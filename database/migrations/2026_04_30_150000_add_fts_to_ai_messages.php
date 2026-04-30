<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::transaction(function (): void {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm;');
            DB::statement('ALTER TABLE ai_messages ADD COLUMN IF NOT EXISTS content_tsv tsvector;');
            DB::statement('CREATE INDEX IF NOT EXISTS idx_ai_messages_content_tsv ON ai_messages USING gin(content_tsv);');
            DB::statement('CREATE INDEX IF NOT EXISTS idx_ai_messages_content_trgm ON ai_messages USING gin(content gin_trgm_ops);');

            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION update_ai_messages_tsv() RETURNS trigger AS $$
                BEGIN
                  NEW.content_tsv := to_tsvector('simple', coalesce(NEW.content, ''));
                  RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
            SQL);

            DB::statement('DROP TRIGGER IF EXISTS ai_messages_tsv_update ON ai_messages;');
            DB::statement(<<<'SQL'
                CREATE TRIGGER ai_messages_tsv_update
                BEFORE INSERT OR UPDATE ON ai_messages
                FOR EACH ROW EXECUTE FUNCTION update_ai_messages_tsv();
            SQL);

            DB::statement("UPDATE ai_messages SET content_tsv = to_tsvector('simple', coalesce(content, '')) WHERE content_tsv IS NULL;");
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::transaction(function (): void {
            DB::statement('DROP TRIGGER IF EXISTS ai_messages_tsv_update ON ai_messages;');
            DB::statement('DROP FUNCTION IF EXISTS update_ai_messages_tsv();');
            DB::statement('DROP INDEX IF EXISTS idx_ai_messages_content_trgm;');
            DB::statement('DROP INDEX IF EXISTS idx_ai_messages_content_tsv;');
            DB::statement('ALTER TABLE ai_messages DROP COLUMN IF EXISTS content_tsv;');
        });
    }
};
