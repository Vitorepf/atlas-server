<?php

declare(strict_types=1);

namespace Tests\Feature\Patamar4;

use App\Services\Ai\AtlasDecide\AtlasCognitiveFunctionSwarmRouterService;
use App\Services\Ai\Cognition\AtlasCognitionScoreCardService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\Patamar4\AtlasEmbodimentIntegrationService;
use App\Services\Ai\Patamar4\AtlasRuntimeDegradationSignalService;
use App\Services\Ai\Patamar4\AtlasSchedulerHealthService;
use App\Services\Ai\Patamar4\AtlasSubsystemAutoRebalanceService;
use App\Services\Ai\Reconciliation\AtlasAutonomousReconciliationRuntimeService;
use App\Services\Ai\SelfConstruction\AtlasSelfDivergenceModelService;
use Tests\TestCase;

/**
 * Patamar 4 · 4.7 Hardening — cross-system stress test.
 *
 * Runs every Patamar 4 subsystem in sequence within one process so we
 * can prove that the full chain composes without cross-talk:
 *
 *   Scheduler heartbeat → Reconciliation tick (sweeps Auto-Rebalance) →
 *   Runtime Degradation Signal (high) → out-of-cron tick → Self-Divergence
 *   measurement → Cognitive Function Swarm Router → Embodiment snapshot →
 *   Scorecard strict.
 *
 * All artifacts must carry sha256 + provider-safe claim_policy. The kernel
 * hash must remain stable across the whole chain (no drift).
 */
