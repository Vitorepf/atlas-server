<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * K3 — WindowedReservationService backing tables (Atlas Phase 0 keystone).
 *
 * Atomic, windowed spend reservation: the death-by-a-thousand-cuts fix. A
 * window row holds (cap, reserved); reservation is an atomic conditional
 * UPDATE (reserved + n <= cap) so concurrent small spends can never
 * collectively exceed the cap. Idempotency via a unique key per reservation.
 *
 * Additive + inert (byte-identical-OFF): new tables wired to nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_venture_spend_windows')) {
            Schema::create('ai_venture_spend_windows', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('scope_ref', 120)->index(); // venture_id or 'PORTFOLIO'
                $table->enum('window_kind', ['hour', 'day', 'week', 'portfolio']);
                $table->string('window_key', 40); // e.g. 2026-06-17, 2026-06-17T14, 2026-W25, ALL
                $table->bigInteger('cap_microusd');
                $table->bigInteger('reserved_microusd')->default(0);
                $table->timestamps();

                $table->unique(['scope_ref', 'window_kind', 'window_key'], 'uniq_spend_window');
            });
        }

        if (! Schema::hasTable('ai_venture_spend_reservations')) {
            Schema::create('ai_venture_spend_reservations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('window_id')->index();
                $table->string('idempotency_key', 191)->unique();
                $table->bigInteger('amount_microusd');
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_venture_spend_reservations');
        Schema::dropIfExists('ai_venture_spend_windows');
    }
};
