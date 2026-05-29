<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_engineering_code_symbols')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                DELETE FROM atlas_engineering_code_symbols
                WHERE ctid IN (
                    SELECT ctid
                    FROM (
                        SELECT
                            ctid,
                            row_number() OVER (
                                PARTITION BY symbol_type, source_hash
                                ORDER BY updated_at DESC NULLS LAST, indexed_at DESC NULLS LAST, created_at DESC NULLS LAST, id DESC
                            ) AS duplicate_rank
                        FROM atlas_engineering_code_symbols
                    ) ranked_symbols
                    WHERE duplicate_rank > 1
                )
            SQL);

            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX IF NOT EXISTS uniq_atlas_eng_code_symbol_source
                ON atlas_engineering_code_symbols (symbol_type, source_hash)
            SQL);

            DB::statement(<<<'SQL'
                CREATE INDEX IF NOT EXISTS idx_atlas_eng_symbols_doc_refresh
                ON atlas_engineering_code_symbols (status, archived_at, module_id, docs_status)
            SQL);

            DB::statement(<<<'SQL'
                CREATE INDEX IF NOT EXISTS idx_atlas_eng_symbols_active_file
                ON atlas_engineering_code_symbols (status, archived_at, file_path)
            SQL);

            DB::statement(<<<'SQL'
                CREATE INDEX IF NOT EXISTS idx_atlas_eng_symbols_prune
                ON atlas_engineering_code_symbols (indexed_at, status, archived_at)
            SQL);

            return;
        }

        Schema::table('atlas_engineering_code_symbols', function (Blueprint $table): void {
            $table->unique(['symbol_type', 'source_hash'], 'uniq_atlas_eng_code_symbol_source');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('atlas_engineering_code_symbols')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS idx_atlas_eng_symbols_prune');
            DB::statement('DROP INDEX IF EXISTS idx_atlas_eng_symbols_active_file');
            DB::statement('DROP INDEX IF EXISTS idx_atlas_eng_symbols_doc_refresh');
            DB::statement('DROP INDEX IF EXISTS uniq_atlas_eng_code_symbol_source');

            return;
        }

        Schema::table('atlas_engineering_code_symbols', function (Blueprint $table): void {
            $table->dropUnique('uniq_atlas_eng_code_symbol_source');
        });
    }
};
