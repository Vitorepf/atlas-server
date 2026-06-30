<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainClosedLoopLearningCompletenessVerifier;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainClosedLoopLearningCompletenessVerifierTest extends TestCase
{
    private AtlasExternalBrainClosedLoopLearningCompletenessVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new AtlasExternalBrainClosedLoopLearningCompletenessVerifier;
    }

    private function completeCycle(array $overrides = []): array
    {
        return array_merge([
            'cycle_id'             => 'cycle-1',
            'origination_receipt'  => ['task_packet_id' => 'task-001'],
            'implementation_result' => ['commit_sha' => 'abc123', 'files_committed' => ['app/Foo.php']],
            'runnable_evidence'    => ['command' => './vendor/bin/phpunit tests/FooTest.php', 'outcome' => 'OK (14 tests)'],
            'learning_update'      => ['pattern_family' => 'spec-quality', 'delta' => 0.15],
            'next_batch_constraint' => ['target_entropy_floor' => 0.4, 'required_families' => ['adversarial']],
        ], $overrides);
    }

    private function input(array ...$cycles): array
    {
        return ['cycles' => $cycles];
    }

    // ── Schema / AC4 required keys ────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->verifier->verify($this->input($this->completeCycle()));

        foreach (['schema', 'complete', 'missing_links', 'cycle_receipts', 'next_repair_task_hint'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainClosedLoopLearningCompletenessVerifier::SCHEMA, $result['schema']);
    }

    // ── AC3: complete cycle passes ────────────────────────────────────────────

    public function test_complete_cycle_passes(): void
    {
        $result = $this->verifier->verify($this->input($this->completeCycle()));

        $this->assertTrue($result['complete']);
        $this->assertSame([], $result['missing_links']);
        $this->assertNull($result['next_repair_task_hint']);
    }

    public function test_cycle_receipt_reflects_complete_status(): void
    {
        $result = $this->verifier->verify($this->input($this->completeCycle()));

        $this->assertTrue($result['cycle_receipts'][0]['complete']);
        $this->assertSame([], $result['cycle_receipts'][0]['missing_links']);
    }

    // ── AC2: missing value evidence → incomplete ──────────────────────────────

    public function test_missing_runnable_evidence_is_flagged(): void
    {
        $result = $this->verifier->verify($this->input($this->completeCycle(['runnable_evidence' => null])));

        $this->assertFalse($result['complete']);
        $this->assertContains(
            AtlasExternalBrainClosedLoopLearningCompletenessVerifier::LINK_VALUE_EVIDENCE,
            $result['missing_links'],
        );
    }

    public function test_empty_runnable_evidence_array_is_flagged(): void
    {
        $result = $this->verifier->verify($this->input($this->completeCycle(['runnable_evidence' => []])));

        $this->assertContains(
            AtlasExternalBrainClosedLoopLearningCompletenessVerifier::LINK_VALUE_EVIDENCE,
            $result['missing_links'],
        );
    }

    // ── AC2: missing learning record → incomplete ─────────────────────────────

    public function test_missing_learning_update_is_flagged(): void
    {
        $result = $this->verifier->verify($this->input($this->completeCycle(['learning_update' => null])));

        $this->assertFalse($result['complete']);
        $this->assertContains(
            AtlasExternalBrainClosedLoopLearningCompletenessVerifier::LINK_LEARNING_RECORD,
            $result['missing_links'],
        );
    }

    // ── AC2: missing next-batch constraint → incomplete ───────────────────────

    public function test_missing_next_batch_constraint_is_flagged(): void
    {
        $result = $this->verifier->verify($this->input($this->completeCycle(['next_batch_constraint' => null])));

        $this->assertFalse($result['complete']);
        $this->assertContains(
            AtlasExternalBrainClosedLoopLearningCompletenessVerifier::LINK_NEXT_BATCH_CONSTRAINT,
            $result['missing_links'],
        );
    }

    // ── Other link checks ─────────────────────────────────────────────────────

    public function test_missing_origination_receipt_is_flagged(): void
    {
        $result = $this->verifier->verify($this->input($this->completeCycle(['origination_receipt' => null])));

        $this->assertContains(
            AtlasExternalBrainClosedLoopLearningCompletenessVerifier::LINK_ORIGINATION_RECEIPT,
            $result['missing_links'],
        );
    }

    public function test_missing_implementation_result_is_flagged(): void
    {
        $result = $this->verifier->verify($this->input($this->completeCycle(['implementation_result' => null])));

        $this->assertContains(
            AtlasExternalBrainClosedLoopLearningCompletenessVerifier::LINK_IMPLEMENTATION_RESULT,
            $result['missing_links'],
        );
    }

    // ── repair hint follows chain priority ────────────────────────────────────

    public function test_origination_missing_is_highest_priority_repair_hint(): void
    {
        $result = $this->verifier->verify($this->input($this->completeCycle([
            'origination_receipt'   => null,
            'next_batch_constraint' => null,
        ])));

        $this->assertSame('emit_origination_receipt_for_cycle', $result['next_repair_task_hint']);
    }

    public function test_evidence_repair_hint_when_only_evidence_missing(): void
    {
        $result = $this->verifier->verify($this->input($this->completeCycle(['runnable_evidence' => null])));

        $this->assertSame('attach_runnable_evidence_to_cycle', $result['next_repair_task_hint']);
    }

    // ── Multiple cycles ───────────────────────────────────────────────────────

    public function test_all_cycles_complete_means_overall_complete(): void
    {
        $result = $this->verifier->verify($this->input(
            $this->completeCycle(['cycle_id' => 'a']),
            $this->completeCycle(['cycle_id' => 'b']),
        ));

        $this->assertTrue($result['complete']);
        $this->assertCount(2, $result['cycle_receipts']);
    }

    public function test_one_incomplete_cycle_makes_overall_incomplete(): void
    {
        $result = $this->verifier->verify($this->input(
            $this->completeCycle(['cycle_id' => 'good']),
            $this->completeCycle(['cycle_id' => 'bad', 'learning_update' => null]),
        ));

        $this->assertFalse($result['complete']);
        $this->assertFalse($result['cycle_receipts'][1]['complete']);
        $this->assertTrue($result['cycle_receipts'][0]['complete']);
    }

    // ── Empty input ───────────────────────────────────────────────────────────

    public function test_empty_cycles_is_vacuously_complete(): void
    {
        $result = $this->verifier->verify(['cycles' => []]);

        $this->assertTrue($result['complete']);
        $this->assertSame([], $result['missing_links']);
        $this->assertNull($result['next_repair_task_hint']);
    }
}
