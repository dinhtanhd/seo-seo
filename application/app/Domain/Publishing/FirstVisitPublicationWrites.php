<?php

namespace App\Domain\Publishing;

use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Batched writes for first-visit publications, inside Publisher's transactions. */
final class FirstVisitPublicationWrites
{
    public function claim(object $page, object $item): int
    {
        $now = now();
        $lease = DB::selectOne(<<<'SQL'
WITH leased AS (
    INSERT INTO page_publish_leases (page_id, owner_item_id, fencing_token, expires_at, heartbeat_at)
    VALUES (?, ?, 1, ?, ?)
    ON CONFLICT (page_id) DO UPDATE SET owner_item_id = EXCLUDED.owner_item_id,
        fencing_token = page_publish_leases.fencing_token + 1,
        expires_at = EXCLUDED.expires_at, heartbeat_at = EXCLUDED.heartbeat_at
    WHERE page_publish_leases.owner_item_id IS NULL
    RETURNING owner_item_id, fencing_token
), rendering AS (
    UPDATE publish_batch_items i SET status = 'rendering', attempts = i.attempts + 1
    FROM leased WHERE i.id = leased.owner_item_id RETURNING i.batch_id
), started AS (
    UPDATE publish_batches b SET status = 'running' FROM rendering WHERE b.id = rendering.batch_id
)
SELECT fencing_token FROM leased
SQL, [$page->id, $item->id, $now->copy()->addMinutes(3), $now], false);
        if ($lease === null) {
            throw new Conflict('LEASE_BUSY', 'Chạy reconcile nếu worker đã mất');
        }
        DB::connection()->recordsHaveBeenModified();

        return (int) $lease->fencing_token;
    }

    public function insertPublication(object $page, object $item, ?int $revisionId, array $proposal): object
    {
        $publication = DB::selectOne(<<<'SQL'
INSERT INTO page_publications (page_id, site_id, batch_item_id, publication_no, content_item_id, content_revision_id,
                               snapshot_json, html_body, html_hash, previous_publication_id)
SELECT ?, ?, ?, COALESCE(max(publication_no), 0) + 1, ?, ?, ?::jsonb, ?, ?, ?
FROM page_publications WHERE page_id = ?
RETURNING *
SQL, [$page->id, $page->site_id, $item->id, $page->content_item_id, $revisionId, Json::encode($proposal['snapshot']),
            $proposal['html'], $proposal['html_hash'], $page->current_publication_id, $page->id], false);
        DB::connection()->recordsHaveBeenModified();

        return $publication;
    }

    public function finish(object $page, object $item, object $batch, int $fence, object $publication, array $proposal, string $fingerprint): void
    {
        // A completed item still has an immutable publication containing these bytes.
        unset($proposal['html']);
        $proposal['html_publication_id'] = $publication->id;
        DB::statement(<<<'SQL'
WITH input AS (
    SELECT ?::bigint AS item_id, ?::bigint AS batch_id, ?::bigint AS page_id, ?::bigint AS site_id,
        ?::bigint AS actor_id, ?::bigint AS fence, ?::bigint AS publication_id, ?::text AS fingerprint,
        ?::jsonb AS proposal, ?::uuid AS event_id, ?::uuid AS correlation_id, ?::timestamptz AS updated_at
), released AS (
    UPDATE page_publish_leases l SET owner_item_id = NULL, expires_at = NULL
    FROM input i WHERE l.page_id = i.page_id AND l.owner_item_id = i.item_id AND l.fencing_token = i.fence
), consumed AS (
    UPDATE keyword_capacity_claims c SET status = 'consumed'
    FROM input i WHERE c.item_id = i.item_id AND c.fencing_token = i.fence AND c.status = 'held'
), finished AS (
    UPDATE publish_batch_items b SET status = 'published', error_code = NULL, preview_json = i.proposal, fingerprint = i.fingerprint
    FROM input i WHERE b.id = i.item_id
), event AS (
    INSERT INTO outbox_events (id, event_type, aggregate_id, payload_json)
    SELECT event_id, 'publication.activated', publication_id,
        jsonb_build_object('publication_id', publication_id, 'site_id', site_id) FROM input
), audit AS (
    INSERT INTO audit_events (actor_id, action, site_id, page_id, batch_id, after_json, correlation_id)
    SELECT actor_id, 'page.published', site_id, page_id, batch_id,
        jsonb_build_object('publication_id', publication_id), correlation_id FROM input
), status_counts AS (
    -- Sibling writes share a snapshot. Count the status being applied to this item.
    SELECT CASE WHEN b.id = i.item_id THEN 'published' ELSE b.status END AS status,
        count(*) AS items, count(*) FILTER (WHERE b.accepted_for_publish) AS accepted
    FROM publish_batch_items b JOIN input i ON b.batch_id = i.batch_id GROUP BY 1
), totals AS (
    SELECT jsonb_object_agg(status, items) AS counts, sum(accepted) AS accepted,
        COALESCE(sum(accepted) FILTER (WHERE status = 'published'), 0) AS successful,
        COALESCE(sum(accepted) FILTER (WHERE status IN ('pending', 'claimed', 'rendering', 'activating')), 0) AS pending
    FROM status_counts
)
UPDATE publish_batches b SET counts = totals.counts, updated_at = i.updated_at,
    status = CASE WHEN b.accepted_at IS NULL OR totals.pending > 0 THEN b.status
                  WHEN b.cancel_requested_at IS NOT NULL THEN 'cancelled'
                  WHEN totals.successful = totals.accepted THEN 'completed'
                  WHEN totals.successful > 0 THEN 'partial' ELSE 'failed' END
FROM input i CROSS JOIN totals WHERE b.id = i.batch_id
SQL, [$item->id, $batch->id, $page->id, $page->site_id, $batch->requested_by, $fence, $publication->id,
            $fingerprint, Json::encode($proposal), (string) Str::uuid(), (string) Str::uuid(), now()]);
    }
}
