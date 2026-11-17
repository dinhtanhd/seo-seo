<?php

namespace App\Console\Commands\Ingestion;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class SourceRetentionCommand extends Command
{
    protected $signature = 'sources:retention {--days=30} {--apply : Xóa payload raw cũ, giữ report và provenance}';

    protected $description = 'Áp retention raw của import/quarantine; không xóa nội dung, revision hoặc publication.';

    public function handle(): int
    {
        $cutoff = now()->subDays(max(7, (int) $this->option('days')));
        $quarantine = DB::table('ingestion_quarantine')->where('created_at', '<', $cutoff)->whereRaw("payload_json <> '{}'::jsonb");
        $imports = DB::table('outbox_events')->where('event_type', 'import.requested')->where('created_at', '<', $cutoff)->whereNotNull('delivered_at')
            ->whereNotNull('payload_json->records')->whereIn('aggregate_id', DB::table('ingest_runs')->whereIn('status', ['succeeded', 'partial'])->select('id'));
        $this->info('Payload quarantine: '.$quarantine->count().'; file import kết thúc: '.$imports->count().'.');
        if (! $this->option('apply')) {
            $this->info('Dry-run. Thêm --apply để áp chính sách retention.');

            return self::SUCCESS;
        }
        DB::transaction(function () use ($quarantine, $imports) {
            $quarantine->update(['payload_json' => '{}']);
            foreach ($imports->get() as $event) {
                DB::table('outbox_events')->where('id', $event->id)->update(['payload_json' => json_encode(['run_id' => $event->aggregate_id, 'raw_retained' => false])]);
            }
        });
        $this->info('Đã bỏ raw payload; reports/errors/content revisions/publications được giữ.');

        return self::SUCCESS;
    }
}
