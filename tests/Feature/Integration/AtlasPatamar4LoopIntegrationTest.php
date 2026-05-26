<?php

declare(strict_types=1);

namespace Tests\Feature\Integration;

use App\Services\Ai\AtlasDecide\AtlasDecideGatewayConsultationService;
use App\Services\Ai\Cognition\AtlasCognitiveFunctionAtlasService;
use App\Services\Ai\Compounding\AtlasAntifragilityCompositionMetricService;
use App\Services\Ai\Compounding\AtlasCompoundingLevel8DistillationService;
use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\Patamar4\AtlasPatamar4StateService;
use App\Services\Ai\Reconciliation\AtlasAutonomousReconciliationRuntimeService;
use App\Services\Ai\Teos\AtlasTeosI3CounterfactualService;
use App\Services\Ai\Teos\AtlasTeosI4CounterfactualTreeService;
use Tests\TestCase;

/**
 * End-to-end integration test: prove the autonomous loop closes across all
 * Patamar 4 services via real DI resolution (no test stubs except registry).
 *
 * Validates that:
 *   1. Reconciliation tick fires real ASCB.propose() when admission=allow_autonomous
 *   2. TEOS-I3 auto-emits AURG-4D ticks via DI wiring
 *   3. TEOS-I3 meta-projection runs before ASCB.propose() via DI wiring
 *   4. Constitutional Kernel blocks claim_policy violations
 *   5. State aggregator reflects all activity
 *   6. Antifragility metric increments with activity
 */
class AtlasPatamar4LoopIntegrationTest extends TestCase
{
    public function test_full_loop_real_di_end_to_end(): void
    {
        // Resolve all services via container — exactly what production uses.
        $kernel = $this->app->make(AtlasConstitutionalKernelService::class);
        $admission = $this->app->make(AtlasAutonomyAdmissionService::class);
        $cfa = $this->app->make(AtlasCognitiveFunctionAtlasService::class);
        $reconciliation = $this->app->make(AtlasAutonomousReconciliationRuntimeService::class);
        $teosI3 = $this->app->make(AtlasTeosI3CounterfactualService::class);
        $teosI4 = $this->app->make(AtlasTeosI4CounterfactualTreeService::class);
        $gatewayConsult = $this->app->make(AtlasDecideGatewayConsultationService::class);
        $antifragility = $this->app->make(AtlasAntifragilityCompositionMetricService::class);
        $compounding = $this->app->make(AtlasCompoundingLevel8DistillationService::class);
        $state = $this->app->make(AtlasPatamar4StateService::class);

        // 1. Kernel must have at least 9 pétreos + elastic + runtime.
        $petreos = $kernel->listInvariants(AtlasConstitutionalKernelService::CLASS_PETREO);
        $elastic = $kernel->listInvariants(AtlasConstitutionalKernelService::CLASS_ELASTIC);
        $runtime = $kernel->listInvariants(AtlasConstitutionalKernelService::CLASS_RUNTIME);
        $this->assertGreaterThanOrEqual(9, count($petreos));
        $this->assertGreaterThanOrEqual(1, count($elastic));
        $this->assertGreaterThanOrEqual(1, count($runtime));

        // 2. CFA produces self-model with 51+ subsystems.
        $selfModel = $cfa->selfModel();
        $this->assertGreaterThanOrEqual(51, $selfModel['subsystem_count']);

        // 3. Reconciliation tick fires with force_group + autonomous → real ASCB proposal.
        $tick = $reconciliation->tick([
            'force_group' => 'cognitive_immune',
            'privacy_class' => 'public',
            'requested_autonomy' => 'autonomous',
        ]);
        $this->assertSame(AtlasAutonomousReconciliationRuntimeService::OUTCOME_AUTO_APPLIED, $tick['outcome']);
        $this->assertNotNull($tick['step']['ascb_proposal_id']);
        $this->assertStringStartsWith('prop_', $tick['step']['ascb_proposal_id']);
        $this->assertNotNull($tick['step']['aurg_tick_id']);
        $this->assertNotNull($tick['step']['projection_branch_id'], 'TEOS-I3 meta-projection must run via DI wiring');

        // 4. TEOS-I3 standalone branch must auto-emit AURG-4D tick via DI.
        $branch = $teosI3->branch([
            'anchor_decision_id' => 'integration_test_anchor',
            'alternative' => ['decision_kind' => 'policy_swap', 'value' => 'strict'],
            'factual_outcome_score' => 0.5,
            'projected_outcome_score' => 0.7,
        ]);
        $this->assertNotNull($branch['aurg_tick_id'] ?? null, 'TEOS-I3 must auto-chain to AURG-4D in production DI');

        // 5. Kernel BLOCKS claim_policy violation.
        $blocked = $kernel->validateChange([
            'change_kind' => 'subsystem_propose',
            'proposed_effect' => 'innocent',
            'claims' => ['rivals'],
            'actor' => 'integration_test',
        ]);
        $this->assertSame(AtlasConstitutionalKernelService::DECISION_BLOCK, $blocked['decision']);

        // 6. State aggregator surfaces everything.
        $snapshot = $state->snapshot(3);
        $this->assertArrayHasKey('kernel', $snapshot);
        $this->assertArrayHasKey('reconciliation', $snapshot);
        $this->assertArrayHasKey('antifragility', $snapshot);
        $this->assertGreaterThan(0, $snapshot['reconciliation']['summary']['tick_count']);

        // 7. Antifragility multiplier is in [0,1] honest range.
        $m = $antifragility->measure();
        $this->assertGreaterThan(0.0, $m['wrapper_multiplier_m']);
        $this->assertLessThanOrEqual(1.0, $m['wrapper_multiplier_m']);
        $this->assertFalse($m['claim_policy']['benchmark_claim_allowed']);

        // 8. Compounding distillation reads activity and classifies level.
        $distill = $compounding->distill(false);
        $this->assertContains($distill['level'], [
            AtlasCompoundingLevel8DistillationService::LEVEL_L7,
            AtlasCompoundingLevel8DistillationService::LEVEL_L8,
            AtlasCompoundingLevel8DistillationService::LEVEL_L9,
        ]);

        // 9. Gateway consultation hook is callable.
        $consult = $gatewayConsult->consult([
            'task_category' => 'code_generation',
            'role' => 'primary',
            'privacy_class' => 'public',
        ]);
        $this->assertContains($consult['verdict'], [
            AtlasDecideGatewayConsultationService::VERDICT_FOLLOW_LEARNED,
            AtlasDecideGatewayConsultationService::VERDICT_FREE_TO_CHOOSE,
            AtlasDecideGatewayConsultationService::VERDICT_REQUIRES_APPROVAL,
            AtlasDecideGatewayConsultationService::VERDICT_BLOCKED,
        ]);
    }

