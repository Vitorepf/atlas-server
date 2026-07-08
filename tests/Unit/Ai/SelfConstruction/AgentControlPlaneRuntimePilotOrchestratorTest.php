<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneRuntimePilotOrchestrator;
use Tests\TestCase;

final class AgentControlPlaneRuntimePilotOrchestratorTest extends TestCase
{
    private function orchestrator(): AgentControlPlaneRuntimePilotOrchestrator
    {
        return app(AgentControlPlaneRuntimePilotOrchestrator::class);
    }

    // ── evaluatePromotion: pure, uses app() (deps unused by this method) ──

    public function test_evaluate_promotion_dry_run_to_shadow(): void
    {
        $result = $this->orchestrator()->evaluatePromotion([
            'current_state' => 'dry_run',
            'queue_health_score' => 1.0,
            'queue_health_baseline' => 0.5,
            'worker_fit_known' => true,
            'give_back_risk' => 0.1,
            'give_back_risk_baseline' => 0.5,
        ]);

        $this->assertSame('promote', $result['promotion_decision']);
        $this->assertSame('shadow', $result['state']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame([], $result['missing_evidence']);
    }

    public function test_evaluate_promotion_shadow_to_canary(): void
    {
        $result = $this->orchestrator()->evaluatePromotion([
            'current_state' => 'shadow',
            'queue_health_score' => 1.0,
            'queue_health_baseline' => 0.5,
            'worker_fit_known' => true,
            'give_back_risk' => 0.1,
            'give_back_risk_baseline' => 0.5,
        ]);

        $this->assertSame('promote', $result['promotion_decision']);
        $this->assertSame('canary', $result['state']);
    }

    public function test_evaluate_promotion_canary_to_live(): void
    {
        $result = $this->orchestrator()->evaluatePromotion([
            'current_state' => 'canary',
            'queue_health_score' => 1.0,
            'queue_health_baseline' => 0.5,
            'worker_fit_known' => true,
            'give_back_risk' => 0.1,
            'give_back_risk_baseline' => 0.5,
        ]);

        $this->assertSame('promote', $result['promotion_decision']);
        $this->assertSame('live', $result['state']);
    }

    public function test_evaluate_promotion_live_stays_live(): void
    {
        $result = $this->orchestrator()->evaluatePromotion([
            'current_state' => 'live',
            'queue_health_score' => 1.0,
            'queue_health_baseline' => 0.5,
            'worker_fit_known' => true,
            'give_back_risk' => 0.1,
            'give_back_risk_baseline' => 0.5,
        ]);

        $this->assertSame('promote', $result['promotion_decision']);
        $this->assertSame('live', $result['state']);
    }

    // ── Blockers ───────────────────────────────────────────────────────

    public function test_evaluate_promotion_queue_health_worsened_blocks(): void
    {
        $result = $this->orchestrator()->evaluatePromotion([
            'current_state' => 'dry_run',
            'queue_health_score' => 0.3,
            'queue_health_baseline' => 0.5,
            'worker_fit_known' => true,
            'give_back_risk' => 0.1,
            'give_back_risk_baseline' => 0.5,
        ]);

        $this->assertSame('hold', $result['promotion_decision']);
        $this->assertContains('queue_health_worsened', $result['blockers']);
    }

    public function test_evaluate_promotion_worker_fit_unknown_blocks(): void
    {
        $result = $this->orchestrator()->evaluatePromotion([
            'current_state' => 'dry_run',
            'queue_health_score' => 1.0,
            'queue_health_baseline' => 0.5,
            'worker_fit_known' => false,
            'give_back_risk' => 0.1,
            'give_back_risk_baseline' => 0.5,
        ]);

        $this->assertSame('hold', $result['promotion_decision']);
        $this->assertContains('worker_fit_unknown', $result['blockers']);
    }

    public function test_evaluate_promotion_give_back_risk_increased_blocks(): void
    {
        $result = $this->orchestrator()->evaluatePromotion([
            'current_state' => 'dry_run',
            'queue_health_score' => 1.0,
            'queue_health_baseline' => 0.5,
            'worker_fit_known' => true,
            'give_back_risk' => 0.9,
            'give_back_risk_baseline' => 0.5,
        ]);

        $this->assertSame('hold', $result['promotion_decision']);
        $this->assertContains('give_back_risk_increased', $result['blockers']);
    }

    // ── Missing evidence ───────────────────────────────────────────────

    public function test_evaluate_promotion_missing_evidence_blocks(): void
    {
        $result = $this->orchestrator()->evaluatePromotion([
            'current_state' => 'dry_run',
        ]);

        $this->assertSame('hold', $result['promotion_decision']);
        $this->assertContains('queue_health_score', $result['missing_evidence']);
        $this->assertContains('queue_health_baseline', $result['missing_evidence']);
        $this->assertContains('give_back_risk', $result['missing_evidence']);
        $this->assertContains('give_back_risk_baseline', $result['missing_evidence']);
    }

    public function test_evaluate_promotion_rejects_unknown_state(): void
    {
        $result = $this->orchestrator()->evaluatePromotion([
            'current_state' => 'bogus',
            'queue_health_score' => 1.0,
            'queue_health_baseline' => 0.5,
            'worker_fit_known' => true,
            'give_back_risk' => 0.1,
            'give_back_risk_baseline' => 0.5,
        ]);

        $this->assertSame('promote', $result['promotion_decision']);
        $this->assertSame('shadow', $result['state']); // defaults to dry_run
    }

    // ── PROMOTION_STATES constant ──────────────────────────────────────

    public function test_promotion_states_are_well_known(): void
    {
        $this->assertSame(
            ['dry_run', 'shadow', 'canary', 'live'],
            AgentControlPlaneRuntimePilotOrchestrator::PROMOTION_STATES,
        );
    }

    // ── run: integration with real deps ────────────────────────────────

    public function test_run_returns_expected_schema(): void
    {
        $result = $this->orchestrator()->run([]);

        $this->assertSame(
            AgentControlPlaneRuntimePilotOrchestrator::SCHEMA_VERSION,
            $result['schema_version'],
        );
        $this->assertSame(
            AgentControlPlaneRuntimePilotOrchestrator::MODE,
            $result['mode'],
        );
        $this->assertArrayHasKey('pilot_hash', $result);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['pilot_hash']);
    }

    public function test_run_includes_all_required_components(): void
    {
        $result = $this->orchestrator()->run([]);

        $requiredKeys = [
            'task_packet', 'claim_lease_simulation', 'scope_lock_plan',
            'evidence_ledger_dry_run', 'continuation_summary',
            'work_product_manifest_plan', 'cost_import_dry_run',
            'multi_agent_parallelism_plan', 'chain_integrity_summary',
            'replay_summary', 'certification_observatory_summary',
            'blockers', 'warnings',
        ];
        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $result, "run() must include: {$key}");
        }
    }

    public function test_run_read_only_guarantees(): void
    {
        $result = $this->orchestrator()->run([]);

        $this->assertTrue($result['read_only']);
        $this->assertTrue($result['runtime_disabled']);
        $this->assertFalse($result['runtime_execution_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['provider_call_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['self_programming_allowed']);
        $this->assertFalse($result['ledger_write_allowed']);
        $this->assertFalse($result['completion_claim_allowed']);
        $this->assertNotEmpty($result['non_execution_guarantees']);
    }

    public function test_run_returns_human_summary(): void
    {
        $result = $this->orchestrator()->run([]);

        $this->assertArrayHasKey('human_summary', $result);
        $this->assertStringContainsString('Runtime pilot', $result['human_summary']);
    }
}
