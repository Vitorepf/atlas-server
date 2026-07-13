<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atlas_live_activity_push_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('trace_id')->constrained('ai_traces')->cascadeOnDelete();
            $table->string('activity_id', 128)->unique();
            $table->string('installation_id', 128)->index();
            $table->text('push_token'); // encrypted cast in AtlasLiveActivityPushToken
            $table->string('push_token_hash', 128)->index();
            $table->string('environment', 16);
            $table->string('status', 24)->default('active')->index();
            $table->timestamp('started_at');
            $table->timestamp('invalidated_at')->nullable()->index();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_pushed_at')->nullable();
            $table->boolean('frequent_updates_enabled')->default(false);
            $table->timestamps();

            $table->index(['trace_id', 'status']);
            $table->index(['installation_id', 'status']);
        });

        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_atlas_live_activity_push_tokens_updated_at
            BEFORE UPDATE ON atlas_live_activity_push_tokens
            FOR EACH ROW EXECUTE FUNCTION set_updated_at();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_live_activity_push_tokens');
    }
};
