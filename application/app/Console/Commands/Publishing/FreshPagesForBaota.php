<?php

namespace App\Console\Commands\Publishing;

use App\Domain\Ingestion\SourceTypeService;
use App\Domain\Publishing\CatalogRefresh;
use App\Domain\Publishing\OnDemandEligibility;
use App\Domain\Publishing\PublicationCampaignService;
use App\Domain\Publishing\QuickApply;
use App\Domain\Publishing\SiteDeliverySettings;
use App\Domain\Support\Json;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Explicit, one-time reset of a newly restored BaoTa database, never a regular deployment step. */
final class FreshPagesForBaota extends Command
{
    protected $signature = 'tram:baota-fresh-pages {--apply : Reset only with an installer authorization file} {--guard-file=} {--actor=} {--rebuild : Publish aggregates and prepare regular-page campaigns}';

    protected $description = 'Lập kế hoạch hoặc dựng lại trang trên database VPS mới được cho phép; giữ kho phim, keyword và cấu hình website.';

    private const RECEIPT = 'deployment.baota_fresh_pages_reset';

    private const TABLES = [
        'pages', 'rules', 'rule_versions', 'publish_batches', 'publish_batch_items',
        'allocation_batch_reservations', 'allocation_state', 'page_publish_leases',
        'keyword_capacity_claims', 'keyword_usage_buckets', 'page_publications', 'page_keyword_assignments',
        'audit_events', 'baidu_submissions', 'publication_campaigns', 'publication_campaign_sites',
        'publication_campaign_chunks', 'publication_campaign_pages', 'publication_category_index',
        'catalog_publication_metadata', 'fanmulu_url_registry', 'page_template_variable_values',
        'template_variable_decks', 'snapshot_fragments', 'page_redirects', 'search_observations',
        'fanmulu_recommendation_states', 'fanmulu_recommendation_windows', 'page_bulk_operations',
        'outbox_events', 'processed_events', 'jobs', 'job_batches', 'failed_jobs',
    ];

    private const PAGE_EVENTS = [
        'publish.requested', 'publication.activated', 'public-cache.refresh', 'ingestion.committed',
        'campaign.chunk.requested', 'quick-apply.requested', 'catalog-refresh.requested', 'site-presentation.requested',
        'catalog-backfill.requested',
    ];

