<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Completion;

use App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionAtlasNativeReadinessPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasSelfConstructionAtlasNativeReadinessPolicy: every required capability owned by
 * atlas_native + verified + evidence_ref ⇒ ready; any non-Atlas-native owner ⇒
 * blocked_by_dependency; missing evidence or verified=false ⇒ blocked_by_missing_evidence; oversight
 * and emergency capabilities are surfaced separately (not steady-state ownership).
 */
final class AtlasSelfConstructionAtlasNativeReadinessPolicyTest extends TestCase
{
    private function allReadyOrdinary(): array
    {
        $out = [];
        foreach (AtlasSelfConstructionAtlasNativeReadinessPolicy::REQUIRED_ORDINARY_CAPABILITIES as $cap) {
            $out[$cap] = ['owner' => AtlasSelfConstructionAtlasNativeReadinessPolicy::ATLAS_NATIVE_OWNER, 'verified' => true, 'evidence_ref' => 'evh-'.$cap];
        }

        return $out;
    }

    public function test_ready_when_all_required_capabilities_owned_by_atlas_native_and_verified(): void
    {
        $r = (new AtlasSelfConstructionAtlasNativeReadinessPolicy)->evaluate([
            'ordinary_capabilities' => $this->allReadyOrdinary(),
            'oversight_capabilities' => ['view_dashboard' => ['owner' => 'human_operator']],
            'emergency_capabilities' => ['kill_switch' => ['owner' => 'human_operator']],
        ]);
        $this->assertSame(AtlasSelfConstructionAtlasNativeReadinessPolicy::OUTCOME_READY, $r['outcome']);
        $this->assertSame([], $r['blockers']);
        $this->assertContains('view_dashboard', $r['oversight_capabilities']);
        $this->assertContains('kill_switch', $r['emergency_capabilities']);
    }

    public function test_blocked_by_dependency_when_any_capability_owned_by_non_atlas_owner(): void
    {
        $ordinary = $this->allReadyOrdinary();
        $ordinary['run_gates']['owner'] = 'external_provider_worker';
        $r = (new AtlasSelfConstructionAtlasNativeReadinessPolicy)->evaluate(['ordinary_capabilities' => $ordinary]);
        $this->assertSame(AtlasSelfConstructionAtlasNativeReadinessPolicy::OUTCOME_BLOCKED_DEPENDENCY, $r['outcome']);
        $this->assertContains('non_atlas_native_owner:run_gates:external_provider_worker', $r['blockers']);
    }

    public function test_blocked_by_missing_evidence_when_capability_lacks_evidence_ref(): void
    {
        $ordinary = $this->allReadyOrdinary();
        $ordinary['write_evidence']['evidence_ref'] = '';
        $r = (new AtlasSelfConstructionAtlasNativeReadinessPolicy)->evaluate(['ordinary_capabilities' => $ordinary]);
        $this->assertSame(AtlasSelfConstructionAtlasNativeReadinessPolicy::OUTCOME_BLOCKED_EVIDENCE, $r['outcome']);
        $this->assertContains('missing_evidence_ref:write_evidence', $r['blockers']);
    }

    public function test_blocked_by_missing_evidence_when_capability_not_verified(): void
    {
        $ordinary = $this->allReadyOrdinary();
        $ordinary['inspect_task_packet']['verified'] = false;
        $r = (new AtlasSelfConstructionAtlasNativeReadinessPolicy)->evaluate(['ordinary_capabilities' => $ordinary]);
        $this->assertSame(AtlasSelfConstructionAtlasNativeReadinessPolicy::OUTCOME_BLOCKED_EVIDENCE, $r['outcome']);
        $this->assertContains('unverified:inspect_task_packet', $r['blockers']);
    }

    public function test_missing_capability_yields_blocked_by_missing_evidence(): void
    {
        $ordinary = $this->allReadyOrdinary();
        unset($ordinary['decide_release']);
        $r = (new AtlasSelfConstructionAtlasNativeReadinessPolicy)->evaluate(['ordinary_capabilities' => $ordinary]);
        $this->assertContains('missing_capability:decide_release', $r['blockers']);
    }

    public function test_oversight_and_emergency_are_not_required_for_ready(): void
    {
        // No oversight / emergency supplied — ready must still be true if ordinary set is complete.
        $r = (new AtlasSelfConstructionAtlasNativeReadinessPolicy)->evaluate(['ordinary_capabilities' => $this->allReadyOrdinary()]);
        $this->assertSame(AtlasSelfConstructionAtlasNativeReadinessPolicy::OUTCOME_READY, $r['outcome']);
    }

