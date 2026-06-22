<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AGENT GOVERNANCE — the CONTROL PLANE for the whole Atlas agent fleet (the definitive fix for
 * "agents running without the operator knowing, burning provider accounts, respawning by themselves").
 *
 * Two tables, one idea: DESIRED-STATE is the single source of respawn authority.
 *
 *   - `atlas_agent_desired_state` — the operator's DECLARED intent, one row per fleet agent. ABSENCE of a
 *     row (or desired=false) means OFF. This is the ONLY thing that authorizes an agent to run/respawn.
 *     The reconciler (the babá) converges the real world toward THIS — never toward orphan `status=running`
 *     campaign rows (that was the exact root cause: the keepalive resurrected any stale `running` row with
 *     no "did the operator turn this on?" gate). `ttl_expires_at` + `budget_limit_usd` are the FREIO: a run
 *     auto-OFFs when its time/spend budget is exhausted. `target_ref` scopes respawn authority to the
 *     EXACT thing the operator launched (e.g. one campaign uuid), so siblings/orphans are never revived.
 *
 *   - `atlas_agent_events` — append-only history (who turned what on/off/when, every start/stop/expire the
 *     reconciler performs, which account it spends). Feeds the apps' "loop history" + audit.
 *
 * Idempotent (hasTable guard); never `INSERT INTO migrations` by hand. Portable sqlite/pgsql (plain Blueprint).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_agent_desired_state')) {
            Schema::create('atlas_agent_desired_state', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('agent_key')->unique();           // 'loop' | 'ai-worker.codex' | 'finance.strategy-loop' | …
                $table->boolean('desired')->default(false);       // FAIL-CLOSED: absence/false ⇒ OFF
                $table->string('set_by')->nullable();             // who flipped it (operator id / 'operator')
                $table->timestamp('set_at')->nullable();          // when desired was last set
                $table->timestamp('ttl_expires_at')->nullable();  // FREIO: past this ⇒ auto-OFF
                $table->decimal('budget_limit_usd', 14, 4)->nullable(); // FREIO: spend ≥ this ⇒ auto-OFF
                $table->string('target_ref')->nullable();         // exact thing authorized (e.g. campaign uuid)
                $table->text('reason')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('atlas_agent_events')) {
            Schema::create('atlas_agent_events', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('agent_key')->index();
                $table->string('event')->index();                 // desired_on|desired_off|started|stopped|expired|reconcile_*
                $table->timestamp('at')->index();                 // canonical event time (append-only)
                $table->string('by')->nullable();                 // operator / reconciler / system
                $table->string('account')->nullable();            // which provider account it spends (denormalized for history)
                $table->unsignedInteger('pid')->nullable();
                $table->unsignedInteger('duration_seconds')->nullable(); // for stop/expire events
                $table->text('reason')->nullable();
                $table->json('detail')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_agent_events');
        Schema::dropIfExists('atlas_agent_desired_state');
    }
};
