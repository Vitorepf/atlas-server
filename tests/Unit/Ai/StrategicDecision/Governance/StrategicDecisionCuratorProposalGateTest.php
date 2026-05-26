<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\StrategicDecision\Governance;

use App\Services\Ai\StrategicDecision\Governance\StrategicDecisionCuratorProposalGate;
use Tests\TestCase;

final class StrategicDecisionCuratorProposalGateTest extends TestCase
{
    private StrategicDecisionCuratorProposalGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new StrategicDecisionCuratorProposalGate;
    }

    private function compliantQl4(): array
    {
        return [
            'proposal_id' => 'prop-001',
            'qualitative_level' => 4,
            'mutation_class' => 'gate_threshold_change',
            'evidence_refs' => [
                ['kind' => 'simulation', 'ref' => 'sim-2026-05-26'],
            ],
            'rollback_plan' => 'revert via Curator Inbox revert action',
            'simulation_summary' => 'shadow-run delta < 5%',
            'review_required_actors' => ['operator'],
        ];
    }

    private function compliantQl5(): array
    {
        $p = $this->compliantQl4();
        $p['qualitative_level'] = 5;
        $p['review_required_actors'] = ['operator', 'security_reviewer'];

        return $p;
    }

    public function test_queues_compliant_ql4_proposal(): void
    {
        $r = $this->gate->evaluate($this->compliantQl4());
        $this->assertSame('queued_for_review', $r['gate_decision']);
        $this->assertFalse($r['auto_apply_allowed']);
        $this->assertSame('atlas.strategic_decision.curator_proposal_gate.v1', $r['schema_version']);
    }

    public function test_queues_compliant_ql5_with_two_reviewers(): void
    {
        $r = $this->gate->evaluate($this->compliantQl5());
        $this->assertSame('queued_for_review', $r['gate_decision']);
    }

    public function test_blocks_ql5_with_single_reviewer(): void
    {
        $p = $this->compliantQl5();
        $p['review_required_actors'] = ['operator'];
        $r = $this->gate->evaluate($p);
        $this->assertArrayHasKey('review_required_actors', $r['failed_checks']);
    }

    public function test_blocks_invalid_ql_out_of_range(): void
    {
        $p = $this->compliantQl4();
        $p['qualitative_level'] = 7;
        $r = $this->gate->evaluate($p);
        $this->assertArrayHasKey('qualitative_level', $r['failed_checks']);
    }

    public function test_blocks_invalid_mutation_class(): void
    {
        $p = $this->compliantQl4();
        $p['mutation_class'] = 'rogue_mutation';
        $r = $this->gate->evaluate($p);
        $this->assertArrayHasKey('mutation_class', $r['failed_checks']);
    }

    public function test_blocks_when_simulation_evidence_missing(): void
    {
        $p = $this->compliantQl4();
        $p['evidence_refs'] = [['kind' => 'log', 'ref' => 'some-log']];
        $r = $this->gate->evaluate($p);
        $this->assertArrayHasKey('evidence_refs.simulation', $r['failed_checks']);
    }

    public function test_blocks_when_auto_apply_claimed(): void
    {
        $p = $this->compliantQl4();
        $p['auto_apply_claimed'] = true;
        $r = $this->gate->evaluate($p);
        $this->assertArrayHasKey('auto_apply_claimed', $r['failed_checks']);
        $this->assertFalse($r['auto_apply_allowed']);
    }

    public function test_auto_apply_is_always_false_in_envelope(): void
    {
        // Even on a fully-approved proposal, auto_apply is canonically false
        $r = $this->gate->evaluate($this->compliantQl4());
        $this->assertFalse($r['auto_apply_allowed']);
    }

    public function test_envelope_shape_is_stable(): void
    {
        $r = $this->gate->evaluate($this->compliantQl4());
        $this->assertSame([
            'schema_version', 'proposal_id', 'gate_decision', 'qualitative_level',
            'mutation_class', 'passed_checks', 'failed_checks',
            'review_required_actors', 'auto_apply_allowed', 'detail', 'evaluated_at',
        ], array_keys($r));
    }
}
