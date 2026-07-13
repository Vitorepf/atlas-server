<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atlas_live_activity_start_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('installation_id', 128)->unique();
            $table->text('push_token');
            $table->string('push_token_hash', 128)->index();
            $table->string('environment', 16);
            $table->timestamp('last_seen_at')->nullable();
            $table->uuid('last_started_trace_id')->nullable()->index();
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_atlas_live_activity_start_tokens_updated_at
            BEFORE UPDATE ON atlas_live_activity_start_tokens
            FOR EACH ROW EXECUTE FUNCTION set_updated_at();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_live_activity_start_tokens');
    }
};
