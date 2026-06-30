<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOrganSprawlReductionPlanner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainOrganSprawlReductionPlannerTest extends TestCase
{
    private AtlasExternalBrainOrganSprawlReductionPlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new AtlasExternalBrainOrganSprawlReductionPlanner;
    }

    private function organ(string $id, array $overrides = []): array
    {
        return array_merge([
            'organ_id'               => $id,
            'capability_labels'      => ['some_capability'],
            'evidence_strength'      => 0.80,
            'consumer_count'         => 3,
            'scaffold_status'        => 'active',
            'line_count'             => 100,
            'has_replacement_owner'  => false,
            'has_test_coverage'      => false,
            'overlap_organs'         => [],
        ], $overrides);
    }

    private function plan(array ...$organs): array
    {
        return $this->planner->plan(['organs' => $organs]);
    }

    private function findEntry(array $result, string $id): array
    {
        foreach ($result['ranked_actions'] as $entry) {
            if ($entry['organ_id'] === $id) {
                return $entry;
            }
        }
        $this->fail("Organ '{$id}' not found in ranked_actions.");
    }

    // ── Schema / required keys ────────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->plan($this->organ('o1'));

        foreach (['schema', 'ranked_actions', 'expected_line_delta', 'capability_preserved_count', 'first_safe_batch', 'required_tests'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainOrganSprawlReductionPlanner::SCHEMA, $result['schema']);
    }

    public function test_each_entry_has_required_keys(): void
    {
        $result = $this->plan($this->organ('o1'));

        foreach (['organ_id', 'action', 'reasons', 'line_delta', 'required_tests'] as $k) {
            $this->assertArrayHasKey($k, $result['ranked_actions'][0]);
        }
    }

    // ── AC2: retire_blocked — wants retirement but missing safety ─────────────

    public function test_retire_blocked_when_missing_replacement(): void
    {
        $result = $this->plan($this->organ('weak', [
            'evidence_strength'     => 0.10, // low → needs retirement
            'has_replacement_owner' => false,
            'has_test_coverage'     => true,
        ]));

        $entry = $this->findEntry($result, 'weak');
        $this->assertSame(AtlasExternalBrainOrganSprawlReductionPlanner::ACTION_RETIRE_BLOCKED, $entry['action']);
        $this->assertStringContainsString('replacement_owner', implode(' ', $entry['reasons']));
    }

    public function test_retire_blocked_when_missing_test_coverage(): void
    {
        $result = $this->plan($this->organ('weak2', [
            'evidence_strength'     => 0.10,
            'has_replacement_owner' => true,
            'has_test_coverage'     => false,
        ]));

        $entry = $this->findEntry($result, 'weak2');
        $this->assertSame(AtlasExternalBrainOrganSprawlReductionPlanner::ACTION_RETIRE_BLOCKED, $entry['action']);
        $this->assertStringContainsString('test_coverage', implode(' ', $entry['reasons']));
    }

    // ── AC2: retire — safety met ──────────────────────────────────────────────

    public function test_low_evidence_with_safety_retires(): void
    {
        $result = $this->plan($this->organ('safe-retire', [
            'evidence_strength'     => 0.10,
            'line_count'            => 150,
            'has_replacement_owner' => true,
            'has_test_coverage'     => true,
        ]));

        $entry = $this->findEntry($result, 'safe-retire');
        $this->assertSame(AtlasExternalBrainOrganSprawlReductionPlanner::ACTION_RETIRE, $entry['action']);
        $this->assertSame(-150, $entry['line_delta']);
    }

    public function test_retired_scaffold_status_retires(): void
    {
        $result = $this->plan($this->organ('old', [
            'scaffold_status'       => 'retired',
            'has_replacement_owner' => true,
            'has_test_coverage'     => true,
        ]));

        $entry = $this->findEntry($result, 'old');
        $this->assertSame(AtlasExternalBrainOrganSprawlReductionPlanner::ACTION_RETIRE, $entry['action']);
        $this->assertStringContainsString('scaffold_status:retired', implode(' ', $entry['reasons']));
    }

    // ── AC2: merge — overlapping organs ──────────────────────────────────────

    public function test_overlapping_organ_with_safety_merges(): void
    {
        $result = $this->plan($this->organ('dup', [
            'overlap_organs'        => ['organ-v2'],
            'line_count'            => 200,
            'has_replacement_owner' => true,
            'has_test_coverage'     => true,
        ]));

        $entry = $this->findEntry($result, 'dup');
        $this->assertSame(AtlasExternalBrainOrganSprawlReductionPlanner::ACTION_MERGE, $entry['action']);
        $this->assertStringContainsString('organ-v2', implode(' ', $entry['reasons']));
        $this->assertSame(-100, $entry['line_delta']); // 50% of 200
    }

    // ── AC2: simplify — oversized + few consumers ─────────────────────────────

    public function test_large_low_consumer_organ_simplifies(): void
    {
        $result = $this->plan($this->organ('bloat', [
            'line_count'     => 400,
            'consumer_count' => 1,
        ]));

        $entry = $this->findEntry($result, 'bloat');
        $this->assertSame(AtlasExternalBrainOrganSprawlReductionPlanner::ACTION_SIMPLIFY, $entry['action']);
        $this->assertLessThan(0, $entry['line_delta']);
    }

    // ── AC2: keep — healthy organ ─────────────────────────────────────────────

    public function test_healthy_organ_is_kept(): void
    {
        $result = $this->plan($this->organ('healthy', [
            'evidence_strength' => 0.90,
            'consumer_count'    => 5,
            'line_count'        => 80,
        ]));

        $entry = $this->findEntry($result, 'healthy');
        $this->assertSame(AtlasExternalBrainOrganSprawlReductionPlanner::ACTION_KEEP, $entry['action']);
        $this->assertSame(0, $entry['line_delta']);
    }

    // ── AC3: retire_blocked emits required_tests ──────────────────────────────

    public function test_retire_blocked_emits_required_tests(): void
    {
        $result = $this->plan($this->organ('blocked', [
            'evidence_strength'  => 0.05,
            'capability_labels'  => ['emit_tasks', 'score_quality'],
        ]));

        $entry = $this->findEntry($result, 'blocked');
        $this->assertNotEmpty($entry['required_tests']);
    }

    // ── AC3: no deletion without replacement — retire_blocked has line_delta 0

    public function test_retire_blocked_has_zero_line_delta(): void
    {
        $result = $this->plan($this->organ('unsafe', [
            'evidence_strength'     => 0.05,
            'line_count'            => 300,
            'has_replacement_owner' => false,
        ]));

        $entry = $this->findEntry($result, 'unsafe');
        $this->assertSame(0, $entry['line_delta']);
    }

    // ── AC4: expected_line_delta sums all deltas ──────────────────────────────

    public function test_expected_line_delta_aggregates(): void
    {
        $result = $this->plan(
            $this->organ('r1', ['evidence_strength' => 0.05, 'line_count' => 100, 'has_replacement_owner' => true, 'has_test_coverage' => true]),
            $this->organ('k1'),
        );

        $this->assertSame(-100, $result['expected_line_delta']);
    }

    // ── AC4: capability_preserved_count counts keep labels ───────────────────

    public function test_capability_preserved_counts_kept_organ_labels(): void
    {
        $result = $this->plan(
            $this->organ('k1', ['capability_labels' => ['cap_a', 'cap_b']]),
            $this->organ('r1', ['evidence_strength' => 0.05, 'has_replacement_owner' => true, 'has_test_coverage' => true]),
        );

        $this->assertSame(2, $result['capability_preserved_count']);
    }

    // ── AC4: first_safe_batch includes retire + simplify ─────────────────────

    public function test_first_safe_batch_includes_retire_and_simplify(): void
    {
        $result = $this->plan(
            $this->organ('r1', ['evidence_strength' => 0.05, 'has_replacement_owner' => true, 'has_test_coverage' => true]),
            $this->organ('s1', ['line_count' => 400, 'consumer_count' => 1]),
            $this->organ('k1'),
        );

        $this->assertContains('r1', $result['first_safe_batch']);
        $this->assertContains('s1', $result['first_safe_batch']);
        $this->assertNotContains('k1', $result['first_safe_batch']);
    }

    // ── AC4: ranked order — retire_blocked first ─────────────────────────────

    public function test_ranked_actions_retire_blocked_comes_before_keep(): void
    {
        $result = $this->plan(
            $this->organ('k1'),
            $this->organ('blocked', ['evidence_strength' => 0.05]),
        );

        $actions = array_column($result['ranked_actions'], 'action');
        $blockedPos = array_search(AtlasExternalBrainOrganSprawlReductionPlanner::ACTION_RETIRE_BLOCKED, $actions, true);
        $keepPos    = array_search(AtlasExternalBrainOrganSprawlReductionPlanner::ACTION_KEEP, $actions, true);

        $this->assertLessThan($keepPos, $blockedPos);
    }

    // ── Empty input ───────────────────────────────────────────────────────────

    public function test_empty_organs_returns_zero_state(): void
    {
        $result = $this->plan();

        $this->assertSame([], $result['ranked_actions']);
        $this->assertSame(0, $result['expected_line_delta']);
        $this->assertSame(0, $result['capability_preserved_count']);
        $this->assertSame([], $result['first_safe_batch']);
        $this->assertSame([], $result['required_tests']);
    }
}
