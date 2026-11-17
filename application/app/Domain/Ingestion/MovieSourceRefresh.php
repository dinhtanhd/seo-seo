<?php

namespace App\Domain\Ingestion;

use App\Domain\Ingestion\Connectors\MacCmsConnector;
use App\Domain\Ingestion\Http\HttpTransport;
use App\Domain\Ingestion\Http\SafeHttpClient;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use Closure;
use Illuminate\Support\Facades\DB;

final class MovieSourceRefresh
{
    public function __construct(private readonly ?HttpTransport $http = null) {}

    /** Network work stays outside transactions; source and content CAS guard the commit. */
    public function refresh(int $id, int $expectedRowVersion, int $actorId, ?Closure $onSaved = null): object
    {
        $library = app(MovieLibraryService::class);
        $detail = $library->find($id, $actorId);
        $item = $detail['item'];
        if ((int) $item->row_version !== $expectedRowVersion) {
            throw new Conflict('MOVIE_VERSION_CONFLICT');
        }
        if ($item->library_status !== 'active' || $item->manual_update_locked) {
            throw new Conflict($item->manual_update_locked ? 'MOVIE_UPDATE_LOCKED' : 'MOVIE_ARCHIVED');
        }
        $source = DB::table('source_connectors')->find($item->source_connector_id);
        if (! $source || $source->status !== 'active' || $source->deleted_at !== null || ! in_array($source->kind, ['maccms', 'shandian'], true)) {
            throw new Conflict('MOVIE_REFRESH_SOURCE_UNAVAILABLE');
        }
        $configuration = Json::decode($source->config_json);
        $transport = $this->http ?? app(SafeHttpClient::class);
        $batch = (new MacCmsConnector($transport))->fetchByIds($configuration, [(string) $item->external_id]);
        $record = collect($batch->records)->first(fn ($record): bool => (string) ($record['external_id'] ?? '') === (string) $item->external_id);
        if (! $record || isset($record['_parse_error'])) {
            throw new Conflict($record ? 'MOVIE_REFRESH_INVALID_RECORD' : 'MOVIE_REFRESH_NOT_FOUND');
        }

        return DB::transaction(function () use ($library, $item, $source, $record, $expectedRowVersion, $actorId, $onSaved): object {
            $current = DB::table('source_connectors')->where('id', $source->id)->lockForUpdate()->first();
            if (! $current || $current->status !== 'active' || $current->deleted_at !== null
                || (int) $current->row_version !== (int) $source->row_version || Json::hash(Json::decode($current->config_json)) !== Json::hash(Json::decode($source->config_json))) {
                throw new Conflict('MOVIE_REFRESH_SOURCE_CHANGED');
            }

            $saved = $library->saveFromSource((int) $item->id, $record, $expectedRowVersion, $actorId);
            $onSaved?->__invoke($saved);

            return $saved;
        }, 3);
    }
}
