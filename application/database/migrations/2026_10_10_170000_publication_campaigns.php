<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE page_bulk_operations (
 id uuid PRIMARY KEY, actor_id bigint NOT NULL REFERENCES users(id), intent text NOT NULL CHECK(intent IN ('edit','publish')),
 selection_json jsonb NOT NULL, selected_json jsonb NOT NULL, fingerprint char(64) NOT NULL, payload_hash char(64),
 changes_json jsonb NOT NULL DEFAULT '{}', result_json jsonb NOT NULL DEFAULT '{}',
 status text NOT NULL CHECK(status IN ('inspected','applied')), expires_at timestamptz NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX page_bulk_operations_actor_created ON page_bulk_operations(actor_id,created_at DESC);
CREATE TABLE publication_campaigns (
 id bigserial PRIMARY KEY, public_id uuid NOT NULL UNIQUE, requested_by bigint NOT NULL REFERENCES users(id),
 action text NOT NULL CHECK(action IN ('publish','reallocate','rollback')), status text NOT NULL DEFAULT 'preparing',
 idempotency_key varchar(128) NOT NULL, request_hash char(64) NOT NULL, selection_fingerprint char(64),
 source_campaign_id bigint REFERENCES publication_campaigns(id), options_json jsonb NOT NULL DEFAULT '{}',
 counts jsonb NOT NULL DEFAULT '{}', accepted_at timestamptz, cancel_requested_at timestamptz,
 cancelled_by bigint REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(requested_by,idempotency_key)
);
CREATE TABLE publication_campaign_sites (
 id bigserial PRIMARY KEY, campaign_id bigint NOT NULL REFERENCES publication_campaigns(id), site_id bigint NOT NULL REFERENCES sites(id),
 total integer NOT NULL, status text NOT NULL DEFAULT 'preparing', counts jsonb NOT NULL DEFAULT '{}',
 UNIQUE(campaign_id,site_id)
);
CREATE TABLE publication_campaign_chunks (
 id bigserial PRIMARY KEY, campaign_id bigint NOT NULL REFERENCES publication_campaigns(id), site_id bigint NOT NULL REFERENCES sites(id),
 chunk_no integer NOT NULL, page_ids_json jsonb NOT NULL, restore_map_json jsonb NOT NULL DEFAULT '{}',
 batch_id bigint UNIQUE REFERENCES publish_batches(id), status text NOT NULL DEFAULT 'prepare_queued',
 attempts integer NOT NULL DEFAULT 0, error_code text, lease_token uuid, lease_expires_at timestamptz, last_dispatched_at timestamptz,
 started_at timestamptz, finished_at timestamptz, created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(campaign_id,site_id,chunk_no), FOREIGN KEY(campaign_id,site_id) REFERENCES publication_campaign_sites(campaign_id,site_id)
);
CREATE INDEX publication_campaign_chunks_pending ON publication_campaign_chunks(status,last_dispatched_at);
CREATE TABLE publication_campaign_pages (
 campaign_id bigint NOT NULL REFERENCES publication_campaigns(id), site_id bigint NOT NULL REFERENCES sites(id), page_id bigint NOT NULL REFERENCES pages(id),
 chunk_id bigint REFERENCES publication_campaign_chunks(id), expected_row_version bigint NOT NULL, expected_head_id bigint REFERENCES page_publications(id),
 expected_row_hash char(64) NOT NULL, path text NOT NULL, item_id bigint UNIQUE REFERENCES publish_batch_items(id),
 publication_id bigint REFERENCES page_publications(id), previous_publication_id bigint REFERENCES page_publications(id),
 status text NOT NULL DEFAULT 'selected', error_code text,
 PRIMARY KEY(campaign_id,page_id), FOREIGN KEY(site_id,page_id) REFERENCES pages(site_id,id)
);
CREATE INDEX publication_campaign_pages_chunk ON publication_campaign_pages(chunk_id);
CREATE INDEX publication_campaign_pages_item ON publication_campaign_pages(item_id);
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Campaign/audit history requires an explicit reviewed restore, not a destructive rollback.');
    }
};