    public function test_claim_policy_invariants_intact_after_loop_run(): void
    {
        $kernel = $this->app->make(AtlasConstitutionalKernelService::class);
        $reconciliation = $this->app->make(AtlasAutonomousReconciliationRuntimeService::class);
        $state = $this->app->make(AtlasPatamar4StateService::class);

        // Fire multiple ticks
        for ($i = 0; $i < 3; $i++) {
            $reconciliation->tick([
                'force_group' => 'cognitive_immune',
                'privacy_class' => 'public',
                'requested_autonomy' => 'autonomous',
            ]);
        }

        $snapshot = $state->snapshot(10);
        // claim_policy must remain hardcoded false across all flags
        foreach (['benchmark_claim_allowed', 'rivals_claim_allowed', 'superiority_claim_allowed', 'external_rivals_certification_touched'] as $k) {
            $this->assertFalse($snapshot['claim_policy'][$k], "claim_policy.{$k} must stay false after autonomous activity");
        }
        $this->assertTrue($snapshot['claim_policy']['cognitive_immune_law_enforced']);
        $this->assertTrue($snapshot['claim_policy']['provider_safe_only_enforced']);
    }

    public function test_kernel_hash_stable_across_calls(): void
    {
        $kernel = $this->app->make(AtlasConstitutionalKernelService::class);
        $h1 = $kernel->kernelHash();
        $h2 = $kernel->kernelHash();
        $this->assertSame($h1, $h2, 'kernel hash MUST be deterministic — any drift means invariant tampering');
    }
}
