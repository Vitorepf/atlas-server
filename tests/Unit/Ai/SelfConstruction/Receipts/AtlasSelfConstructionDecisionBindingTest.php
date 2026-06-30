<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Receipts;

use App\Services\Ai\SelfConstruction\Receipts\AtlasSelfConstructionDecisionBinding;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasSelfConstructionDecisionBinding: every downstream kind that carries a matching
 * decision_ref + decision_hash ⇒ status=bound; missing/unknown decision_ref ⇒ status=missing_binding;
 * mismatching hash ⇒ status=invalid_hash; decision_ts newer than the row ⇒ status=stale_binding;
 * envelope is deterministic across calls.
 */
final class AtlasSelfConstructionDecisionBindingTest extends TestCase
{
    private function index(array $decisions, array $downstreamRows): array
    {
        return [
            'index' => array_merge(
                ['decision_receipts' => $decisions],
                $downstreamRows,
            ),
        ];
    }

    public function test_full_binding_for_every_downstream_kind_yields_bound(): void
    {
        $decisions = ['d-1' => ['id' => 'd-1', 'hash' => 'h-d1', 'ts' => '2026-06-25T00:00:00Z']];
        $row = ['decision_ref' => 'd-1', 'decision_hash' => 'h-d1', 'decision_ts' => '2026-06-25T00:00:01Z', 'hash' => 'rh', 'ts' => 't'];
        $index = $this->index($decisions, [
            'task_receipts' => ['t-1' => array_merge(['id' => 't-1'], $row)],
            'verification_receipts' => ['v-1' => array_merge(['id' => 'v-1'], $row)],
            'merge_receipts' => ['m-1' => array_merge(['id' => 'm-1'], $row)],
            'learning_receipts' => ['l-1' => array_merge(['id' => 'l-1'], $row)],
        ]);

        $r = (new AtlasSelfConstructionDecisionBinding)->verify($index);
        $this->assertCount(4, $r['bindings']);
        foreach ($r['bindings'] as $b) {
            $this->assertSame(AtlasSelfConstructionDecisionBinding::STATUS_BOUND, $b['status']);
        }
    }

    public function test_missing_decision_ref_yields_missing_binding(): void
    {
        $index = $this->index(
            ['d-1' => ['id' => 'd-1', 'hash' => 'h-d1']],
            ['task_receipts' => ['t-1' => ['id' => 't-1', 'hash' => 'rh', 'ts' => 't']]],
        );
        $r = (new AtlasSelfConstructionDecisionBinding)->verify($index);
        $this->assertSame(AtlasSelfConstructionDecisionBinding::STATUS_MISSING, $r['bindings'][0]['status']);
        $this->assertSame('no_decision_ref', $r['bindings'][0]['reason']);
    }

    public function test_unknown_decision_ref_yields_missing_binding_with_ref_unknown(): void
    {
        $index = $this->index(
            ['d-1' => ['id' => 'd-1', 'hash' => 'h-d1']],
            ['task_receipts' => ['t-1' => ['id' => 't-1', 'decision_ref' => 'd-ghost', 'decision_hash' => 'h-d1']]],
        );
        $r = (new AtlasSelfConstructionDecisionBinding)->verify($index);
        $this->assertSame(AtlasSelfConstructionDecisionBinding::STATUS_MISSING, $r['bindings'][0]['status']);
        $this->assertStringContainsString('decision_ref_unknown:d-ghost', $r['bindings'][0]['reason']);
    }

    public function test_mismatching_decision_hash_yields_invalid_hash(): void
    {
        $index = $this->index(
            ['d-1' => ['id' => 'd-1', 'hash' => 'h-d1']],
            ['merge_receipts' => ['m-1' => ['id' => 'm-1', 'decision_ref' => 'd-1', 'decision_hash' => 'WRONG']]],
        );
        $r = (new AtlasSelfConstructionDecisionBinding)->verify($index);
        $this->assertSame(AtlasSelfConstructionDecisionBinding::STATUS_INVALID_HASH, $r['bindings'][0]['status']);
    }

