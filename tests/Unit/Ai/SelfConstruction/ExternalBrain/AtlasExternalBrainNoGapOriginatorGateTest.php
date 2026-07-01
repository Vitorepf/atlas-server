<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainNoGapOriginatorGate;
use Tests\TestCase;

final class AtlasExternalBrainNoGapOriginatorGateTest extends TestCase
{
    private function gate(): AtlasExternalBrainNoGapOriginatorGate
    {
        return new AtlasExternalBrainNoGapOriginatorGate;
    }

    private function validProposal(array $overrides = []): array
    {
        return array_merge([
            'organ_id' => 'FooPlanner',
            'organ_kind' => 'planner',
            'gap_closed' => 'no ranked hotspot map exists for the compression system',
            'downstream_consumer' => 'AtlasExternalBrainComplexityDebtBurnDownPlanner',
            'proof_gate' => 'php artisan test tests/Unit/.../FooPlannerTest.php',
            'compounding_effect' => 'future waves target real debt instead of vague cleanup instincts',
        ], $overrides);
    }

    public function test_schema_present(): void
    {
        $r = $this->gate()->evaluate($this->validProposal());
        $this->assertSame(AtlasExternalBrainNoGapOriginatorGate::SCHEMA, $r['schema']);
    }

    public function test_valid_proposal_is_seedable_and_preserves_metadata(): void
    {
        $r = $this->gate()->evaluate($this->validProposal());

        $this->assertTrue($r['seedable']);
        $this->assertSame([], $r['blockers']);
        $this->assertSame('no ranked hotspot map exists for the compression system', $r['metadata']['gap_closed']);
        $this->assertSame('AtlasExternalBrainComplexityDebtBurnDownPlanner', $r['metadata']['downstream_consumer']);
        $this->assertSame('php artisan test tests/Unit/.../FooPlannerTest.php', $r['metadata']['proof_gate']);
        $this->assertSame('future waves target real debt instead of vague cleanup instincts', $r['metadata']['compounding_effect']);
    }

    // ── missing mandatory metadata rejects ───────────────────────────────────

    public function test_missing_gap_closed_is_rejected(): void
    {
        $r = $this->gate()->evaluate($this->validProposal(['gap_closed' => '']));

        $this->assertFalse($r['seedable']);
        $this->assertContains('missing_gap_closed', $r['blockers']);
    }

    public function test_missing_downstream_consumer_is_rejected(): void
    {
        $r = $this->gate()->evaluate($this->validProposal(['downstream_consumer' => '']));

        $this->assertFalse($r['seedable']);
        $this->assertContains('missing_downstream_consumer', $r['blockers']);
    }

    public function test_missing_proof_gate_is_rejected(): void
    {
        $r = $this->gate()->evaluate($this->validProposal(['proof_gate' => '']));

        $this->assertFalse($r['seedable']);
        $this->assertContains('missing_proof_gate', $r['blockers']);
    }

    public function test_missing_compounding_effect_is_rejected(): void
    {
        $r = $this->gate()->evaluate($this->validProposal(['compounding_effect' => '']));

        $this->assertFalse($r['seedable']);
        $this->assertContains('missing_compounding_effect', $r['blockers']);
    }

    public function test_all_fields_absent_lists_every_missing_blocker(): void
    {
        $r = $this->gate()->evaluate(['organ_id' => 'Bare', 'organ_kind' => 'planner']);

        $this->assertFalse($r['seedable']);
        $this->assertContains('missing_gap_closed', $r['blockers']);
        $this->assertContains('missing_downstream_consumer', $r['blockers']);
        $this->assertContains('missing_proof_gate', $r['blockers']);
        $this->assertContains('missing_compounding_effect', $r['blockers']);
    }

    // ── detector/report without a real consumer is a detached organ ──────────

    public function test_detector_without_consumer_is_rejected_as_detached_organ(): void
    {
        $r = $this->gate()->evaluate($this->validProposal([
            'organ_kind' => 'detector',
            'downstream_consumer' => '',
        ]));

        $this->assertFalse($r['seedable']);
        $this->assertContains('detached_organ', $r['blockers']);
    }

    public function test_report_without_consumer_is_rejected_as_detached_organ(): void
    {
        $r = $this->gate()->evaluate($this->validProposal([
            'organ_kind' => 'report',
            'downstream_consumer' => '',
        ]));

        $this->assertFalse($r['seedable']);
        $this->assertContains('detached_organ', $r['blockers']);
    }

    public function test_detector_consuming_itself_is_rejected_as_detached_organ(): void
    {
        $r = $this->gate()->evaluate($this->validProposal([
            'organ_id' => 'SelfDetector',
            'organ_kind' => 'detector',
            'downstream_consumer' => 'SelfDetector',
        ]));

        $this->assertFalse($r['seedable']);
        $this->assertContains('detached_organ', $r['blockers']);
    }

    public function test_detector_with_real_downstream_consumer_is_not_detached(): void
    {
        $r = $this->gate()->evaluate($this->validProposal([
            'organ_kind' => 'detector',
            'downstream_consumer' => 'AtlasExternalBrainQueueGapRepairPlanner',
        ]));

        $this->assertTrue($r['seedable']);
        $this->assertNotContains('detached_organ', $r['blockers']);
    }

    public function test_non_detector_organ_kind_is_never_flagged_detached(): void
    {
        $r = $this->gate()->evaluate($this->validProposal([
            'organ_kind' => 'planner',
            'downstream_consumer' => 'SomeConsumer',
        ]));

        $this->assertNotContains('detached_organ', $r['blockers']);
    }

    // ── originator_gate_matrix ────────────────────────────────────────────────

    public function test_originator_gate_matrix_has_all_five_checks(): void
    {
        $r = $this->gate()->evaluate($this->validProposal());

        $checks = array_column($r['originator_gate_matrix'], 'check');
        foreach (['gap_closed_present', 'downstream_consumer_present', 'proof_gate_present', 'compounding_effect_present', 'not_detached_organ'] as $expected) {
            $this->assertContains($expected, $checks);
        }
    }

    public function test_originator_gate_matrix_reflects_pass_fail_state(): void
    {
        $r = $this->gate()->evaluate($this->validProposal(['gap_closed' => '']));

        $entry = array_values(array_filter($r['originator_gate_matrix'], fn ($e) => $e['check'] === 'gap_closed_present'))[0];
        $this->assertFalse($entry['passed']);
    }

    // ── determinism ───────────────────────────────────────────────────────────

    public function test_evaluate_is_deterministic(): void
    {
        $proposal = $this->validProposal();
        $this->assertSame(
            json_encode($this->gate()->evaluate($proposal)),
            json_encode($this->gate()->evaluate($proposal)),
        );
    }

    public function test_rejected_proposal_still_preserves_metadata_for_task_fabric(): void
    {
        $r = $this->gate()->evaluate($this->validProposal(['proof_gate' => '']));

        $this->assertFalse($r['seedable']);
        $this->assertSame('no ranked hotspot map exists for the compression system', $r['metadata']['gap_closed']);
        $this->assertSame('', $r['metadata']['proof_gate']);
    }
}
