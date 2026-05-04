<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_vault_sync_items')) {
            return;
        }

        Schema::create('atlas_vault_sync_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('direction', 24)->index();
            $table->string('operation', 40)->index();
            $table->string('status', 32)->default('pending')->index();
            $table->string('path')->nullable()->index();
            $table->string('source_type', 80)->nullable()->index();
            $table->string('source_id', 120)->nullable()->index();
            $table->uuid('semantic_note_id')->nullable()->index();
            $table->string('content_hash', 64)->nullable()->index();
            $table->string('conflict_type', 80)->nullable()->index();
            $table->text('summary')->nullable();
            $table->json('frontmatter_json')->default('{}');
            $table->json('links_json')->default('[]');
            $table->json('metadata')->default('{}');
            $table->timestamp('reviewed_at')->nullable()->index();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->timestamps();

            $table->index(['direction', 'status', 'created_at'], 'idx_atlas_vault_sync_direction_status');
            $table->index(['path', 'content_hash'], 'idx_atlas_vault_sync_path_hash');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_atlas_vault_sync_items_updated_at
                BEFORE UPDATE ON atlas_vault_sync_items
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_vault_sync_items');
    }
};
