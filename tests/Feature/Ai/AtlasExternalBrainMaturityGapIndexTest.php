<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainMaturityGapIndex;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainMaturityGapIndexTest extends TestCase
{
    private function index(): AtlasExternalBrainMaturityGapIndex
    {
        return new AtlasExternalBrainMaturityGapIndex;
    }

    private function dim(string $name, float $leverage, array $signals, string $family = 'gate_impl', array $extra = []): array
    {
        return array_merge([
            'dimension'                 => $name,
            'leverage'                  => $leverage,
            'required_evidence_signals' => $signals,
            'task_family'               => $family,
        ], $extra);
    }

    // ── AC1: low-scoring dimensions become prioritized gaps with evidence_source and next_leverage ─

    public function test_dimension_with_no_proven_signals_becomes_gap(): void
    {
        $rubric = [$this->dim('autonomy', 0.9, ['auto_test_green', 'auto_runtime_live'])];

        $r = $this->index()->compute($rubric, ['proven_evidence' => []]);

        $this->assertCount(1, $r['gaps']);
        $this->assertSame('autonomy', $r['gaps'][0]['dimension']);
    }

    public function test_gap_includes_evidence_source_of_proven_signals(): void
    {
        $rubric = [$this->dim('coverage', 0.7, ['sig_a', 'sig_b', 'sig_c'])];

        $r = $this->index()->compute($rubric, ['proven_evidence' => ['sig_a']]);

        $gap = $r['gaps'][0];
        $this->assertArrayHasKey('evidence_source', $gap);
        $this->assertContains('sig_a', $gap['evidence_source']);
        $this->assertNotContains('sig_b', $gap['evidence_source']);
    }

    public function test_gap_includes_next_leverage_string(): void
    {
        $rubric = [$this->dim('wiring', 0.8, ['wire_test_green'])];

        $r = $this->index()->compute($rubric, ['proven_evidence' => []]);

        $gap = $r['gaps'][0];
        $this->assertArrayHasKey('next_leverage', $gap);
        $this->assertNotEmpty($gap['next_leverage']);
        $this->assertIsString($gap['next_leverage']);
    }

    public function test_high_leverage_gap_appears_before_low_leverage(): void
    {
        $rubric = [
            $this->dim('low',  0.3, ['low_sig']),
            $this->dim('high', 0.9, ['high_sig']),
        ];

        $r = $this->index()->compute($rubric, ['proven_evidence' => []]);

        $this->assertSame('high', $r['gaps'][0]['dimension']);
        $this->assertSame('low',  $r['gaps'][1]['dimension']);
    }

    public function test_gap_missing_evidence_lists_unprovable_signals(): void
    {
        $rubric = [$this->dim('impl', 0.6, ['sig_x', 'sig_y'])];

        $r = $this->index()->compute($rubric, ['proven_evidence' => ['sig_x']]);

        $this->assertContains('sig_y', $r['gaps'][0]['missing_evidence']);
        $this->assertNotContains('sig_x', $r['gaps'][0]['missing_evidence']);
    }

    // ── AC2: complete dimensions are separated and cannot be requeued ─────────

    public function test_fully_proven_dimension_goes_to_complete_not_gaps(): void
    {
        $rubric = [$this->dim('done', 0.9, ['sig_done'])];

        $r = $this->index()->compute($rubric, ['proven_evidence' => ['sig_done']]);

        $this->assertSame([], $r['gaps']);
        $this->assertContains('done', $r['complete_dimensions']);
    }

    public function test_complete_dimension_does_not_appear_in_gaps(): void
    {
        $rubric = [
            $this->dim('done',  0.9, ['proven_sig']),
            $this->dim('open',  0.5, ['missing_sig']),
        ];

        $r = $this->index()->compute($rubric, ['proven_evidence' => ['proven_sig']]);

        $gapNames = array_column($r['gaps'], 'dimension');
        $this->assertNotContains('done', $gapNames);
        $this->assertContains('open', $gapNames);
        $this->assertContains('done', $r['complete_dimensions']);
    }

    public function test_queue_activity_alone_does_not_make_dimension_complete(): void
    {
        $rubric = [$this->dim('queued', 0.8, ['needs_proof'])];

        $r = $this->index()->compute($rubric, [
            'proven_evidence' => [],
            'queue_counts'    => ['queued' => 5],
        ]);

        $this->assertCount(1, $r['gaps']);
        $this->assertSame('queued', $r['gaps'][0]['dimension']);
        $this->assertNotContains('queued', $r['complete_dimensions']);
    }

    // ── AC3: gaps include dependency_chain or unblock_hint when control-plane exposes dependencies ─

    public function test_dependency_chain_populated_from_control_plane_dependencies(): void
    {
        $rubric = [$this->dim('merge-gate', 0.85, ['gate_sig'])];

        $r = $this->index()->compute($rubric, [
            'proven_evidence' => [],
            'dependencies'    => ['merge-gate' => ['wiring', 'cert']],
        ]);

        $gap = $r['gaps'][0];
        $this->assertArrayHasKey('dependency_chain', $gap);
        $this->assertContains('wiring', $gap['dependency_chain']);
        $this->assertContains('cert', $gap['dependency_chain']);
    }

    public function test_unblock_hint_non_empty_when_dependency_chain_exists(): void
    {
        $rubric = [$this->dim('final-gate', 0.9, ['final_sig'])];

        $r = $this->index()->compute($rubric, [
            'proven_evidence' => [],
            'dependencies'    => ['final-gate' => ['upstream-a']],
        ]);

        $gap = $r['gaps'][0];
        $this->assertNotEmpty($gap['unblock_hint']);
        $this->assertStringContainsString('upstream-a', $gap['unblock_hint']);
    }

    public function test_dependency_chain_empty_when_no_dependencies_in_snapshot(): void
    {
        $rubric = [$this->dim('standalone', 0.7, ['some_sig'])];

        $r = $this->index()->compute($rubric, ['proven_evidence' => []]);

        $this->assertSame([], $r['gaps'][0]['dependency_chain']);
        $this->assertSame('', $r['gaps'][0]['unblock_hint']);
    }

    // ── AC4: pure and deterministic ───────────────────────────────────────────

    public function test_compute_is_deterministic(): void
    {
        $rubric = [
            $this->dim('alpha', 0.9, ['a_sig', 'b_sig']),
            $this->dim('beta',  0.5, ['c_sig']),
        ];
        $snapshot = [
            'proven_evidence' => ['a_sig'],
            'queue_counts'    => ['beta' => 2],
            'dependencies'    => ['alpha' => ['beta']],
        ];

        $a = $this->index()->compute($rubric, $snapshot);
        $b = $this->index()->compute($rubric, $snapshot);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
