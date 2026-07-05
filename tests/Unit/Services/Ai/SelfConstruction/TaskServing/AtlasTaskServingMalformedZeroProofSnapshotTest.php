<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\TaskServing;

use App\Services\Ai\SelfConstruction\TaskServing\AtlasTaskServingMalformedZeroProofSnapshot;
use PHPUnit\Framework\TestCase;

final class AtlasTaskServingMalformedZeroProofSnapshotTest extends TestCase
{
    private AtlasTaskServingMalformedZeroProofSnapshot $snapshot;

    protected function setUp(): void
    {
        $this->snapshot = new AtlasTaskServingMalformedZeroProofSnapshot;
    }

    // ── AC: would_block_count zero creates pass proof ──

    public function test_zero_would_block_creates_pass_proof(): void
    {
        $result = $this->snapshot->snapshot([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'malformed_sweep' => [
                'would_block_count' => 0,
                'blocked_count' => 0,
                'inspected_claimable' => 5,
                'would_block' => [],
                'blocked' => [],
            ],
        ]);

        $this->assertSame(AtlasTaskServingMalformedZeroProofSnapshot::PROOF_PASS, $result['proof']);
        $this->assertSame([], $result['reason_summaries']);
        $this->assertSame(5, $result['inspected_claimable']);
    }

    // ── AC: nonzero blockers create fail proof with reason summaries ──

    public function test_nonzero_blockers_create_fail_proof(): void
    {
        $result = $this->snapshot->snapshot([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'malformed_sweep' => [
                'would_block_count' => 2,
                'blocked_count' => 1,
                'inspected_claimable' => 5,
                'would_block' => [
                    ['task_packet_id' => 'pkt-1', 'blocking_deficiencies' => ['missing_objective']],
                    ['task_packet_id' => 'pkt-2', 'blocking_deficiencies' => ['missing_acceptance_criteria', 'not_self_sufficient']],
                ],
                'blocked' => [
                    ['task_packet_id' => 'pkt-3', 'blocking_deficiencies' => ['missing_scope']],
                ],
            ],
        ]);

        $this->assertSame(AtlasTaskServingMalformedZeroProofSnapshot::PROOF_FAIL, $result['proof']);
        $this->assertSame(2, $result['would_block_count']);
        $this->assertSame(1, $result['blocked_count']);
        $this->assertSame(['missing_acceptance_criteria', 'missing_objective', 'missing_scope', 'not_self_sufficient'], $result['reason_summaries']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->snapshot->snapshot([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'malformed_sweep' => ['would_block_count' => 0],
        ]);

        $this->assertSame(AtlasTaskServingMalformedZeroProofSnapshot::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('proof', $result);
        $this->assertArrayHasKey('would_block_count', $result);
        $this->assertArrayHasKey('reason_summaries', $result);
        $this->assertArrayHasKey('compact_hash', $result);
        $this->assertStringStartsWith('mzp_', $result['compact_hash']);
    }

    public function test_result_is_deterministic(): void
    {
        $input = [
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'malformed_sweep' => [
                'would_block_count' => 1,
                'blocked_count' => 0,
                'would_block' => [
                    ['task_packet_id' => 'pkt-1', 'blocking_deficiencies' => ['bad']],
                ],
            ],
        ];

        $a = $this->snapshot->snapshot($input);
        $b = $this->snapshot->snapshot($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
