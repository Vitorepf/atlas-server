<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('atlas_engineering_code_symbols', function (Blueprint $table): void {
            $table->index(
                ['status', 'archived_at', 'module_id', 'docs_status'],
                'idx_atlas_eng_symbols_doc_refresh',
            );
            $table->index(
                ['status', 'archived_at', 'file_path'],
                'idx_atlas_eng_symbols_active_file',
            );
            $table->index(
                ['indexed_at', 'status', 'archived_at'],
                'idx_atlas_eng_symbols_prune',
            );
        });

        Schema::table('atlas_engineering_doc_links', function (Blueprint $table): void {
            $table->index(
                ['status', 'archived_at', 'module_id'],
                'idx_atlas_eng_doc_links_module_refresh',
            );
            $table->index(
                ['status', 'archived_at', 'symbol_id'],
                'idx_atlas_eng_doc_links_symbol_refresh',
            );
            $table->index(
                ['indexed_at', 'archived_at', 'status'],
                'idx_atlas_eng_doc_links_prune',
            );
        });
    }

    public function down(): void
    {
        Schema::table('atlas_engineering_doc_links', function (Blueprint $table): void {
            $table->dropIndex('idx_atlas_eng_doc_links_module_refresh');
            $table->dropIndex('idx_atlas_eng_doc_links_symbol_refresh');
            $table->dropIndex('idx_atlas_eng_doc_links_prune');
        });

        Schema::table('atlas_engineering_code_symbols', function (Blueprint $table): void {
            $table->dropIndex('idx_atlas_eng_symbols_doc_refresh');
            $table->dropIndex('idx_atlas_eng_symbols_active_file');
            $table->dropIndex('idx_atlas_eng_symbols_prune');
        });
    }
};
