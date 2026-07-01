<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\TerminalLoopProof;

use App\Services\Ai\SelfConstruction\TerminalLoopProof\TerminalLoopProofReadinessMatrixBuilder;
use Tests\TestCase;

/**
 * Focused contract test: proves all_true is true only when every row's required invariants
 * are satisfied AND every row carries at least one valid sha256 evidence hash, and that
 * next_repair_focus prioritizes missing_invariants over missing_evidence_hashes.
 */
final class TerminalLoopProofReadinessMatrixBuilderTest extends TestCase
{
    private function allInvariantIds(): array
    {
        $matrix = TerminalLoopProofReadinessMatrixBuilder::operationalReadinessMatrix([], []);
        $ids = [];
        foreach ($matrix['rows'] as $row) {
            foreach ($row['required_invariants'] as $inv) {
                $ids[] = $inv;
            }
        }

        return $ids;
    }

    public function test_all_green_readiness_requires_every_invariant_and_every_row_hash(): void
    {
        $invariants = array_fill_keys($this->allInvariantIds(), true);
        $hashes = [
            'auto_replenishment_hash' => str_repeat('a', 64),
            'one_shot_packet_hash' => str_repeat('a', 64),
            'completion_evidence_validation_hash' => str_repeat('a', 64),
            'recovery_resume_proof_hash' => str_repeat('a', 64),
            'validation_rejection_proof_hash' => str_repeat('a', 64),
            'fleet_concurrency_proof_hash' => str_repeat('a', 64),
            'partial_supply_launch_gate_proof_hash' => str_repeat('a', 64),
            'partial_supply_bootstrap_gate_proof_hash' => str_repeat('a', 64),
            'resume_packet_hash' => str_repeat('a', 64),
            'post_cycle_cycle_supervisor_hash' => str_repeat('a', 64),
            'post_cycle_end_to_end_contract_hash' => str_repeat('a', 64),
            'post_cycle_health_digest_hash' => str_repeat('a', 64),
        ];

        $matrix = TerminalLoopProofReadinessMatrixBuilder::operationalReadinessMatrix($invariants, $hashes);

        self::assertTrue($matrix['all_true']);
        self::assertSame(0, $matrix['failed_row_count']);
        self::assertSame($matrix['row_count'], $matrix['passed_row_count']);
        self::assertSame([], $matrix['failed_rows']);
        self::assertSame('', $matrix['first_failed_row']);
        self::assertSame('', $matrix['next_repair_focus']);
    }

    public function test_all_green_becomes_false_when_a_single_invariant_is_missing(): void
    {
        $invariants = array_fill_keys($this->allInvariantIds(), true);
        unset($invariants['claim_acquired_active_lease']);
        $hashes = ['auto_replenishment_hash' => str_repeat('a', 64), 'one_shot_packet_hash' => str_repeat('a', 64)];

        $matrix = TerminalLoopProofReadinessMatrixBuilder::operationalReadinessMatrix($invariants, $hashes);

        self::assertFalse($matrix['all_true']);
    }

    public function test_all_green_becomes_false_when_a_single_row_has_no_valid_hash(): void
    {
        $invariants = array_fill_keys($this->allInvariantIds(), true);
        // Every row has its required invariant true, but no hashes are supplied at all.
        $matrix = TerminalLoopProofReadinessMatrixBuilder::operationalReadinessMatrix($invariants, []);

        self::assertFalse($matrix['all_true']);
        self::assertSame($matrix['row_count'], $matrix['failed_row_count']);
    }

    public function test_next_repair_focus_prioritizes_missing_invariants_over_missing_evidence(): void
    {
        // First row's invariants are entirely missing AND it also lacks evidence hashes —
        // missing_invariants must win the priority race.
        $matrix = TerminalLoopProofReadinessMatrixBuilder::operationalReadinessMatrix([], []);

        self::assertSame(
            'auto_replenishment_claim_and_one_shot_packet:missing_invariants',
            $matrix['next_repair_focus'],
        );
    }

    public function test_next_repair_focus_reports_missing_evidence_hashes_when_invariants_all_satisfied(): void
    {
        $invariants = [
            'before_digest_started_with_empty_lane' => true,
            'auto_replenishment_generated_task' => true,
            'bootstrap_ready_for_worker' => true,
            'one_shot_worker_packet_ready' => true,
            'claim_acquired_active_lease' => true,
        ];

        $matrix = TerminalLoopProofReadinessMatrixBuilder::operationalReadinessMatrix($invariants, []);

        self::assertSame(
            'auto_replenishment_claim_and_one_shot_packet:missing_evidence_hashes',
            $matrix['next_repair_focus'],
        );
    }

    public function test_invalid_hashes_are_filtered_and_do_not_satisfy_a_row(): void
    {
        $invariants = [
            'before_digest_started_with_empty_lane' => true,
            'auto_replenishment_generated_task' => true,
            'bootstrap_ready_for_worker' => true,
            'one_shot_worker_packet_ready' => true,
            'claim_acquired_active_lease' => true,
        ];
        $hashes = [
            'auto_replenishment_hash' => 'not-a-valid-hash',
            'one_shot_packet_hash' => '',
        ];

        $matrix = TerminalLoopProofReadinessMatrixBuilder::operationalReadinessMatrix($invariants, $hashes);
        $firstRow = $matrix['rows'][0];

        self::assertFalse($firstRow['passed']);
        self::assertSame([], $firstRow['evidence_hashes']);
        self::assertContains('auto_replenishment_claim_and_one_shot_packet', $matrix['missing_evidence_rows']);
    }
}
