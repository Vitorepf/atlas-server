<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Evidence;

use App\Services\Ai\Evidence\EvidenceStrengthScorer;
use PHPUnit\Framework\TestCase;

final class EvidenceStrengthScorerTest extends TestCase
{
    public function test_many_doc_only_refs_cannot_reach_strong_without_gate_run_or_test_result(): void
    {
        $refs = array_fill(0, 10, ['kind' => 'doc']);

        $result = (new EvidenceStrengthScorer)->score($refs);

        $this->assertGreaterThan(6, $result['score']);
        $this->assertNotSame('strong', $result['tier']);
        $this->assertContains('gate_run_or_test_result', $result['missing_required_kinds']);
    }

    public function test_mixed_proof_set_reaches_strong_when_score_is_high_enough(): void
    {
        $refs = [
            ['kind' => 'test_result'],
            ['kind' => 'implementation_notes'],
            ['kind' => 'commit_hash'],
        ];

        $result = (new EvidenceStrengthScorer)->score($refs);

        $this->assertSame('strong', $result['tier']);
        $this->assertSame([], $result['missing_required_kinds']);
    }

    public function test_gate_run_plus_implementation_notes_reaches_strong(): void
    {
        $refs = [
            ['kind' => 'gate_run'],
            ['kind' => 'implementation_notes'],
            ['kind' => 'benchmark_weight'],
        ];

        $result = (new EvidenceStrengthScorer)->score($refs);

        $this->assertSame('strong', $result['tier']);
    }

    public function test_missing_required_kinds_lists_absent_proof_categories_deterministically(): void
    {
        $result = (new EvidenceStrengthScorer)->score([['kind' => 'doc']]);

        $this->assertSame(['gate_run_or_test_result', 'implementation_notes'], $result['missing_required_kinds']);
    }

    public function test_missing_required_kinds_empty_when_both_groups_present(): void
    {
        $result = (new EvidenceStrengthScorer)->score([
            ['kind' => 'test_result'],
            ['kind' => 'implementation_notes'],
        ]);

        $this->assertSame([], $result['missing_required_kinds']);
    }
}
