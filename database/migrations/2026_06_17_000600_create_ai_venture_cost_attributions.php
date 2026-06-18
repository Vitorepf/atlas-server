<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * K2 — VentureCostAttributionLedger (Atlas Phase 0 keystone).
 *
 * Per-venture measured burn — the DENOMINATOR for solvency/runway/margin.
 * `cost_mode` segregates the honest floor (token_cost, measured from provider
 * rates) from true operational cost. Burn is UNKNOWN (not zero, not fabricated)
 * until a venture actually has attributed cost rows.
 *
 * Additive + inert: a brand-new ledger written by a new recorder; nothing
 * existing changes (byte-identical-OFF). Wiring real telemetry -> this ledger
 * is a later, flag-gated step.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_venture_cost_attributions')) {
            Schema::create('ai_venture_cost_attributions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.venture.cost_attribution.v1');
                $table->string('uuid', 64)->unique();
                $table->uuid('venture_id')->index();
                // token_cost = measured floor from provider rates; operational = real ops cost (ads/COGS/infra) when fed.
                $table->enum('cost_mode', ['token_cost', 'operational']);
                $table->bigInteger('cost_microusd'); // micro-USD; measured, never fabricated
                $table->string('category', 60)->nullable(); // ads | infra | provider | cogs | ...
                $table->string('source_ref', 255)->nullable(); // trace/agent_run/invoice ref
                $table->string('provider', 80)->nullable();
                $table->string('model', 120)->nullable();
                $table->timestamp('occurred_at');
                $table->string('attribution_hash', 64)->unique();
                $table->timestamps();

                $table->index(['venture_id', 'occurred_at'], 'idx_venture_cost_lookup');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_venture_cost_attributions');
    }
};
