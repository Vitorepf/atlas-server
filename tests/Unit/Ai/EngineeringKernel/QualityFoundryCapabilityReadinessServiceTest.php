<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\QualityFoundry\QualityFoundryCapabilityReadinessService;
use PHPUnit\Framework\TestCase;

final class QualityFoundryCapabilityReadinessServiceTest extends TestCase
{
    public function test_green_component_checks_still_leave_comparative_proof_pending(): void
    {
        $report = (new QualityFoundryCapabilityReadinessService)->inspect($this->readyInput());

        self::assertSame('ready_for_limited_sandbox', $report['status']);
        self::assertSame('limited_sandbox', $report['rollout_stage']);
        self::assertSame('world_10x_quality_proof_pending', $report['comparative_state']);
        self::assertFalse($report['multiplier_proven']);
        self::assertFalse($report['world_leading']);
        self::assertFalse($report['world_10x_quality_proven']);
        self::assertFalse($report['claim_eligible']);
        self::assertSame(['read_only_snapshot', 'market_shadow', 'limited_sandbox', 'governed_mode_enablement'], $report['rollout_sequence']);
        self::assertFalse($report['governed_mode_enablement_allowed']);
        self::assertSame('tests/Unit/Ai/EngineeringKernel/QualityFoundryCapabilityReadinessServiceTest.php', $report['verification_refs'][0]['path']);
    }

    public function test_unknown_or_stale_critical_facts_disable_promotion(): void
    {
        $input = $this->readyInput();
        $input['unknown_critical_facts'] = ['deploy-runtime'];
        $input['temporal_freshness'] = false;

        $report = (new QualityFoundryCapabilityReadinessService)->inspect($input);

        self::assertSame('blocked', $report['status']);
        self::assertSame('read_only_snapshot', $report['rollout_stage']);
        self::assertContains('unknown_critical_facts', $report['blockers']);
        self::assertContains('temporal_freshness_invalid', $report['blockers']);
        self::assertFalse($report['claim_eligible']);
    }

    public function test_missing_quality_or_route_replay_evidence_is_not_treated_as_zero_loss(): void
    {
        $input = $this->readyInput();
        $input['quality_first_selection'] = false;
        $input['route_replay'] = false;

        $report = (new QualityFoundryCapabilityReadinessService)->inspect($input);

        self::assertSame('blocked', $report['status']);
        self::assertContains('quality_first_selection_invalid', $report['blockers']);
        self::assertContains('route_replay_invalid', $report['blockers']);
        self::assertSame([], $report['unresolved_unknowns']);
    }

    /** @return array<string,mixed> */
    private function readyInput(): array
    {
        return [
            'fact_families' => ['code', 'contract', 'deploy_runtime', 'flag', 'incident', 'ownership', 'outcome', 'performance', 'security', 'docs', 'decision', 'concurrent_work', 'tool_provider'],
            'required_fact_families' => ['code', 'contract', 'deploy_runtime', 'flag', 'incident', 'ownership', 'outcome', 'performance', 'security', 'docs', 'decision', 'concurrent_work', 'tool_provider'],
            'workspace_isolated' => true, 'temporal_freshness' => true,
            'calibration' => ['status' => 'calibrated', 'confidence' => 0.8, 'claim_eligible' => false],
            'snapshot_deterministic' => true, 'unknown_critical_facts' => [],
            'quality_first_selection' => true, 'route_replay' => true, 'no_claim_writer' => true,
            'rivals_evidence_complete' => false,
            'mode_parity' => false,
            'verification_refs' => [['kind' => 'test_ref', 'path' => 'tests/Unit/Ai/EngineeringKernel/QualityFoundryCapabilityReadinessServiceTest.php']],
        ];
    }
}