    public function handle(): int
    {
        try {
            if (DB::getDriverName() !== 'pgsql') {
                throw new RuntimeException('Chỉ hỗ trợ PostgreSQL.');
            }
            $this->line(Json::encode(['database' => DB::getDatabaseName(),
                'regular_blueprints' => DB::table('pages')->where('page_kind', 'regular')->count(),
                'fanmulu_pages_to_remove' => DB::table('pages')->where('page_kind', 'fanmulu')->count(),
                'publications_to_remove' => DB::table('page_publications')->count(),
                'keyword_assignments_to_remove' => DB::table('page_keyword_assignments')->count()]));
            if (! $this->option('apply')) {
                $this->info('Chỉ xem kế hoạch. Không sửa database, cache hoặc dịch vụ.');

                return self::SUCCESS;
            }
            $guard = $this->guard();
            $actor = (int) ($this->option('actor') ?: DB::table('users')->where('is_global_admin', true)->orderBy('id')->value('id'));
            if (! $actor || ! DB::table('users')->where(['id' => $actor, 'is_global_admin' => true])->exists()) {
                throw new RuntimeException('Cần tài khoản quản trị toàn hệ thống hiện có.');
            }
            $reset = $this->reset($guard, $actor);
            $this->info($reset ? 'Đã tạo lại nháp thường; publication, assignment và FML cũ đã gỡ trên đích được cho phép.' : 'Đã có biên nhận reset; giữ nguyên các trang mới của lượt này.');
            if ($this->option('rebuild')) {
                return $this->rebuild($guard['token'], $actor);
            }
            $this->warn('Chưa dựng HTML mới. Chạy lại cùng guard với --rebuild trước khi phục vụ website.');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function guard(): array
    {
        $path = (string) $this->option('guard-file');
        $testing = app()->environment('testing') && DB::getDatabaseName() === 'tram_seo_test';
        if (! is_file($path) || is_link($path) || (! $testing && (PHP_OS_FAMILY !== 'Linux'
            || realpath($path) !== '/etc/tram-seo/fresh-pages-authorization.json' || fileowner($path) !== 0
            || (fileperms($path) & 0022) !== 0))) {
            throw new RuntimeException('Chỉ bộ cài VPS mới được cấp guard-file; không dùng lệnh này trực tiếp trên database đang phục vụ.');
        }
        $guard = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        if (($guard['schema_version'] ?? null) !== 1 || ($guard['mode'] ?? null) !== 'fresh-pages'
            || ($guard['database'] ?? null) !== DB::getDatabaseName()
            || ($guard['project_root'] ?? null) !== dirname(base_path())
            || ! preg_match('/^[a-f0-9]{64}$/D', $guard['dump_sha256'] ?? '')
            || ! preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $guard['token'] ?? '')) {
            throw new RuntimeException('Guard không khớp database/root/dump của lượt chuyển dữ liệu.');
        }

        return $guard;
    }

    private function reset(array $guard, int $actor): bool
    {
        return DB::transaction(function () use ($guard, $actor): bool {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['baota:fresh-pages:'.$guard['token']]);
            if (DB::table('audit_events')->where('action', self::RECEIPT)->where('correlation_id', $guard['token'])->exists()) {
                return false;
            }
            $this->assertNoUnrelatedPendingOperations();
            $tables = array_values(array_filter(self::TABLES, fn (string $table): bool => Schema::hasTable($table)));
            foreach (DB::select("SELECT conrelid::regclass::text child, confrelid::regclass::text parent FROM pg_constraint WHERE contype='f' AND connamespace='public'::regnamespace") as $foreign) {
                if (in_array($foreign->parent, $tables, true) && ! in_array($foreign->child, $tables, true)) {
                    throw new RuntimeException('Schema có tham chiếu mới cần review trước reset: '.$foreign->child.' -> '.$foreign->parent);
                }
            }
            $quoted = implode(',', array_map(fn (string $table): string => '"'.$table.'"', $tables));
            DB::statement('LOCK TABLE '.$quoted.' IN ACCESS EXCLUSIVE MODE');
            DB::statement("CREATE TEMP TABLE baota_keep_pages ON COMMIT DROP AS SELECT * FROM pages WHERE page_kind='regular'");
            DB::statement("UPDATE baota_keep_pages SET current_publication_id=NULL, source_page_id=NULL, source_publication_id=NULL,
                serving_content_revision_id=NULL, assignment_locked=false, row_version=1,
                status=CASE WHEN status='live' THEN 'draft' ELSE status END, created_at=now(), updated_at=now()");
            DB::statement('CREATE TEMP TABLE baota_keep_rules ON COMMIT DROP AS SELECT * FROM rules WHERE page_id IS NULL');
            DB::statement('CREATE TEMP TABLE baota_keep_rule_versions ON COMMIT DROP AS SELECT v.* FROM rule_versions v JOIN baota_keep_rules r ON r.id=v.rule_id');
            DB::statement('CREATE TEMP TABLE baota_keep_audit ON COMMIT DROP AS SELECT * FROM audit_events WHERE page_id IS NULL AND batch_id IS NULL');
            $events = implode(',', array_fill(0, count(self::PAGE_EVENTS), '?'));
            DB::statement('CREATE TEMP TABLE baota_keep_outbox ON COMMIT DROP AS SELECT * FROM outbox_events WHERE event_type NOT IN ('.$events.')', self::PAGE_EVENTS);
            DB::statement('CREATE TEMP TABLE baota_keep_processed ON COMMIT DROP AS SELECT p.* FROM processed_events p JOIN baota_keep_outbox e ON e.id=p.event_id');
            $catalogReady = Schema::hasTable('catalog_metadata_state') ? DB::table('catalog_metadata_state')->where('id', 1)->value('ready') : null;
            DB::statement('TRUNCATE TABLE '.$quoted.' CONTINUE IDENTITY RESTRICT');
            DB::statement('INSERT INTO pages SELECT * FROM baota_keep_pages');
            DB::statement('CREATE TEMP TABLE baota_rule_heads ON COMMIT DROP AS SELECT id,current_version_id FROM baota_keep_rules');
            DB::statement('UPDATE baota_keep_rules SET current_version_id=NULL');
            DB::statement('INSERT INTO rules SELECT * FROM baota_keep_rules');
            DB::statement('INSERT INTO rule_versions SELECT * FROM baota_keep_rule_versions');
            DB::statement('UPDATE rules r SET current_version_id=h.current_version_id FROM baota_rule_heads h WHERE r.id=h.id');
            DB::statement('INSERT INTO audit_events SELECT * FROM baota_keep_audit');
            DB::statement('INSERT INTO outbox_events SELECT * FROM baota_keep_outbox');
            DB::statement('INSERT INTO processed_events SELECT * FROM baota_keep_processed');
            if ($catalogReady !== null) {
                DB::table('catalog_metadata_state')->where('id', 1)->update(['ready' => $catalogReady]);
            }
            DB::table('audit_events')->insert(['actor_id' => $actor, 'action' => self::RECEIPT,
                'correlation_id' => $guard['token'], 'after_json' => Json::encode(['dump_sha256' => $guard['dump_sha256'],
                    'regular_drafts' => DB::table('pages')->where('status', 'draft')->count(), 'reset_tables' => $tables])]);

            return true;
        });
    }

    private function assertNoUnrelatedPendingOperations(): void
    {
        foreach (['website_create_operations', 'website_bulk_edit_operations', 'movie_library_operations',
            'catalog_delete_operations', 'site_delete_operations'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->whereIn('status', ['queued', 'running'])->exists()) {
                throw new RuntimeException('Còn thao tác kho/website đang chờ trong '.$table.'; xử lý trước khi tạo lại trang.');
            }
        }
    }

    private function eligible(int $site): Builder
    {
        return DB::table('pages as p')->where('p.site_id', $site)->where('p.page_kind', 'regular')
            ->where('p.status', 'draft')->whereNull('p.current_publication_id')->where('p.publish_locked', false)
            ->where(function (Builder $query): void {
                $query->whereNull('p.content_item_id')->orWhereExists(function (Builder $source): void {
                    $source->selectRaw('1')->from('content_items as c')->join('source_connectors as s', 's.id', '=', 'c.source_connector_id')
                        ->join('source_site_bindings as b', function ($join): void {
                            $join->on('b.connector_id', '=', 's.id')->on('b.site_id', '=', 'p.site_id');
                        })->whereColumn('c.id', 'p.content_item_id')->where('c.library_status', 'active')
                        ->where('s.status', 'active')->whereNull('s.deleted_at')->where('b.status', 'active')->whereNull('b.deleted_at');
                });
            });
    }

    private function prepareCampaign(int $id, int $actor): void
    {
        $campaigns = app(PublicationCampaignService::class);
        $campaign = DB::table('publication_campaigns')->find($id);
        if (! $campaign->accepted_at) {
            foreach (DB::table('publication_campaign_chunks')->where('campaign_id', $id)->orderBy('chunk_no')->pluck('id') as $chunk) {
                $campaigns->processChunk((int) $chunk, 'prepare');
            }
            $campaign = DB::table('publication_campaigns')->find($id);
            if ($campaign->status !== 'ready') {
                throw new RuntimeException('Campaign '.$id.' chưa sẵn sàng: '.$campaign->status.'. Sửa lỗi rồi chạy lại cùng guard.');
            }
            $campaigns->accept($id, $actor);
        }
        foreach (DB::table('publication_campaign_chunks')->where('campaign_id', $id)->whereNotNull('batch_id')->orderBy('chunk_no')->get(['id', 'site_id', 'batch_id']) as $chunk) {
            $campaigns->processChunk((int) $chunk->id, 'apply');
            app(CatalogRefresh::class)->scheduleForBatch((int) $chunk->site_id, (int) $chunk->batch_id, $actor);
        }
    }

    /** Keep the source's selected types and config fingerprint even for manual/prepublish sites. */
    private function selectedSourcePageIds(array $ids): array
    {
        $accepted = [];
        foreach (DB::table('pages as p')->leftJoin('content_items as c', 'c.id', '=', 'p.content_item_id')
            ->leftJoin('content_revisions as r', 'r.id', '=', 'p.working_content_revision_id')
            ->leftJoin('source_connectors as s', 's.id', '=', 'c.source_connector_id')
            ->leftJoin('source_site_bindings as b', function ($join): void {
                $join->on('b.connector_id', '=', 's.id')->on('b.site_id', '=', 'p.site_id');
            })->whereIn('p.id', $ids)->get(['p.id', 'p.content_item_id', 'b.mapping_json', 's.config_json',
                DB::raw("jsonb_build_object('category_external_id',r.payload_json->'category_external_id',
                    '_raw',jsonb_build_object('type_id',r.payload_json->'_raw'->'type_id')) AS type_json")]) as $page) {
            try {
                $policy = SourceTypeService::policy($page);
                SourceTypeService::assertCurrentPolicy($policy, $page);
                if ($page->content_item_id === null || SourceTypeService::accepts($policy, SourceTypeService::recordType(Json::decode($page->type_json)))) {
                    $accepted[] = (int) $page->id;
                }
            } catch (RuntimeException) {
                // A stale source policy must be reviewed in admin, never silently broadened.
            }
        }

        return $accepted;
    }

    private function rebuild(string $token, int $actor): int
    {
        $queued = $onDemand = $blocked = 0;
        $campaigns = app(PublicationCampaignService::class);
        $prefix = 'baota-fresh:'.$token.':';
        foreach (DB::table('publication_campaigns')->where('idempotency_key', 'like', $prefix.'%')->orderBy('id')->cursor() as $existing) {
            $this->prepareCampaign((int) $existing->id, $actor);
        }
        foreach (DB::table('sites')->where('status', 'active')->whereNull('deleted_at')->orderBy('id')->cursor() as $site) {
            foreach ($this->eligible($site->id)->whereIn('p.page_type', ['home', 'category'])->orderBy('p.id')->pluck('p.id') as $id) {
                $batch = app(QuickApply::class)->apply($site->id, [(int) $id], $actor, 'baota-fresh:'.$token.':aggregate:'.$id);
                if (! DB::table('pages')->where('id', $id)->where('status', 'live')->whereNotNull('current_publication_id')->exists()) {
                    throw new RuntimeException('Trang tổng hợp '.$id.' chưa dựng được; xem batch '.$batch->id.'. Chạy lại cùng guard sau khi sửa cấu hình.');
                }
                $this->info('Đã dựng trang tổng hợp '.$id.' của website '.$site->id.'.');
            }
            $cursor = 0;
            while (true) {
                $ids = $this->eligible($site->id)->whereNotIn('p.page_type', ['home', 'category'])->where('p.id', '>', $cursor)
                    ->whereNotExists(function (Builder $selected) use ($prefix): void {
                        $selected->selectRaw('1')->from('publication_campaign_pages as cp')->join('publication_campaigns as campaign', 'campaign.id', '=', 'cp.campaign_id')
                            ->whereColumn('cp.page_id', 'p.id')->where('campaign.idempotency_key', 'like', $prefix.'%');
                    })
                    ->orderBy('p.id')->limit(100)->pluck('p.id')->map(fn ($id): int => (int) $id)->all();
                if ($ids === []) {
                    break;
                }
                $cursor = max($ids);
                $selected = $this->selectedSourcePageIds($ids);
                $blocked += count($ids) - count($selected);
                $ids = $selected;
                if (app(SiteDeliverySettings::class)->enabled((int) $site->id)) {
                    $deferred = app(OnDemandEligibility::class)->pageIds([(int) $site->id], $ids);
                    $onDemand += count($deferred);
                    $ids = array_values(array_diff($ids, $deferred));
                }
                if ($ids === []) {
                    continue;
                }
                $key = $prefix.substr(hash('sha256', Json::encode($ids)), 0, 32);
                $campaign = $campaigns->create(['page_ids' => $ids, 'idempotency_key' => $key], $actor);
                $this->prepareCampaign((int) $campaign->id, $actor);
                $queued += count($ids);
                $this->info('Đã nhận campaign '.$campaign->id.' cho '.count($ids).' trang; worker sẽ dựng HTML.');
            }
            $protected = DB::table('pages')->where('site_id', $site->id)->where('status', 'draft')->where('publish_locked', true)->count();
            $blocked += DB::table('pages')->where('site_id', $site->id)->where('status', 'draft')->where('publish_locked', false)
                ->whereNotIn('id', $this->eligible($site->id)->select('p.id'))->count();
            if ($protected) {
                $this->warn('Giữ khóa xuất bản của '.$protected.' trang tại website '.$site->id.'.');
            }
        }
        $this->info('Đã chuẩn bị '.$queued.' trang cho worker; '.$onDemand.' trang theo cấu hình mở lần đầu; '.$blocked.' trang bị điều kiện nguồn/kho chặn.');
        if ($blocked) {
            $this->error('Còn trang chưa thể dựng. Giữ nguyên trạng thái nguồn/kho và kiểm tra trong admin.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
