<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AgenticEngineeringOs;

use App\Services\Ai\AgenticEngineeringOs\AaeosPhaseHandoffService;
use App\Services\Ai\AgenticEngineeringOs\AtlasAaeosHttpPathFacadeService;
use App\Services\Ai\Kernel\Architecture\AtlasFeaturePlacementService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Tests\TestCase;

/**
 * Unit tests for T1.4 Phase 1 / AP-696 AAEOS HTTP Path Facade.
 *
 * The facade is a pure decorator: given an HTTP `data` envelope and a
 * configured phase, it MUST return a result with status, intent_id,
 * patched data, AAEOS phase envelopes, blocker (or null) and telemetry.
 *
 * We verify three canonical paths:
 *   - legacy mode is a no-op (zero envelopes, original data preserved).
 *   - Phase 1 with placement OK emits the canonical sub-sequence
 *     (intent_capture, disambiguation skip, placement) and patches data
 *     with `payload.aaeos_http_path`.
 *   - Phase 1 with placement gate BLOCKED surfaces blocker code
 *     `placement_gate_blocked` and HTTP 422 without proceeding.
 */
final class AtlasAaeosHttpPathFacadeServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Ensure canonical defaults regardless of host environment.
        config([
            'atlas.aaeos.http_path_phase' => 'legacy',
            'atlas.aaeos.placement_cache_ttl_seconds' => 300,
            'atlas.aaeos.telemetry_enabled' => true,
            'atlas.aaeos.mission_foundation_optional_at_phase_1' => true,
        ]);
    }

    public function test_legacy_phase_is_no_op(): void
    {
        $facade = $this->makeFacade(placementResult: $this->okPlacement());
        $data = $this->incomingData('corrige timezone do export excel');

        $result = $facade->run($data, 'legacy');

        self::assertSame(AtlasAaeosHttpPathFacadeService::RESULT_OK, $result['status']);
        self::assertSame([], $result['envelopes']);
        self::assertNull($result['blocker']);
        self::assertSame('legacy', $result['telemetry']['phase_active']);
        // Data must come back identical except for the intent_id allocation,
        // which legacy mode still produces but does NOT inject into payload.
        self::assertSame($data, $result['data']);
        self::assertNotEmpty($result['intent_id']);
    }

    public function test_phase_1_emits_canonical_envelopes_and_patches_payload(): void
    {
        $facade = $this->makeFacade(placementResult: $this->okPlacement());
        $data = $this->incomingData('cria endpoint POST /reports/export com auth');

        $result = $facade->run($data, '1');

        self::assertSame(AtlasAaeosHttpPathFacadeService::RESULT_OK, $result['status']);
        self::assertNull($result['blocker']);
        self::assertSame('1', $result['telemetry']['phase_active']);
        self::assertCount(3, $result['envelopes'], 'must emit P0 intent_capture, P1 disambiguation (skip), P2 placement');

        $phasesOut = array_map(static fn (array $env): string => (string) $env['phase_out'], $result['envelopes']);
        self::assertSame(
            [
                AaeosPhaseHandoffService::PHASE_INTENT_CAPTURE,
                AaeosPhaseHandoffService::PHASE_DISAMBIGUATION,
                AaeosPhaseHandoffService::PHASE_PLACEMENT,
            ],
            $phasesOut,
        );
        // Disambiguation is recorded as a justified skip (never silent).
        self::assertNotEmpty($result['envelopes'][1]['skip_reason']);
        // Placement envelope reports the gate as passed.
        self::assertContains(
            'placement_decision_feature_path_valid',
            (array) $result['envelopes'][2]['gates']['passed'],
        );
        // Patched payload carries the canonical metadata for downstream services.
        self::assertArrayHasKey('aaeos_http_path', $result['data']['payload']);
        self::assertSame(
            'atlas.aaeos.http_path_request.v1',
            $result['data']['payload']['aaeos_http_path']['schema'],
        );
        self::assertSame(
            $result['intent_id'],
            $result['data']['payload']['aaeos_http_path']['intent_id'],
        );
    }

    public function test_phase_1_blocks_when_placement_gate_blocked(): void
    {
        $facade = $this->makeFacade(placementResult: $this->blockedPlacement());
        $data = $this->incomingData('apaga a tabela de finance e exporta tudo');

        $result = $facade->run($data, '1');

        self::assertSame(AtlasAaeosHttpPathFacadeService::RESULT_BLOCKED, $result['status']);
        self::assertNotNull($result['blocker']);
        self::assertSame(
            AtlasAaeosHttpPathFacadeService::BLOCK_PLACEMENT_GATE_BLOCKED,
            $result['blocker']['code'],
        );
        self::assertSame(422, $result['blocker']['http_status']);
        self::assertNotEmpty($result['blocker']['blocked_when']);
        // Placement envelope marks the gate as blocked, not silently passed.
        $placementEnv = end($result['envelopes']);
        self::assertContains(
            'placement_decision_feature_path_valid',
            (array) $placementEnv['gates']['blocked'],
        );
        self::assertNotEmpty($placementEnv['blockers']);
    }

    public function test_placement_decision_is_cached_within_ttl(): void
    {
        $placementSpy = new SpyPlacementService($this->okPlacement());
        $facade = $this->makeFacadeWithPlacementSpy($placementSpy);

        $data = $this->incomingData('identical intent');
        $facade->run($data, '1');
        $facade->run($data, '1');

        self::assertSame(1, $placementSpy->callCount, 'identical intent must reuse cached placement decision');
    }

    public function test_telemetry_snapshot_returns_canonical_schema(): void
    {
        $facade = $this->makeFacade(placementResult: $this->okPlacement());
        $facade->run($this->incomingData('foo'), '1');

        $snapshot = $facade->telemetrySnapshot('1');

        self::assertSame('atlas.aaeos.http_path_status.v1', $snapshot['schema']);
        self::assertSame('1', $snapshot['configured_phase']);
        self::assertTrue($snapshot['facade_active']);
        self::assertSame('atlas.aaeos.phase_router.v1', $snapshot['phase_router']['schema_version']);
        self::assertTrue($snapshot['phase_router']['phase_capabilities']['placement']);
        self::assertFalse($snapshot['phase_router']['phase_capabilities']['policy_gate']);
        self::assertGreaterThanOrEqual(1, $snapshot['counters']['requests']);
        self::assertGreaterThanOrEqual(1, $snapshot['counters']['canonical_calls']);
    }

    public function test_phase_2_emits_classification_and_policy_envelopes_when_router_decision_present(): void
    {
        $facade = $this->makeFacade(placementResult: $this->okPlacement());
        $data = $this->incomingData('rota dev simples');
        $data['payload']['atlas_ai_router'] = [
            'flow_id' => 'atlas_dev',
            'command_intent' => 'dev',
        ];
        $data['payload']['atlas_ai_assisted_execution_quality'] = [
            'route' => ['target' => 'atlas_dev'],
            'status' => 'ready_for_assisted_execution',
        ];

        $result = $facade->run($data, '2');

        self::assertSame(AtlasAaeosHttpPathFacadeService::RESULT_OK, $result['status']);
        self::assertCount(5, $result['envelopes'], 'phase 2 must emit P0..P4');
        $phasesOut = array_map(static fn (array $env): string => (string) $env['phase_out'], $result['envelopes']);
        self::assertSame(
            [
                AaeosPhaseHandoffService::PHASE_INTENT_CAPTURE,
                AaeosPhaseHandoffService::PHASE_DISAMBIGUATION,
                AaeosPhaseHandoffService::PHASE_PLACEMENT,
                AaeosPhaseHandoffService::PHASE_CLASSIFICATION,
                AaeosPhaseHandoffService::PHASE_POLICY_GATE,
            ],
            $phasesOut,
        );
        self::assertContains(
            'intent_classification_target_department_declared',
            (array) $result['envelopes'][3]['gates']['passed'],
        );
        self::assertContains(
            'policy_decision_allowed_true',
            (array) $result['envelopes'][4]['gates']['passed'],
        );
    }

    public function test_phase_2_blocks_when_assisted_execution_not_ready_for_atlas_dev(): void
    {
        $facade = $this->makeFacade(placementResult: $this->okPlacement());
        $data = $this->incomingData('apaga essa pasta toda agora');
        $data['payload']['atlas_ai_router'] = [
            'flow_id' => 'atlas_dev',
            'command_intent' => 'dev',
        ];
        $data['payload']['atlas_ai_assisted_execution_quality'] = [
            'route' => ['target' => 'atlas_dev'],
            'status' => 'needs_more_context',
            'blockers' => [
                ['id' => 'missing_workspace', 'severity' => 'high', 'owner' => 'atlas-dev'],
            ],
        ];

        $result = $facade->run($data, '2');

        self::assertSame(AtlasAaeosHttpPathFacadeService::RESULT_BLOCKED, $result['status']);
        self::assertSame(
            AtlasAaeosHttpPathFacadeService::BLOCK_POLICY_GATE_BLOCKED,
            $result['blocker']['code'],
        );
        self::assertSame(422, $result['blocker']['http_status']);
        self::assertContains('missing_workspace', $result['blocker']['blocked_when']);
        self::assertStringContainsString(
            'high_severity_blocker_block',
            (string) $result['blocker']['reason'],
            'policy stop must surface PhaseAdvanceVerdictClassifier reason',
        );    }

    public function test_phase_2_passes_policy_gate_for_non_dev_target(): void
    {
        // Atlas operational / non-dev intents should not be blocked by the
        // assisted-execution policy gate. They simply do not fire it.
        $facade = $this->makeFacade(placementResult: $this->okPlacement());
        $data = $this->incomingData('agenda reuniao com cliente');
        $data['payload']['atlas_ai_router'] = [
            'flow_id' => 'atlas_assistant',
            'command_intent' => 'chat',
        ];
        // No atlas_ai_assisted_execution_quality at all — gate should pass.

        $result = $facade->run($data, '2');

        self::assertSame(AtlasAaeosHttpPathFacadeService::RESULT_OK, $result['status']);
        $policyEnv = $result['envelopes'][4];
        self::assertContains(
            'policy_decision_allowed_true',
            (array) $policyEnv['gates']['passed'],
        );
        self::assertSame('not_required', $policyEnv['outputs']['policy_status']);
    }

    public function test_phase_3_r1_r2_emits_topology_and_routing_as_canonical_skips(): void
    {
        $facade = $this->makeFacade(placementResult: $this->okPlacement());
        $data = $this->incomingData('fix small bug em controller');
        $data['payload']['atlas_ai_router'] = [
            'flow_id' => 'atlas_dev',
            'command_intent' => 'dev',
        ];

        $result = $facade->run($data, '3');

        self::assertSame(AtlasAaeosHttpPathFacadeService::RESULT_OK, $result['status']);
        self::assertCount(7, $result['envelopes'], 'phase 3 must emit P0..P6');
        $phasesOut = array_map(static fn (array $env): string => (string) $env['phase_out'], $result['envelopes']);
        self::assertSame(
            [
                AaeosPhaseHandoffService::PHASE_INTENT_CAPTURE,
                AaeosPhaseHandoffService::PHASE_DISAMBIGUATION,
                AaeosPhaseHandoffService::PHASE_PLACEMENT,
                AaeosPhaseHandoffService::PHASE_CLASSIFICATION,
                AaeosPhaseHandoffService::PHASE_POLICY_GATE,
                AaeosPhaseHandoffService::PHASE_TOPOLOGY,
                AaeosPhaseHandoffService::PHASE_ROUTING,
            ],
            $phasesOut,
        );
        // R1-R2 → topology and routing must be canonical skips with reason.
        self::assertNotEmpty($result['envelopes'][5]['skip_reason']);
        self::assertStringContainsString('r1_r2_fast_path', $result['envelopes'][5]['skip_reason']);
        self::assertNotEmpty($result['envelopes'][6]['skip_reason']);
        self::assertStringContainsString('r1_r2_fast_path', $result['envelopes'][6]['skip_reason']);
    }

    public function test_phase_3_r3_plus_emits_topology_and_routing_with_deferred_aawr(): void
    {
        $facade = $this->makeFacade(placementResult: $this->okPlacement());
        $data = $this->incomingData('cria obra grande multi-arquivo para refator');
        $data['payload']['atlas_ai_router'] = [
            'flow_id' => 'programming.forge',
            'command_intent' => 'forge',
        ];

        $result = $facade->run($data, '3');

        self::assertSame(AtlasAaeosHttpPathFacadeService::RESULT_OK, $result['status']);
        self::assertCount(7, $result['envelopes']);
        // R3+ topology envelope reports topology_required + deferred.
        self::assertSame('yes', $result['envelopes'][5]['outputs']['topology_required']);
        self::assertSame('deferred', $result['envelopes'][5]['outputs']['aawr_invocation']);
        // R3+ routing envelope reports company_runtime deferred.
        self::assertSame('deferred', $result['envelopes'][6]['outputs']['company_runtime_invocation']);
    }

    public function test_phase_4_r3_plus_emits_p7_p8_p9_with_deferred_invocations(): void
    {
        $facade = $this->makeFacade(placementResult: $this->okPlacement());
        $data = $this->incomingData('plano grande de refator');
        $data['payload']['atlas_ai_router'] = [
            'flow_id' => 'programming.forge',
            'command_intent' => 'forge',
        ];

        $result = $facade->run($data, '4');

        self::assertSame(AtlasAaeosHttpPathFacadeService::RESULT_OK, $result['status']);
        self::assertCount(10, $result['envelopes'], 'phase 4 must emit P0..P9');
        self::assertSame(10, $result['data']['payload']['aaeos_http_path']['phases_executed_count']);
        $phasesOut = array_map(static fn (array $env): string => (string) $env['phase_out'], $result['envelopes']);
        self::assertSame(
            [
                AaeosPhaseHandoffService::PHASE_INTENT_CAPTURE,
                AaeosPhaseHandoffService::PHASE_DISAMBIGUATION,
                AaeosPhaseHandoffService::PHASE_PLACEMENT,
                AaeosPhaseHandoffService::PHASE_CLASSIFICATION,
                AaeosPhaseHandoffService::PHASE_POLICY_GATE,
                AaeosPhaseHandoffService::PHASE_TOPOLOGY,
                AaeosPhaseHandoffService::PHASE_ROUTING,
                AaeosPhaseHandoffService::PHASE_SPEC,
                AaeosPhaseHandoffService::PHASE_TASKS,
                AaeosPhaseHandoffService::PHASE_RECEIPT,
            ],
            $phasesOut,
        );
        self::assertSame('deferred', $result['envelopes'][7]['outputs']['spec_invocation']);
        self::assertSame('deferred', $result['envelopes'][8]['outputs']['task_pack_invocation']);
        self::assertSame('yes', $result['envelopes'][9]['outputs']['receipt_required']);
        self::assertSame('deferred', $result['envelopes'][9]['outputs']['decision_receipt_v2_invocation']);
    }

    public function test_phase_4_r1_r2_emits_p7_p8_p9_as_canonical_skips(): void
    {
        $facade = $this->makeFacade(placementResult: $this->okPlacement());
        $data = $this->incomingData('bug pequeno em controller');
        $data['payload']['atlas_ai_router'] = [
            'flow_id' => 'atlas_dev',
            'command_intent' => 'dev',
        ];

        $result = $facade->run($data, '4');

        self::assertSame(AtlasAaeosHttpPathFacadeService::RESULT_OK, $result['status']);
        self::assertCount(10, $result['envelopes']);
        foreach ([7, 8, 9] as $idx) {
            self::assertNotEmpty($result['envelopes'][$idx]['skip_reason'], "envelope index {$idx} must have skip_reason");
            self::assertStringContainsString(
                'r1_r2_fast_path',
                (string) $result['envelopes'][$idx]['skip_reason'],
            );
        }
    }

    public function test_phase_3_still_emits_only_seven_envelopes_after_phase_4_added(): void
    {
        $facade = $this->makeFacade(placementResult: $this->okPlacement());
        $data = $this->incomingData('phase 3 regression');
        $data['payload']['atlas_ai_router'] = [
            'flow_id' => 'atlas_dev',
            'command_intent' => 'dev',
        ];

        $result = $facade->run($data, '3');

        self::assertCount(7, $result['envelopes'], 'phase 3 must stop after P6 routing');
    }

    public function test_phase_2_still_emits_only_five_envelopes_after_phase_3_added(): void
    {
        $facade = $this->makeFacade(placementResult: $this->okPlacement());
        $data = $this->incomingData('phase 2 regression');
        $data['payload']['atlas_ai_router'] = [
            'flow_id' => 'atlas_dev',
            'command_intent' => 'dev',
        ];

        $result = $facade->run($data, '2');

        self::assertSame(AtlasAaeosHttpPathFacadeService::RESULT_OK, $result['status']);
        self::assertCount(5, $result['envelopes'], 'phase 2 must stop after P4 policy_gate');
    }

    public function test_phase_1_still_emits_only_three_envelopes_after_phase_2_added(): void
    {
        // Regression guard: adding Phase 2 emission MUST NOT leak into Phase 1.
        $facade = $this->makeFacade(placementResult: $this->okPlacement());
        $data = $this->incomingData('phase 1 regression');
        $data['payload']['atlas_ai_router'] = [
            'flow_id' => 'atlas_dev',
            'command_intent' => 'dev',
        ];

        $result = $facade->run($data, '1');

        self::assertSame(AtlasAaeosHttpPathFacadeService::RESULT_OK, $result['status']);
        self::assertCount(3, $result['envelopes'], 'phase 1 must stop after P2 placement');
    }

    public function test_is_active_recognizes_phase_values(): void
    {
        self::assertFalse(AtlasAaeosHttpPathFacadeService::isActive('legacy'));
        self::assertTrue(AtlasAaeosHttpPathFacadeService::isActive('1'));
        self::assertTrue(AtlasAaeosHttpPathFacadeService::isActive('2'));
        self::assertTrue(AtlasAaeosHttpPathFacadeService::isActive('3'));
        self::assertTrue(AtlasAaeosHttpPathFacadeService::isActive('4'));
        self::assertFalse(AtlasAaeosHttpPathFacadeService::isActive(''));
        self::assertFalse(AtlasAaeosHttpPathFacadeService::isActive('5'));
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $placementResult
     */
    private function makeFacade(array $placementResult): AtlasAaeosHttpPathFacadeService
    {
        return $this->makeFacadeWithPlacementSpy(new SpyPlacementService($placementResult));
    }

    private function makeFacadeWithPlacementSpy(SpyPlacementService $placement): AtlasAaeosHttpPathFacadeService
    {
        return new AtlasAaeosHttpPathFacadeService(
            handoff: new AaeosPhaseHandoffService(),
            placement: $placement,
            missionDetection: null,
            cache: new Repository(new ArrayStore()),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function incomingData(string $text): array
    {
        return [
            'input_text' => $text,
            'source_type' => 'app',
            'payload' => [
                'workspace' => '/tmp/atlas-test',
                'atlas_mode' => 'programming',
                'flow_id' => 'programming.dev',
                'surface_id' => 'atlas_ai',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function okPlacement(): array
    {
        return [
            'schema_version' => 'atlas.feature_placement.v1',
            'status' => 'ok',
            'gate_status' => 'passed',
            'placement' => [
                'layer' => 'domain',
                'domain' => 'programming',
                'flow' => 'programming.dev',
                'requires_ap' => false,
            ],
            'blocked_when' => [],
            'duplicate_candidates' => [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function blockedPlacement(): array
    {
        return [
            'schema_version' => 'atlas.feature_placement.v1',
            'status' => 'ok',
            'gate_status' => 'blocked',
            'placement' => [
                'layer' => 'documentation_governance',
                'domain' => 'finance',
                'flow' => 'finance.research',
                'requires_ap' => true,
            ],
            'blocked_when' => [
                'new_or_future_capability_requires_ap_contract_first',
                'high_overlap_duplicate_candidate_requires_reuse_or_explicit_supersede_decision',
            ],
            'duplicate_candidates' => [],
        ];
    }
}

/**
 * Minimal placement stub. Avoids spinning up the full
 * AtlasFeaturePlacementService dependency graph (knowledge base,
 * documentation reality, code intelligence, runtime boundary, etc.)
 * which would turn this unit test into a feature test.
 *
 * We extend the real service via a same-namespace alias so the facade's
 * type hint matches. The placement engine itself is exercised by its own
 * dedicated test suite; here we only verify the facade contract.
 */
final class SpyPlacementService extends AtlasFeaturePlacementService
{
    public int $callCount = 0;

    /** @param array<string,mixed> $result */
    public function __construct(private readonly array $result)
    {
        // Intentionally skip parent constructor — we override place() and
        // the parent's dependencies are never used in this stub.
    }

    /** @return array<string,mixed> */
    public function place(string $feature, array $hints = []): array
    {
        $this->callCount++;

        return $this->result;
    }
}
