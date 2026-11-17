<?php

namespace App\Domain\Publishing;

use App\Domain\Support\Access;
use Illuminate\Support\Facades\DB;

final class NativePageAdapter implements PageAdapter
{
    public function readRevision(int $pageId, int $actorId): array
    {
        $page = DB::table('pages')->find($pageId);
        if (! $page) {
            abort(404);
        }Access::site($actorId, $page->site_id);

        return ['row_version' => $page->row_version, 'publication_id' => $page->current_publication_id, 'content_revision_id' => $page->working_content_revision_id];
    }

    public function preview(int $siteId, array $pageIds, int $actorId, array $options = []): object
    {
        return app(Planner::class)->preview($siteId, $pageIds, $actorId, 'publish', $options);
    }

    public function apply(int $batchId, int $actorId): object
    {
        return app(Publisher::class)->accept($batchId, $actorId);
    }

    public function rollback(int $publicationId, int $expectedHeadId, int $actorId): object
    {
        return app(Publisher::class)->rollbackPreview($publicationId, $expectedHeadId, $actorId);
    }
}