class AtlasPatamar4CrossSystemStressTest extends TestCase
{
    public function test_full_patamar4_chain_runs_end_to_end_without_drift(): void
    {
        // 1. Scheduler heartbeat — proves cron OS layer alive.
        $scheduler = $this->app->make(AtlasSchedulerHealthService::class);
        $scheduler->setLogPathForTesting(sys_get_temp_dir().'/stress_sched_'.uniqid('', true).'.jsonl');
        $beat = $scheduler->recordHeartbeat('stress_test');
        $this->assertStringStartsWith('sha256:', $beat['heartbeat_hash']);

        // 2. Reconciliation tick — should sweep auto-rebalance plans.
        $reconciliation = $this->app->make(AtlasAutonomousReconciliationRuntimeService::class);
        $reconciliation->setTicksLogPathForTesting(sys_get_temp_dir().'/stress_recon_'.uniqid('', true).'.jsonl');
        $tick = $reconciliation->tick();
        $this->assertArrayHasKey('outcome', $tick);

        // 3. Auto-Rebalance — confirm probe wiring honest.
        $rebalance = $this->app->make(AtlasSubsystemAutoRebalanceService::class);
        $rebalance->setLogPathForTesting(sys_get_temp_dir().'/stress_rb_'.uniqid('', true).'.jsonl');
        $plan = $rebalance->plan(AtlasSubsystemAutoRebalanceService::KIND_AEMOR_RECOMPACT);
        $this->assertArrayHasKey('probe_status', $plan['diagnostics']);
        $this->assertContains($plan['diagnostics']['probe_status'], ['ok', 'unwired', 'error']);

        // 4. Runtime Degradation Signal — high severity should fire tick.
        config(['atlas.patamar4.runtime_degradation_auto_tick_enabled' => true]);
        config(['atlas.patamar4.runtime_degradation_auto_tick_threshold' => 'high']);
        $signal = $this->app->make(AtlasRuntimeDegradationSignalService::class);
        $signal->setLogPathForTesting(sys_get_temp_dir().'/stress_sig_'.uniqid('', true).'.jsonl');
        $sig = $signal->record([
            'source' => 'stress_test',
            'kind' => 'synthetic_latency_spike',
            'severity' => 'critical',
            'message' => 'cross-system stress synthetic signal',
        ]);
        $this->assertTrue($sig['auto_tick']['fired']);

        // 5. Self-Divergence — measure target vs current.
        $divergence = $this->app->make(AtlasSelfDivergenceModelService::class);
        $divergence->setLogPathForTesting(sys_get_temp_dir().'/stress_div_'.uniqid('', true).'.jsonl');
        $divergence->setTargetPathForTesting(sys_get_temp_dir().'/stress_div_target_'.uniqid('', true).'.json');
        $div = $divergence->measure();
        $this->assertArrayHasKey('divergence_count', $div);
        $this->assertStringStartsWith('sha256:', $div['divergence_hash']);

        // 6. Cognitive Function Swarm Router — full composition.
        $router = $this->app->make(AtlasCognitiveFunctionSwarmRouterService::class);
        $router->setLogPathForTesting(sys_get_temp_dir().'/stress_router_'.uniqid('', true).'.jsonl');
        $route = $router->routeAndDispatch('audite a cartografia e refatore o controller', ['role' => 'engineer']);
        $this->assertStringStartsWith('sha256:', $route['router_hash']);
        $this->assertArrayHasKey('arms', $route);

        // 7. Embodiment Integration — surface snapshot.
        $embodiment = $this->app->make(AtlasEmbodimentIntegrationService::class);
        $embodiment->setLogPathForTesting(sys_get_temp_dir().'/stress_emb_'.uniqid('', true).'.jsonl');
        $emb = $embodiment->snapshot();
        $this->assertStringStartsWith('sha256:', $emb['embodiment_hash']);
        $this->assertSame(['mac', 'voice', 'stackchan', 'cartography'], array_keys($emb['loci']));

        // 8. Scorecard — overall is RESOLVED from real evidence (doc = FQN-bound
        // ownership, pipeline = fresh green-run receipt), no longer a hardcoded 10/10,
        // so under the stress chain it is honestly BELOW 10. Assert it builds, stays a
        // valid bounded score, and is sub-10 (a 10.0 here would mean the over-claim
        // crept back); the hash still proves a deterministic envelope.
        $score = $this->app->make(AtlasCognitionScoreCardService::class)->build();
        $this->assertGreaterThan(0.0, (float) $score['score']['overall_out_of_10']);
        $this->assertLessThan(10.0, (float) $score['score']['overall_out_of_10']);
        $this->assertStringStartsWith('sha256:', $score['scorecard_hash']);

        // 9. Kernel hash must be the SAME everywhere — no drift across the chain.
        $kernelHash = $this->app->make(AtlasConstitutionalKernelService::class)->kernelHash();
        $this->assertSame($kernelHash, $tick['kernel_hash'] ?? $kernelHash);
        $this->assertSame($kernelHash, $sig['kernel_hash']);
        $this->assertSame($kernelHash, $div['kernel_hash']);
        $this->assertSame($kernelHash, $route['kernel_hash']);
        $this->assertSame($kernelHash, $emb['kernel_hash']);

        // 10. claim_policy must be provider-safe in every envelope that emits one.
        foreach ([$sig['claim_policy'], $div['claim_policy'], $route['claim_policy'], $emb['claim_policy']] as $cp) {
            $this->assertFalse($cp['benchmark_claim_allowed'] ?? null);
            $this->assertFalse($cp['rivals_claim_allowed'] ?? null);
            $this->assertFalse($cp['superiority_claim_allowed'] ?? null);
            $this->assertFalse($cp['external_rivals_certification_touched'] ?? null);
        }
    }

    public function test_swarm_production_flags_can_be_toggled_via_config(): void
    {
        config(['atlas.patamar4.swarm_production_resolver_enabled' => true]);
        config(['atlas.patamar4.swarm_parallel_enabled' => true]);
        config(['atlas.patamar4.swarm_auto_failover_enabled' => true]);
        $this->assertTrue((bool) config('atlas.patamar4.swarm_production_resolver_enabled'));
        $this->assertTrue((bool) config('atlas.patamar4.swarm_parallel_enabled'));
        $this->assertTrue((bool) config('atlas.patamar4.swarm_auto_failover_enabled'));
    }
}
