<?php

namespace App\Domain\Publishing;

interface PageAdapter
{
    public function readRevision(int $pageId, int $actorId): array;

    public function preview(int $siteId, array $pageIds, int $actorId, array $options = []): object;

    public function apply(int $batchId, int $actorId): object;

    public function rollback(int $publicationId, int $expectedHeadId, int $actorId): object;
}
