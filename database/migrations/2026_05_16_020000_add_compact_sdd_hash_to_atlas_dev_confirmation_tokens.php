<?php

use App\Services\Ai\Programming\AtlasDev\Schemas\CompactSdd;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-03 tamper-protection follow-up.
 *
 * The Run flow already validates the {@see CompactSdd}
 * file on disk against its own embedded hash, and against the hash pinned in
 * MiniProgrammingSpec — but both pins live on disk and could be overwritten
 * together by an attacker with filesystem access between Plan and Run.
 *
 * This migration adds a server-side pin: the canonical compact_sdd_hash
 * captured at Plan time, stored alongside the HMAC-keyed confirmation token
 * row. The token row is opaque to clients (only the HMAC of the plaintext is
 * persisted), so the pinned hash cannot be forged without APP_KEY.
 *
 * Idempotent: skips the ALTER when the column already exists so the
 * migration is safe to re-run in CI / fresh databases.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_dev_confirmation_tokens')) {
            return;
        }

        if (Schema::hasColumn('atlas_dev_confirmation_tokens', 'compact_sdd_hash')) {
            return;
        }

        Schema::table('atlas_dev_confirmation_tokens', function (Blueprint $table): void {
            $table->string('compact_sdd_hash', 128)->nullable()->after('task_contract_hash')->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('atlas_dev_confirmation_tokens')) {
            return;
        }

        if (! Schema::hasColumn('atlas_dev_confirmation_tokens', 'compact_sdd_hash')) {
            return;
        }

        Schema::table('atlas_dev_confirmation_tokens', function (Blueprint $table): void {
            $table->dropIndex(['compact_sdd_hash']);
            $table->dropColumn('compact_sdd_hash');
        });
    }
};
