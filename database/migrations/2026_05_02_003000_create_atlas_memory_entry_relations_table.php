<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('atlas_memory_entries', function (Blueprint $table): void {
            if (! Schema::hasColumn('atlas_memory_entries', 'content_hash')) {
                $table->string('content_hash', 64)->nullable()->index();
            }

            if (! Schema::hasColumn('atlas_memory_entries', 'governance_checked_at')) {
                $table->timestamp('governance_checked_at')->nullable()->index();
            }
        });

        Schema::create('atlas_memory_entry_relations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('source_memory_entry_id')->index();
            $table->uuid('target_memory_entry_id')->index();
            $table->string('relation_type', 32)->index();
            $table->string('status', 24)->default('open')->index();
            $table->decimal('confidence', 5, 3)->nullable();
            $table->text('reason')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();

            $table->unique(
                ['source_memory_entry_id', 'target_memory_entry_id', 'relation_type'],
                'uniq_atlas_memory_relation_pair_type',
            );
            $table->index(['relation_type', 'status'], 'idx_atlas_memory_rel_type_status');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_atlas_memory_entry_relations_updated_at
                BEFORE UPDATE ON atlas_memory_entry_relations
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_memory_entry_relations');

        Schema::table('atlas_memory_entries', function (Blueprint $table): void {
            if (Schema::hasColumn('atlas_memory_entries', 'governance_checked_at')) {
                $table->dropColumn('governance_checked_at');
            }

            if (Schema::hasColumn('atlas_memory_entries', 'content_hash')) {
                $table->dropColumn('content_hash');
            }
        });
    }
};