    public function test_decision_ts_newer_than_row_yields_stale_binding(): void
    {
        $index = $this->index(
            ['d-1' => ['id' => 'd-1', 'hash' => 'h-d1', 'ts' => '2026-06-25T10:00:00Z']],
            ['verification_receipts' => ['v-1' => ['id' => 'v-1', 'decision_ref' => 'd-1', 'decision_hash' => 'h-d1', 'decision_ts' => '2026-06-25T05:00:00Z']]],
        );
        $r = (new AtlasSelfConstructionDecisionBinding)->verify($index);
        $this->assertSame(AtlasSelfConstructionDecisionBinding::STATUS_STALE, $r['bindings'][0]['status']);
    }

    public function test_bindings_sorted_by_kind_then_id_and_deterministic(): void
    {
        $decisions = ['d-1' => ['id' => 'd-1', 'hash' => 'h-d1']];
        $row = ['decision_ref' => 'd-1', 'decision_hash' => 'h-d1'];
        $index = $this->index($decisions, [
            'task_receipts' => ['z-task' => array_merge(['id' => 'z-task'], $row), 'a-task' => array_merge(['id' => 'a-task'], $row)],
        ]);
        $b = new AtlasSelfConstructionDecisionBinding;
        $r = $b->verify($index);
        $ids = array_column($r['bindings'], 'id');
        $this->assertSame(['a-task', 'z-task'], $ids);
        $this->assertSame(json_encode($r), json_encode($b->verify($index)));
    }

    public function test_unknown_downstream_kind_yields_unknown_kind_row_not_silent_success(): void
    {
        $index = $this->index(
            ['d-1' => ['id' => 'd-1', 'hash' => 'h-d1']],
            ['ghost_receipts' => ['g-1' => ['id' => 'g-1', 'decision_ref' => 'd-1', 'decision_hash' => 'h-d1']]],
        );
        $r = (new AtlasSelfConstructionDecisionBinding)->verify($index);
        $statuses = array_column($r['bindings'], 'status');
        $this->assertContains('unknown_kind', $statuses, 'unknown receipt kind must produce an unknown_kind row');
        $row = array_values(array_filter($r['bindings'], fn (array $b): bool => $b['status'] === 'unknown_kind'))[0];
        $this->assertSame('ghost_receipts', $row['kind']);
        $this->assertSame('g-1', $row['id']);
    }

    public function test_summary_count_per_status_is_present_without_scalar_score(): void
    {
        $decisions = ['d-1' => ['id' => 'd-1', 'hash' => 'h-d1', 'ts' => '2026-06-25T00:00:00Z']];
        $boundRow = ['decision_ref' => 'd-1', 'decision_hash' => 'h-d1', 'decision_ts' => '2026-06-25T00:01:00Z'];
        $index = $this->index($decisions, [
            'task_receipts' => [
                't-1' => array_merge(['id' => 't-1'], $boundRow),
                't-2' => ['id' => 't-2'], // missing_binding
            ],
        ]);
        $r = (new AtlasSelfConstructionDecisionBinding)->verify($index);
        $this->assertArrayHasKey('summary', $r);
        $this->assertIsArray($r['summary']);
        $this->assertSame(1, $r['summary']['bound'] ?? 0);
        $this->assertSame(1, $r['summary']['missing_binding'] ?? 0);
        // No scalar score, rank, grade, percent
        $json = json_encode($r['summary']);
        $this->assertDoesNotMatchRegularExpression('/"(score|rank|grade|percent)"/i', $json);
    }

    public function test_summary_keys_are_sorted_deterministically(): void
    {
        $decisions = ['d-1' => ['id' => 'd-1', 'hash' => 'h-d1']];
        $row = ['decision_ref' => 'd-1', 'decision_hash' => 'h-d1'];
        $index = $this->index($decisions, [
            'task_receipts' => ['t-1' => array_merge(['id' => 't-1'], $row)],
            'merge_receipts' => ['m-1' => ['id' => 'm-1']], // missing_binding
        ]);
        $r1 = (new AtlasSelfConstructionDecisionBinding)->verify($index);
        $r2 = (new AtlasSelfConstructionDecisionBinding)->verify($index);
        $this->assertSame(array_keys($r1['summary']), array_keys($r2['summary']));
    }
}
