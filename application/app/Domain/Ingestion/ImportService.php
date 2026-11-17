<?php

namespace App\Domain\Ingestion;

use App\Domain\Sites\SitePresentationService;
use App\Domain\Support\CatalogAvailability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ImportService
{
    public function __construct(private readonly CanonicalRecord $normalizer = new CanonicalRecord) {}

    public function dryRun(array $records): array
    {
        $report = ['received' => count($records), 'valid' => 0, 'errors' => []];
        foreach ($records as $index => $record) {
            try {
                if (! is_array($record) || isset($record['_parse_error'])) {
                    throw new \InvalidArgumentException($record['_parse_error'] ?? 'Record không hợp lệ.');
                }
                $this->normalizer->normalize($record);
                $report['valid']++;
            } catch (\Throwable $e) {
                $report['errors'][] = ['row' => $index + 1, 'error' => $e->getMessage()];
            }
        }

        return $report;
    }

    public function queueImportRecords(int $connectorId, array $records, string $idempotencyKey): array
    {
        if (count($records) > 10000 || $idempotencyKey === '' || strlen($idempotencyKey) > 255) {
            throw new \InvalidArgumentException('Import vượt hạn mức hoặc thiếu idempotency key.');
        }
        $encoded = CanonicalRecord::stableJson(['records' => $records, 'cursor' => null]);
        if (strlen($encoded) > 10 * 1024 * 1024) {
            throw new \InvalidArgumentException('Payload import vượt 10 MB.');
        }
        $hash = hash('sha256', $encoded);

        return DB::transaction(function () use ($connectorId, $records, $idempotencyKey, $hash) {
            $source = DB::table('source_connectors')->where('id', $connectorId)->lockForUpdate()->first();
            if (! $source || $source->status !== 'active') {
                throw new \RuntimeException('Nguồn không tồn tại hoặc đã tạm dừng.');
            }
            $run = DB::table('ingest_runs')->where('connector_id', $connectorId)->where('idempotency_key', $idempotencyKey)->first();
            if ($run) {
                if ($run->request_hash !== $hash) {
                    throw new \RuntimeException('IDEMPOTENCY_CONFLICT: cùng key nhưng dữ liệu khác.');
                }

                return $this->report($run);
            }
            $publicId = (string) Str::uuid();
            $id = DB::table('ingest_runs')->insertGetId(['public_id' => $publicId, 'connector_id' => $connectorId, 'idempotency_key' => $idempotencyKey,
                'request_hash' => $hash, 'cursor' => '0', 'status' => 'queued', 'counts' => '{}', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('outbox_events')->insert(['id' => $publicId, 'event_type' => 'import.requested', 'aggregate_id' => $id,
                'payload_json' => CanonicalRecord::stableJson(['run_id' => $id, 'records' => $records]), 'created_at' => now()]);

            return $this->report(DB::table('ingest_runs')->where('id', $id)->first());
        }, 3);
    }

    public function resumeImport(int $runId, int $maxChunks = 4): array
    {
        $initial = DB::table('ingest_runs')->where('id', $runId)->first();
        if (! $initial) {
            throw new \InvalidArgumentException('Không tìm thấy đợt import.');
        }
        if (in_array($initial->status, ['succeeded', 'partial'], true)) {
            return $this->report($initial);
        }
        $event = DB::table('outbox_events')->where('event_type', 'import.requested')->where('aggregate_id', $runId)->whereNotNull('payload_json->records')->first();
        if (! $event) {
            throw new \InvalidArgumentException('Không tìm thấy payload import bền vững.');
        }
        $records = json_decode($event->payload_json, true, 24, JSON_THROW_ON_ERROR)['records'] ?? null;
        if (! is_array($records) || hash('sha256', CanonicalRecord::stableJson(['records' => $records, 'cursor' => null])) !== $initial->request_hash) {
            throw new \RuntimeException('Import payload không khớp request hash.');
        }
        $token = (string) Str::uuid();
        DB::transaction(function () use ($initial, $runId, $token) {
            $source = DB::table('source_connectors')->where('id', $initial->connector_id)->lockForUpdate()->first();
            $run = DB::table('ingest_runs')->where('id', $runId)->lockForUpdate()->first();
            if (! $source || $source->status !== 'active') {
                throw new \RuntimeException('Nguồn đang tạm dừng.');
            }
            if ($run->lease_until && now()->lt($run->lease_until)) {
                throw new \RuntimeException('IMPORT_BUSY: worker khác đang xử lý.');
            }
            DB::table('ingest_runs')->where('id', $runId)->update(['status' => 'running', 'lease_token' => $token, 'lease_until' => now()->addMinutes(5), 'updated_at' => now()]);
        }, 3);
        try {
            for ($chunk = 0; $chunk < min(20, max(1, $maxChunks)); $chunk++) {
                $done = DB::transaction(function () use ($initial, $runId, $token, $records) {
                    $source = DB::table('source_connectors')->where('id', $initial->connector_id)->lockForUpdate()->first();
                    $run = DB::table('ingest_runs')->where('id', $runId)->lockForUpdate()->first();
                    if ($source->status !== 'active' || $run->lease_token !== $token || now()->gte($run->lease_until)) {
                        throw new \RuntimeException('IMPORT_LEASE_LOST: không commit chunk của worker cũ.');
                    }
                    $offset = (int) $run->cursor;
                    $slice = array_slice($records, $offset, 250);
                    $counts = $this->commitRecords($source->id, $runId, $slice, $offset);
                    $previous = json_decode($run->counts, true) ?? [];
                    foreach ($counts as $key => $value) {
                        $previous[$key] = ($previous[$key] ?? 0) + $value;
                    }
                    $cursor = $offset + count($slice);
                    $done = $cursor >= count($records);
                    DB::table('ingest_runs')->where('id', $runId)->update(['cursor' => (string) $cursor, 'counts' => json_encode($previous),
                        'status' => $done ? (($previous['errors'] ?? 0) > 0 ? 'partial' : 'succeeded') : 'running',
                        'lease_token' => $done ? null : $token, 'lease_until' => $done ? null : now()->addMinutes(5), 'error' => null, 'updated_at' => now()]);

                    return $done;
                }, 3);
                if ($done) {
                    break;
                }
            }
            if (! $done) {
                DB::transaction(function () use ($runId, $token) {
                    $run = DB::table('ingest_runs')->where('id', $runId)->lockForUpdate()->first();
                    if ($run->lease_token !== $token) {
                        throw new \RuntimeException('IMPORT_LEASE_LOST');
                    }
                    DB::table('ingest_runs')->where('id', $runId)->update(['status' => 'queued', 'lease_token' => null, 'lease_until' => null, 'updated_at' => now()]);
                    DB::table('outbox_events')->insert(['id' => (string) Str::uuid(), 'event_type' => 'import.requested', 'aggregate_id' => $runId,
                        'payload_json' => json_encode(['run_id' => $runId, 'resume_only' => true]), 'created_at' => now()]);
                }, 3);
            }
        } catch (\Throwable $e) {
            DB::table('ingest_runs')->where('id', $runId)->where('lease_token', $token)->update(['status' => 'failed', 'lease_token' => null, 'lease_until' => null,
                'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);
            throw $e;
        }

        return $this->report(DB::table('ingest_runs')->where('id', $runId)->first());
    }

    public function importRecords(int $connectorId, array $records, string $idempotencyKey, ?string $cursor = null): array
    {
        if (count($records) > 10000 || strlen($idempotencyKey) > 255 || $idempotencyKey === '') {
            throw new \InvalidArgumentException('Import vượt hạn mức hoặc thiếu idempotency key.');
        }
        $requestHash = hash('sha256', CanonicalRecord::stableJson(['records' => $records, 'cursor' => $cursor]));

        return DB::transaction(function () use ($connectorId, $records, $idempotencyKey, $cursor, $requestHash) {
            $connector = DB::table('source_connectors')->where('id', $connectorId)->lockForUpdate()->first();
            if (! $connector || $connector->status !== 'active') {
                throw new \RuntimeException('Nguồn không tồn tại hoặc đã tạm dừng.');
            }
            $existing = DB::table('ingest_runs')->where('connector_id', $connectorId)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if ($existing->request_hash !== $requestHash) {
                    throw new \RuntimeException('IDEMPOTENCY_CONFLICT: cùng key nhưng dữ liệu khác.');
                }

                return $this->report($existing);
            }
            $runId = DB::table('ingest_runs')->insertGetId(['public_id' => (string) Str::uuid(), 'connector_id' => $connectorId, 'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash, 'cursor' => $cursor, 'status' => 'running', 'counts' => '{}', 'created_at' => now(), 'updated_at' => now()]);
            $counts = $this->commitRecords($connectorId, $runId, $records);
            $status = $counts['errors'] > 0 ? ($counts['parsed'] > 0 ? 'partial' : 'failed') : 'succeeded';
            DB::table('ingest_runs')->where('id', $runId)->update(['counts' => json_encode($counts), 'status' => $status, 'updated_at' => now()]);

            return ['run_id' => $runId, 'status' => $status, 'counts' => $counts, 'cursor' => $cursor, 'error' => null];
        }, 3);
    }

    /** Caller holds connector/run locks; data and crawl checkpoint are committed together. */
    public function commitRecords(int $connectorId, int $runId, array $records, int $offset = 0): array
    {
        $counts = ['received' => count($records), 'parsed' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => 0, 'pages_created' => 0,
            'skipped' => 0, 'filtered' => 0, 'skipped_binding' => 0, 'unknown_type' => 0, 'categories_updated' => 0,
            'archived_skipped' => 0, 'manual_locked_skipped' => 0];
        $bindings = DB::table('source_site_bindings')->where('connector_id', $connectorId)->where('status', 'active')->whereNull('deleted_at')
            ->whereIn('site_id', CatalogAvailability::filter(DB::table('sites'), 'sites')->select('id'))->orderBy('site_id')->get();
        $source = DB::table('source_connectors')->where('id', $connectorId)->first() ?? throw new \RuntimeException('Nguồn không tồn tại.');
        $policies = [];
        foreach ($bindings as $binding) {
            $policies[$binding->id] = SourceTypeService::policy($binding);
            SourceTypeService::assertCurrentPolicy($policies[$binding->id], $source);
        }
        // Source -> all usage sites -> content -> pages also fences HTML warmers.
        $websiteSync = app(MovieWebsiteSync::class);
        $servingEnabled = $websiteSync->available();
        $externalIds = [];
        foreach ($records as $raw) {
            if (is_array($raw) && is_scalar($raw['external_id'] ?? null)) {
                $externalIds[] = \Normalizer::normalize(trim(preg_replace('/\s+/u', ' ', (string) $raw['external_id'])), \Normalizer::FORM_C);
            }
        }
        $itemIds = $externalIds === [] ? [] : DB::table('content_items')->where('source_connector_id', $connectorId)
            ->whereIn('external_id', array_filter($externalIds, 'is_string'))->pluck('id')->all();
        $websiteSync->lockSourceSites($connectorId, $bindings->pluck('site_id')->all(), $itemIds);
        $siteStatuses = DB::table('sites')->whereIn('id', $bindings->pluck('site_id'))->pluck('status', 'id');
        $selectedPolicy = collect($policies)->contains(fn ($policy) => $policy['mode'] === 'selected');
        $knownTypes = [];
        if ($selectedPolicy) {
            $catalog = DB::table('source_type_catalogs')->where('connector_id', $connectorId)->value('types_json');
            $knownTypes = array_fill_keys(array_column(json_decode($catalog ?? '[]', true) ?? [], 'id'), true);
        }
        $affected = [];
        $dirtySites = [];
        foreach ($records as $index => $raw) {
            $typeId = is_array($raw) ? SourceTypeService::recordType($raw) : null;
            $acceptedBindings = [];
            foreach ($bindings as $binding) {
                if (SourceTypeService::accepts($policies[$binding->id], $typeId)) {
                    $acceptedBindings[] = $binding;
                } else {
                    $counts['skipped_binding']++;
                }
            }
            if ($selectedPolicy && ($typeId === null || ! isset($knownTypes[$typeId]))) {
                $counts['unknown_type']++;
            }
            if ($acceptedBindings === []) {
                $counts['skipped']++;
                $counts['filtered']++;

                continue;
            }
            try {
                if (! is_array($raw) || isset($raw['_parse_error'])) {
                    throw new \InvalidArgumentException($raw['_parse_error'] ?? 'Record không hợp lệ.');
                }
                $record = $this->normalizer->normalize($raw);
            } catch (\Throwable $e) {
                DB::table('ingestion_quarantine')->insert(['run_id' => $runId, 'record_index' => $offset + $index + 1,
                    'external_id' => is_array($raw) && is_scalar($raw['external_id'] ?? null) ? (string) $raw['external_id'] : null,
                    'payload_json' => json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), 'error' => mb_substr($e->getMessage(), 0, 2000), 'created_at' => now()]);
                $counts['errors']++;

                continue;
            }
            $counts['parsed']++;
            $item = DB::table('content_items')->where('source_connector_id', $connectorId)->where('external_id', $record['external_id'])->lockForUpdate()->first();
            if ($item && ($item->library_status === 'archived' || $item->manual_update_locked)) {
                $counts['skipped']++;
                $counts[$item->library_status === 'archived' ? 'archived_skipped' : 'manual_locked_skipped']++;

                continue;
            }
            if ($item && $item->kind !== $record['kind']) {
                DB::table('ingestion_quarantine')->insert(['run_id' => $runId, 'record_index' => $offset + $index + 1,
                    'external_id' => $record['external_id'], 'payload_json' => CanonicalRecord::stableJson($record), 'error' => 'External ID đã có loại nội dung khác.', 'created_at' => now()]);
                $counts['parsed']--;
                $counts['errors']++;

                continue;
            }
            $encoded = CanonicalRecord::stableJson($record);
            // Transport/provenance URL can contain pagination; it must not create a content revision by itself.
            $hashPayload = $record;
            unset($hashPayload['source_url']);
            $hash = hash('sha256', CanonicalRecord::stableJson($hashPayload));
            $previous = $item && $item->current_revision_id ? DB::table('content_revisions')->where('id', $item->current_revision_id)->first() : null;
            if (! $item) {
                $itemId = DB::table('content_items')->insertGetId(['kind' => $record['kind'], 'source_connector_id' => $connectorId,
                    'external_id' => $record['external_id'], 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                $counts['created']++;
            } else {
                $itemId = $item->id;
                $counts[$previous && $previous->raw_hash === $hash ? 'unchanged' : 'updated']++;
                DB::table('content_items')->where('id', $itemId)->update(['last_seen_at' => now(), 'updated_at' => now()]);
            }
            if ($previous && $previous->raw_hash === $hash) {
                $revisionId = $previous->id;
            } else {
                $revisionId = DB::table('content_revisions')->insertGetId(['content_item_id' => $itemId, 'revision_no' => ($previous->revision_no ?? 0) + 1,
                    'raw_hash' => $hash, 'payload_json' => $encoded, 'source_url' => $record['source_url'], 'fetched_at' => now(), 'created_at' => now()]);
                $changes = ['current_revision_id' => $revisionId, 'updated_at' => now()];
                if ($item) {
                    $changes['row_version'] = DB::raw('row_version+1');
                }
                DB::table('content_items')->where('id', $itemId)->update($changes);
            }
            foreach ($acceptedBindings as $binding) {
                $mappedCategory = $typeId === null ? null : ($policies[$binding->id]['category_map'][$typeId] ?? null);
                $page = DB::table('pages')->where('site_id', $binding->site_id)->where('content_item_id', $itemId)->where('page_kind', 'regular')->lockForUpdate()->first();
                if (! $page) {
                    $presentation = SitePresentationService::profile(DB::table('sites')->find($binding->site_id));
                    $prefix = $record['kind'] === 'movie' && $presentation['configured'] ? $presentation['routes']['detail'] : '/'.$record['kind'];
                    $key = $record['kind'] === 'movie' ? SitePresentationService::movieKey($connectorId, $record['external_id'], $record, $presentation) : $connectorId.'-'.rawurlencode($record['external_id']);
                    $path = $record['kind'] === 'movie' && $presentation['configured'] ? SitePresentationService::detailPath($presentation, $key) : $prefix.'/'.$key;
                    $pageId = DB::table('pages')->insertGetId(['site_id' => $binding->site_id, 'content_item_id' => $itemId, 'working_content_revision_id' => $revisionId,
                        'category_id' => $mappedCategory,
                        'page_type' => $record['kind'], 'canonical_path' => $path,
                        'row_version' => 1, 'status' => 'draft', 'input_snapshot_json' => '{}', 'input_hash' => '', 'created_at' => now(), 'updated_at' => now()]);
                    $counts['pages_created']++;
                    $affected[$binding->site_id][] = $pageId;
                    $dirtySites[] = (int) $binding->site_id;
                } else {
                    // Live publication pointer and manual input overrides are deliberately preserved.
                    $snapshot = json_decode($page->input_snapshot_json ?? '{}', true) ?? [];
                    if (! ($snapshot['source_update_locked'] ?? false)) {
                        $changes = [];
                        if ((int) $page->working_content_revision_id !== (int) $revisionId) {
                            $changes['working_content_revision_id'] = $revisionId;
                        }
                        if ($servingEnabled && $record['kind'] === 'movie' && ($siteStatuses[$binding->site_id] ?? null) === 'active'
                            && in_array($page->status, ['draft', 'live'], true) && (! $previous || $previous->raw_hash !== $hash)
                            && (int) $page->serving_content_revision_id !== (int) $revisionId) {
                            $changes['serving_content_revision_id'] = $revisionId;
                        }
                        // Unmapped/null never erases a manual category; a positive mapping assigns it.
                        if ($mappedCategory !== null && (int) $page->category_id !== $mappedCategory) {
                            $changes['category_id'] = $mappedCategory;
                            $counts['categories_updated']++;
                        }
                        if ($changes !== []) {
                            DB::table('pages')->where('id', $page->id)->update($changes + ['row_version' => $page->row_version + 1, 'updated_at' => now()]);
                            $dirtySites[] = (int) $binding->site_id;
                            if ($page->current_publication_id === null) {
                                $affected[$binding->site_id][] = $page->id;
                            }
                        }
                    }
                }
            }
            if ($item && $record['kind'] === 'movie' && (! $previous || $previous->raw_hash !== $hash)) {
                $dirtySites = array_merge($dirtySites, $websiteSync->sync((int) $itemId, (int) $revisionId));
            }
        }
        $websiteSync->invalidate(array_merge($dirtySites, array_map('intval', array_keys($affected))));
        foreach ($bindings as $binding) {
            $policy = json_decode($binding->auto_publish_policy ?? '{}', true) ?? [];
            if (($policy['enabled'] ?? false) && ! empty($policy['actor_id']) && ! empty($affected[$binding->site_id])) {
                DB::table('outbox_events')->insert(['id' => (string) Str::uuid(), 'event_type' => 'ingestion.committed', 'aggregate_id' => $runId,
                    'payload_json' => json_encode(['run_id' => $runId, 'site_id' => $binding->site_id, 'actor_id' => (int) $policy['actor_id'],
                        'binding_id' => (int) $binding->id, 'binding_generation' => (int) $binding->link_generation,
                        'page_ids' => array_values(array_unique($affected[$binding->site_id]))]), 'created_at' => now()]);
            }
        }

        return $counts;
    }

    public function report(object $run): array
    {
        return ['run_id' => $run->id, 'status' => $run->status, 'counts' => json_decode($run->counts ?? '{}', true) ?? [], 'cursor' => $run->cursor, 'error' => $run->error ?? null]
            + (($run->crawl_mode ?? null) === null ? [] : ['crawl_mode' => $run->crawl_mode, 'page_limit' => isset($run->page_limit) ? (int) $run->page_limit : null]);
    }
}
