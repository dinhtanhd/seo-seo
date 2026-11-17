<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE page_keyword_assignments ADD COLUMN purpose text NOT NULL DEFAULT 'seo';
ALTER TABLE page_keyword_assignments ADD CONSTRAINT assignment_purpose_valid CHECK (purpose IN ('seo','fanmulu_title'));
ALTER TABLE page_keyword_assignments ADD CONSTRAINT assignment_title_role_valid CHECK (purpose <> 'fanmulu_title' OR role = 'primary');
ALTER TABLE page_keyword_assignments DROP CONSTRAINT page_keyword_assignments_publication_id_role_ordinal_key;
ALTER TABLE page_keyword_assignments ADD CONSTRAINT assignment_purpose_ordinal_unique UNIQUE(publication_id,purpose,role,ordinal);
DROP INDEX one_primary_publication;
CREATE UNIQUE INDEX one_primary_publication ON page_keyword_assignments(publication_id,purpose) WHERE role='primary';
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Destructive rollback disabled; restore a reviewed backup into a new database.');
    }
};
