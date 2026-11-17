<?php

namespace App\Domain\Publishing;

use App\Domain\Ingestion\SourceTypeService;
use App\Domain\Support\Json;
use Illuminate\Support\Facades\DB;

/** Read-only import eligibility; callers separately enforce delivery settings and access. */
final class OnDemandEligibility
{
    /** @param array<int> $siteIds @param array<int> $pageIds @return array<int> */
    public function pageIds(array $siteIds, array $pageIds): array
    {
        if ($siteIds === [] || $pageIds === []) {
            return [];
        }
        $pages = DB::table('pages as p')->join('sites as site', 'site.id', '=', 'p.site_id')
            ->join('content_items as content', 'content.id', '=', 'p.content_item_id')
            ->join('content_revisions as revision', function ($join): void {
                $join->on('revision.id', '=', 'p.working_content_revision_id')->on('revision.content_item_id', '=', 'content.id')
                    ->on('revision.id', '=', 'content.current_revision_id');
            })->join('source_connectors as source', 'source.id', '=', 'content.source_connector_id')
            ->join('source_site_bindings as binding', function ($join): void {
                $join->on('binding.connector_id', '=', 'source.id')->on('binding.site_id', '=', 'p.site_id');
            })->whereIn('p.site_id', $siteIds)->whereIn('p.id', $pageIds)
            ->where(['site.status' => 'active', 'site.content_type' => 'movie', 'content.kind' => 'movie', 'content.library_status' => 'active',
                'p.status' => 'draft', 'p.page_type' => 'movie', 'p.page_kind' => 'regular', 'p.publish_locked' => false,
                'source.status' => 'active', 'binding.status' => 'active'])
            ->whereNull('p.current_publication_id')->whereNull('source.deleted_at')->whereNull('binding.deleted_at')
            ->whereRaw("binding.auto_publish_policy->>'enabled' = 'true'")
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('page_publications as history')->whereColumn('history.page_id', 'p.id'))
            ->get(['p.id', 'binding.id as binding_id', 'binding.mapping_version', 'source.row_version as source_version',
                DB::raw("jsonb_build_object('category_external_id',revision.payload_json->'category_external_id',
                    '_raw',jsonb_build_object('type_id',revision.payload_json->'_raw'->'type_id')) AS type_json")]);
        $policies = [];
        foreach (DB::table('source_site_bindings as binding')->join('source_connectors as source', 'source.id', '=', 'binding.connector_id')
            ->whereIn('binding.id', $pages->pluck('binding_id'))->whereNull('binding.deleted_at')
            ->get(['binding.id', 'binding.mapping_version', 'binding.mapping_json', 'source.row_version', 'source.config_json']) as $binding) {
            try {
                $policy = SourceTypeService::policy($binding);
                SourceTypeService::assertCurrentPolicy($policy, $binding);
                $policies[$binding->id] = ['policy' => $policy, 'mapping_version' => $binding->mapping_version, 'source_version' => $binding->row_version];
            } catch (\RuntimeException) {
                continue;
            }
        }
        $eligible = [];
        foreach ($pages as $page) {
            $entry = $policies[$page->binding_id] ?? null;
            if ($entry && $entry['mapping_version'] === $page->mapping_version && $entry['source_version'] === $page->source_version
                && SourceTypeService::accepts($entry['policy'], SourceTypeService::recordType(Json::decode($page->type_json)))) {
                $eligible[] = $page->id;
            }
        }

        return $eligible;
    }
}
