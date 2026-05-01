<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_context_bundles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('user_id')->default('vitor')->index();
            $table->string('purpose', 64);
            $table->string('title', 180);
            $table->text('summary');
            $table->text('body_for_thread');
            $table->json('source_refs')->default('[]');
            $table->json('trace_refs')->default('[]');
            $table->json('job_refs')->default('[]');
            $table->json('metric_refs')->default('[]');
            $table->json('file_refs')->default('[]');
            $table->json('diff_refs')->default('[]');
            $table->json('raw_payload')->default('{}');
            $table->string('redaction_status', 24)->default('clean');
            $table->integer('token_estimate')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->index(['purpose', 'created_at']);
        });

        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_ai_context_bundles_updated_at
            BEFORE UPDATE ON ai_context_bundles
            FOR EACH ROW EXECUTE FUNCTION set_updated_at();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_context_bundles');
    }
};