    public function test_each_continuous_runtime_capability_blocks_readiness_when_missing(): void
    {
        $continuousRuntimeCaps = ['replenish_queue', 'supervise_worker_pool', 'keep_context_fresh', 'repair_poison_packet', 'sync_knowledge'];
        foreach ($continuousRuntimeCaps as $cap) {
            $ordinary = $this->allReadyOrdinary();
            unset($ordinary[$cap]);
            $r = (new AtlasSelfConstructionAtlasNativeReadinessPolicy)->evaluate(['ordinary_capabilities' => $ordinary]);
            $this->assertNotSame(AtlasSelfConstructionAtlasNativeReadinessPolicy::OUTCOME_READY, $r['outcome'], "missing {$cap} should block readiness");
            $this->assertContains("missing_capability:{$cap}", $r['blockers']);
        }
    }

    public function test_ready_with_all_ordinary_and_continuous_runtime_capabilities(): void
    {
        $r = (new AtlasSelfConstructionAtlasNativeReadinessPolicy)->evaluate([
            'ordinary_capabilities' => $this->allReadyOrdinary(),
        ]);
        $this->assertSame(AtlasSelfConstructionAtlasNativeReadinessPolicy::OUTCOME_READY, $r['outcome']);
        $this->assertSame([], $r['blockers']);
        foreach (['replenish_queue', 'supervise_worker_pool', 'keep_context_fresh', 'repair_poison_packet', 'sync_knowledge'] as $cap) {
            $this->assertContains($cap, $r['owned_by_atlas']);
        }
    }

    // ── dimension_verdicts: six non-compensating dimensions ───────────────────

    public function test_all_six_dimensions_present_in_result_when_ready(): void
    {
        $r = (new AtlasSelfConstructionAtlasNativeReadinessPolicy)->evaluate(['ordinary_capabilities' => $this->allReadyOrdinary()]);

        $this->assertArrayHasKey('dimension_verdicts', $r);
        foreach (['planning', 'queueing', 'execution', 'learning', 'certification', 'knowledge_sync'] as $dim) {
            $this->assertArrayHasKey($dim, $r['dimension_verdicts'], "Missing dimension: {$dim}");
            $this->assertSame('ready', $r['dimension_verdicts'][$dim]);
        }
    }

    public function test_missing_planning_capability_blocks_only_planning_dimension(): void
    {
        $ordinary = $this->allReadyOrdinary();
        unset($ordinary['prepare_patch_plan']); // planning dimension
        $r = (new AtlasSelfConstructionAtlasNativeReadinessPolicy)->evaluate(['ordinary_capabilities' => $ordinary]);

        $this->assertSame('blocked', $r['dimension_verdicts']['planning']);
        $this->assertSame('ready',   $r['dimension_verdicts']['execution']);
        $this->assertSame('ready',   $r['dimension_verdicts']['learning']);
        $this->assertSame('ready',   $r['dimension_verdicts']['certification']);
        $this->assertSame('ready',   $r['dimension_verdicts']['knowledge_sync']);
    }

    public function test_strong_dimensions_cannot_compensate_for_missing_knowledge_sync(): void
    {
        $ordinary = $this->allReadyOrdinary();
        unset($ordinary['sync_knowledge']); // knowledge_sync dimension
        $r = (new AtlasSelfConstructionAtlasNativeReadinessPolicy)->evaluate(['ordinary_capabilities' => $ordinary]);

        // Overall outcome must still be blocked despite all other dimensions being ready.
        $this->assertNotSame(AtlasSelfConstructionAtlasNativeReadinessPolicy::OUTCOME_READY, $r['outcome']);
        $this->assertSame('blocked', $r['dimension_verdicts']['knowledge_sync']);
        $this->assertSame('ready',   $r['dimension_verdicts']['planning']);
        $this->assertSame('ready',   $r['dimension_verdicts']['execution']);
    }

    public function test_non_atlas_native_owner_blocks_its_dimension(): void
    {
        $ordinary = $this->allReadyOrdinary();
        $ordinary['route_to_worker']['owner'] = 'external_worker'; // queueing dimension
        $r = (new AtlasSelfConstructionAtlasNativeReadinessPolicy)->evaluate(['ordinary_capabilities' => $ordinary]);

        $this->assertSame('blocked', $r['dimension_verdicts']['queueing']);
        $this->assertSame('ready',   $r['dimension_verdicts']['certification']);
    }
}
