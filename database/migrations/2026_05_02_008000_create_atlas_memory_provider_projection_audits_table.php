<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atlas_memory_provider_projection_audits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('action', 24)->default('apply')->index();
            $table->string('target', 16)->default('all')->index();
            $table->string('workspace')->nullable()->index();
            $table->string('initiator', 40)->default('system')->index();
            $table->string('confirmation_mode', 40)->nullable()->index();
            $table->string('status', 32)->default('needs_review')->index();
            $table->boolean('ok')->default(false)->index();
            $table->json('summary_json')->default('{}');
            $table->json('applied_json')->default('[]');
            $table->json('blocked_json')->default('[]');
            $table->json('failed_json')->default('[]');
            $table->json('review_summary_json')->default('{}');
            $table->json('metadata')->default('{}');
            $table->timestamp('applied_at')->useCurrent()->index();
            $table->timestamps();

            $table->index(['workspace', 'target', 'applied_at'], 'idx_atlas_provider_projection_audit_scope');
            $table->index(['status', 'applied_at'], 'idx_atlas_provider_projection_audit_status');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_atlas_memory_provider_projection_audits_updated_at
                BEFORE UPDATE ON atlas_memory_provider_projection_audits
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_memory_provider_projection_audits');
    }
};
