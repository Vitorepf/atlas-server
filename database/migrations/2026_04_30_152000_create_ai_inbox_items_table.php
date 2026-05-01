<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_inbox_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('user_id')->default('vitor')->index();
            $table->string('type', 40);
            $table->string('category', 40)->nullable();
            $table->string('severity', 16)->default('info');
            $table->string('status', 24)->default('unread');
            $table->string('title', 180);
            $table->text('summary')->nullable();
            $table->text('body')->nullable();
            $table->string('source_type', 64)->nullable();
            $table->uuid('source_id')->nullable();
            $table->string('initiator', 32)->default('system');
            $table->uuid('context_bundle_id')->nullable();
            $table->string('dedupe_key', 160)->nullable();
            $table->json('available_actions')->default('[]');
            $table->json('response')->nullable();
            $table->json('payload')->default('{}');
            $table->text('deep_link')->nullable();
            $table->json('push_policy')->default('{}');
            $table->smallInteger('priority_score')->default(50);
            $table->decimal('confidence_score', 4, 3)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('snoozed_until')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'created_at']);
            $table->index(['user_id', 'type', 'status', 'created_at']);
            $table->index(['user_id', 'severity', 'created_at']);
            $table->index(['source_type', 'source_id']);
            $table->index('context_bundle_id');
            $table->index('dedupe_key');
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX ai_inbox_items_active_dedupe_unique
            ON ai_inbox_items (user_id, dedupe_key)
            WHERE dedupe_key IS NOT NULL
              AND status NOT IN ('resolved', 'dismissed', 'expired')
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_ai_inbox_items_updated_at
            BEFORE UPDATE ON ai_inbox_items
            FOR EACH ROW EXECUTE FUNCTION set_updated_at();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_inbox_items');
    }
};
