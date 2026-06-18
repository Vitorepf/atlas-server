<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * K1 — ReconciledCashEventStore (Atlas Phase 0 keystone).
 *
 * The single source of truth that PAYS. Cash credit/reversal events whose
 * `source` is constrained AT THE DB LAYER to externally-reconciled origins
 * (payment_processor | bank | external_reconciled). A self-reported
 * source='operator'/'loop'/'self' can never be inserted — the honesty
 * boundary is a schema invariant, not a PHP string compare.
 *
 * Additive + inert: nothing reads this table yet, so creating it is
 * byte-identical to today's behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_reconciled_cash_events')) {
            Schema::create('ai_reconciled_cash_events', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.venture.reconciled_cash_event.v1');
                $table->string('uuid', 64)->unique();
                $table->uuid('venture_id')->index();
                // DB-enforced allow-list (Laravel enum => varchar + CHECK on PG and SQLite).
                $table->enum('source', ['payment_processor', 'bank', 'external_reconciled']);
                $table->enum('event_kind', ['credit', 'refund', 'chargeback', 'dispute', 'failed_renewal'])->default('credit');
                $table->string('external_ref', 255); // dereferenceable receipt id from the source system
                $table->bigInteger('amount_cents'); // negative for refunds/chargebacks/disputes
                $table->string('currency', 10)->default('BRL');
                $table->timestamp('occurred_at');
                $table->unsignedInteger('settlement_horizon_days')->default(0);
                $table->timestamp('settled_at')->nullable(); // only settled events count toward reward
                $table->string('reverses_external_ref', 255)->nullable();
                $table->json('raw_payload')->nullable();
                $table->string('event_hash', 64)->unique();
                $table->timestamps();

                // Idempotency: the same source event can never be double-counted.
                $table->unique(['source', 'external_ref'], 'uniq_reconciled_cash_source_ref');
                $table->index(['venture_id', 'settled_at'], 'idx_reconciled_cash_venture_settled');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_reconciled_cash_events');
    }
};
