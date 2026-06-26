<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\TerminalLoopProof\TerminalLoopProofReadinessMatrixBuilder;
use Tests\TestCase;

class TerminalLoopProofReadinessMatrixBuilderTest extends TestCase
{
    public function test_matrix_row_passes_when_invariants_and_hashes_present(): void
    {
        $row = TerminalLoopProofReadinessMatrixBuilder::matrixRow(
            'row_id',
            ['inv_1', 'inv_2'],
            ['inv_1' => true, 'inv_2' => true],
            [str_repeat('a', 64)],
        );

        self::assertTrue($row['passed']);
        self::assertSame([], $row['missing_invariants']);
        self::assertCount(1, $row['evidence_hashes']);
    }

    public function test_matrix_row_fails_when_inv_missing(): void
    {
        $row = TerminalLoopProofReadinessMatrixBuilder::matrixRow(
            'row_id',
            ['inv_1', 'inv_2'],
            ['inv_1' => true],
            [str_repeat('a', 64)],
        );

        self::assertFalse($row['passed']);
        self::assertSame(['inv_2'], $row['missing_invariants']);
    }

    public function test_matrix_row_fails_when_no_valid_hashes(): void
    {
        $row = TerminalLoopProofReadinessMatrixBuilder::matrixRow(
            'row_id',
            ['inv_1'],
            ['inv_1' => true],
            [],
        );

        self::assertFalse($row['passed']);
        self::assertSame([], $row['evidence_hashes']);
    }

    public function test_matrix_row_filters_invalid_hashes(): void
    {
        $row = TerminalLoopProofReadinessMatrixBuilder::matrixRow(
            'row_id',
            ['inv_1'],
            ['inv_1' => true],
            ['not-hex', '', str_repeat('b', 64)],
        );

        self::assertCount(1, $row['evidence_hashes']);
        self::assertTrue($row['passed']);
    }

    public function test_matrix_row_id_preserved(): void
    {
        $row = TerminalLoopProofReadinessMatrixBuilder::matrixRow('my_id', [], [], []);

        self::assertSame('my_id', $row['id']);
    }

    public function test_operational_readiness_matrix_returns_all_rows(): void
    {
        $matrix = TerminalLoopProofReadinessMatrixBuilder::operationalReadinessMatrix([], []);

        self::assertSame(11, $matrix['row_count']);
        self::assertSame(0, $matrix['passed_row_count']);
        self::assertSame(11, $matrix['failed_row_count']);
        self::assertFalse($matrix['all_true']);
        self::assertCount(11, $matrix['rows']);
    }

    public function test_operational_readiness_matrix_schema_version(): void
    {
        $matrix = TerminalLoopProofReadinessMatrixBuilder::operationalReadinessMatrix([], []);

        self::assertSame('atlas.self_construction.agent_control_plane_terminal_loop_operational_readiness_matrix.v1', $matrix['schema_version']);
    }

    public function test_operational_readiness_matrix_failed_rows(): void
    {
        $matrix = TerminalLoopProofReadinessMatrixBuilder::operationalReadinessMatrix([], []);
        $failed = $matrix['failed_rows'];

        self::assertCount(11, $failed);
    }

    public function test_operational_readiness_matrix_with_some_invariants(): void
    {
        $invariants = [
            'before_digest_started_with_empty_lane' => true,
            'auto_replenishment_generated_task' => true,
            'bootstrap_ready_for_worker' => true,
            'one_shot_worker_packet_ready' => true,
            'claim_acquired_active_lease' => true,
        ];
        $hashes = [
            'auto_replenishment_hash' => str_repeat('a', 64),
            'one_shot_packet_hash' => str_repeat('b', 64),
        ];

        $matrix = TerminalLoopProofReadinessMatrixBuilder::operationalReadinessMatrix($invariants, $hashes);

        $firstRow = $matrix['rows'][0];
        self::assertSame('auto_replenishment_claim_and_one_shot_packet', $firstRow['id']);
        self::assertTrue($firstRow['passed']);
        self::assertSame(1, $matrix['passed_row_count']);
        self::assertSame(10, $matrix['failed_row_count']);
    }
}