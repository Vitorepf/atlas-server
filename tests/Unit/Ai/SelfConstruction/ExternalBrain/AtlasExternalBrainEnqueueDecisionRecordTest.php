<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainEnqueueDecisionRecord;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainEnqueueDecisionRecordTest extends TestCase
{
    private AtlasExternalBrainEnqueueDecisionRecord $recorder;

    protected function setUp(): void
    {
        $this->recorder = new AtlasExternalBrainEnqueueDecisionRecord;
    }

    private function validEnqueue(array $overrides = []): array
    {
        return array_merge([
            'decision'                 => 'enqueue',
            'batch_id'                 => 'batch-001',
            'candidate_count'          => 3,
            'queue_depth'              => 10,
            'queue_pressure'           => 'low',
            'leverage_rationale'       => 'High-leverage unimplemented organ found in scan.',
            'expected_downstream_value' => 'Wires AtlasOriginator to real task pipeline.',
            'risk_level'               => 'low',
            'decision_reason'          => 'target unique, evidence clean, acceptance tests present',
            'validation_evidence'      => [
                'target_uniqueness',
                'collision_check',
                'malformed_sweep_clean',
                'allowed_files_exist',
                'runnable_acceptance_present',
            ],
        ], $overrides);
    }

    // ── Schema / required keys ────────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->recorder->record($this->validEnqueue());

        foreach (['schema', 'accepted', 'decision', 'rejection_reason', 'record'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainEnqueueDecisionRecord::SCHEMA, $result['schema']);
    }

    // ── AC1: valid enqueue includes full record ────────────────────────────────

    public function test_valid_enqueue_is_accepted(): void
    {
        $result = $this->recorder->record($this->validEnqueue());

        $this->assertTrue($result['accepted']);
        $this->assertNull($result['rejection_reason']);
        $this->assertNotNull($result['record']);
    }

    public function test_accepted_record_has_queue_snapshot(): void
    {
        $result = $this->recorder->record($this->validEnqueue([
            'queue_depth'    => 25,
            'queue_pressure' => 'high',
        ]));

        $this->assertArrayHasKey('queue_snapshot', $result['record']);
        $this->assertSame(25, $result['record']['queue_snapshot']['queue_depth']);
        $this->assertSame('high', $result['record']['queue_snapshot']['queue_pressure']);
    }

    public function test_accepted_record_has_leverage_rationale(): void
    {
        $result = $this->recorder->record($this->validEnqueue([
            'leverage_rationale' => 'Fills critical gap in replay court.',
        ]));

        $this->assertSame('Fills critical gap in replay court.', $result['record']['leverage_rationale']);
    }

    public function test_accepted_record_has_expected_downstream_value(): void
    {
        $result = $this->recorder->record($this->validEnqueue([
            'expected_downstream_value' => 'Enables task origination without operator seed.',
        ]));

        $this->assertSame(
            'Enables task origination without operator seed.',
            $result['record']['expected_downstream_value'],
        );
    }

    public function test_accepted_record_has_risk(): void
    {
        $result = $this->recorder->record($this->validEnqueue(['risk_level' => 'medium']));

        $this->assertSame('medium', $result['record']['risk']);
    }

    public function test_accepted_record_has_decision_reason(): void
    {
        $result = $this->recorder->record($this->validEnqueue([
            'decision_reason' => 'clean sweep, unique target, acceptance test present',
        ]));

        $this->assertSame(
            'clean sweep, unique target, acceptance test present',
            $result['record']['decision_reason'],
        );
    }

    public function test_accepted_record_has_validation_evidence(): void
    {
        $evidence = [
            'target_uniqueness', 'collision_check', 'malformed_sweep_clean',
            'allowed_files_exist', 'runnable_acceptance_present',
        ];
        $result = $this->recorder->record($this->validEnqueue(['validation_evidence' => $evidence]));

        $this->assertSame($evidence, $result['record']['validation_evidence']);
    }

    public function test_accepted_record_has_batch_id(): void
    {
        $result = $this->recorder->record($this->validEnqueue(['batch_id' => 'batch-xyz']));
        $this->assertSame('batch-xyz', $result['record']['batch_id']);
    }

    // ── AC2: enqueue refused — missing required evidence ──────────────────────

    public function test_enqueue_refused_when_target_uniqueness_missing(): void
    {
        $result = $this->recorder->record($this->validEnqueue([
            'validation_evidence' => ['collision_check'],
        ]));

        $this->assertFalse($result['accepted']);
        $this->assertStringContainsString('target_uniqueness', $result['rejection_reason']);
        $this->assertNull($result['record']);
    }

    public function test_enqueue_refused_when_collision_check_missing(): void
    {
        $result = $this->recorder->record($this->validEnqueue([
            'validation_evidence' => ['target_uniqueness'],
        ]));

        $this->assertFalse($result['accepted']);
        $this->assertStringContainsString('collision_check', $result['rejection_reason']);
    }

    public function test_enqueue_refused_when_malformed_sweep_clean_missing(): void
    {
        $result = $this->recorder->record($this->validEnqueue([
            'validation_evidence' => ['target_uniqueness', 'collision_check'],
        ]));

        $this->assertFalse($result['accepted']);
        $this->assertStringContainsString('malformed_sweep_clean', $result['rejection_reason']);
    }

    public function test_enqueue_refused_when_allowed_files_exist_missing(): void
    {
        $result = $this->recorder->record($this->validEnqueue([
            'validation_evidence' => [
                'target_uniqueness', 'collision_check', 'malformed_sweep_clean',
            ],
        ]));

        $this->assertFalse($result['accepted']);
        $this->assertStringContainsString('allowed_files_exist', $result['rejection_reason']);
    }

    public function test_enqueue_refused_when_runnable_acceptance_present_missing(): void
    {
        $result = $this->recorder->record($this->validEnqueue([
            'validation_evidence' => [
                'target_uniqueness', 'collision_check', 'malformed_sweep_clean', 'allowed_files_exist',
            ],
        ]));

        $this->assertFalse($result['accepted']);
        $this->assertStringContainsString('runnable_acceptance_present', $result['rejection_reason']);
    }

    // ── AC2: enqueue refused — forbidden evidence present ─────────────────────

    public function test_enqueue_refused_when_malformed_sweep_present(): void
    {
        $result = $this->recorder->record($this->validEnqueue([
            'validation_evidence' => ['target_uniqueness', 'collision_check', 'malformed_sweep'],
        ]));

        $this->assertFalse($result['accepted']);
        $this->assertStringContainsString('malformed_sweep', $result['rejection_reason']);
    }

    public function test_enqueue_refused_when_malformed_sweep_failed_present(): void
    {
        $result = $this->recorder->record($this->validEnqueue([
            'validation_evidence' => ['target_uniqueness', 'collision_check', 'malformed_sweep_failed'],
        ]));

        $this->assertFalse($result['accepted']);
        $this->assertStringContainsString('malformed_sweep_failed', $result['rejection_reason']);
    }

    public function test_enqueue_refused_when_collision_detected(): void
    {
        $result = $this->recorder->record($this->validEnqueue([
            'validation_evidence' => ['target_uniqueness', 'collision_check', 'collision_detected'],
        ]));

        $this->assertFalse($result['accepted']);
        $this->assertStringContainsString('collision_detected', $result['rejection_reason']);
    }

    public function test_enqueue_refused_when_duplicate_target(): void
    {
        $result = $this->recorder->record($this->validEnqueue([
            'validation_evidence' => ['target_uniqueness', 'collision_check', 'duplicate_target'],
        ]));

        $this->assertFalse($result['accepted']);
        $this->assertStringContainsString('duplicate_target', $result['rejection_reason']);
    }

    public function test_enqueue_refused_when_missing_allowed_files(): void
    {
        $result = $this->recorder->record($this->validEnqueue([
            'validation_evidence' => ['target_uniqueness', 'collision_check', 'missing_allowed_files'],
        ]));

        $this->assertFalse($result['accepted']);
        $this->assertStringContainsString('missing_allowed_files', $result['rejection_reason']);
    }

    public function test_enqueue_refused_when_no_runnable_acceptance(): void
    {
        $result = $this->recorder->record($this->validEnqueue([
            'validation_evidence' => ['target_uniqueness', 'collision_check', 'no_runnable_acceptance'],
        ]));

        $this->assertFalse($result['accepted']);
        $this->assertStringContainsString('no_runnable_acceptance', $result['rejection_reason']);
    }

    // ── AC1: defer, consolidate, reject always accepted ───────────────────────

    public function test_defer_decision_is_always_accepted(): void
    {
        $result = $this->recorder->record([
            'decision'           => 'defer',
            'queue_depth'        => 50,
            'queue_pressure'     => 'high',
            'leverage_rationale' => 'Queue too deep.',
            'risk_level'         => 'low',
            'validation_evidence' => [],
        ]);

        $this->assertTrue($result['accepted']);
        $this->assertNotNull($result['record']);
    }

    public function test_consolidate_decision_is_always_accepted(): void
    {
        $result = $this->recorder->record([
            'decision'           => 'consolidate',
            'queue_depth'        => 20,
            'queue_pressure'     => 'medium',
            'leverage_rationale' => 'Merged with sibling task.',
            'risk_level'         => 'low',
            'validation_evidence' => [],
        ]);

        $this->assertTrue($result['accepted']);
    }

    public function test_reject_decision_is_always_accepted(): void
    {
        $result = $this->recorder->record([
            'decision'           => 'reject',
            'leverage_rationale' => 'Proxy task detected.',
            'risk_level'         => 'low',
            'validation_evidence' => [],
        ]);

        $this->assertTrue($result['accepted']);
    }

    // ── decision echoed in result ─────────────────────────────────────────────

    public function test_decision_echoed_in_result(): void
    {
        $result = $this->recorder->record($this->validEnqueue(['decision' => 'enqueue']));

        $this->assertSame('enqueue', $result['decision']);
    }
}
