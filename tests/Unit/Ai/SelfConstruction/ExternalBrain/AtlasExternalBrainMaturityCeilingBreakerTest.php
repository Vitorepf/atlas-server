<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainMaturityCeilingBreaker;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainMaturityCeilingBreakerTest extends TestCase
{
    private function breaker(): AtlasExternalBrainMaturityCeilingBreaker
    {
        return new AtlasExternalBrainMaturityCeilingBreaker;
    }

    private function task(bool $unlocks, float $delta = 0.01): array
    {
        return ['id' => uniqid(), 'unlocks_new_capability' => $unlocks, 'metric_delta' => $delta];
    }

    private function jump(array $overrides = []): array
    {
        return array_merge([
            'name'          => 'self_directed_origination',
            'prerequisites' => ['queue_stable', 'worker_healthy'],
            'blast_radius'  => 0.3,
            'risk_score'    => 0.4,
            'proof_gates'   => ['integration_suite_green', 'no_regression'],
        ], $overrides);
    }

    // ── AC4: output shape ─────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->breaker()->analyze([]);
        $this->assertSame(AtlasExternalBrainMaturityCeilingBreaker::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('ceiling_detected', $r);
        $this->assertArrayHasKey('proposed_jump', $r);
        $this->assertArrayHasKey('prerequisites', $r);
        $this->assertArrayHasKey('proof_gates', $r);
        $this->assertArrayHasKey('rejected_incremental_tasks', $r);
    }

    // ── AC2: ceiling detection ────────────────────────────────────────────────

    public function test_ceiling_detected_when_non_unlocking_count_reaches_threshold(): void
    {
        $tasks = array_fill(0, 5, $this->task(false));
        $r = $this->breaker()->analyze(['recent_tasks' => $tasks, 'stagnation_threshold' => 5]);
        $this->assertTrue($r['ceiling_detected']);
        $this->assertSame(5, $r['ceiling_evidence']['non_unlocking_task_count']);
    }

    public function test_ceiling_not_detected_below_threshold(): void
    {
        $tasks = array_fill(0, 4, $this->task(false));
        $r = $this->breaker()->analyze(['recent_tasks' => $tasks, 'stagnation_threshold' => 5]);
        $this->assertFalse($r['ceiling_detected']);
    }

    public function test_unlocking_tasks_do_not_count_toward_ceiling(): void
    {
        $tasks = [
            $this->task(false), $this->task(false), $this->task(false),
            $this->task(true),  // unlocking — not counted
            $this->task(false),
        ];
        $r = $this->breaker()->analyze(['recent_tasks' => $tasks, 'stagnation_threshold' => 5]);
        $this->assertFalse($r['ceiling_detected']);
        $this->assertSame(4, $r['ceiling_evidence']['non_unlocking_task_count']);
    }

    public function test_default_stagnation_threshold_is_five(): void
    {
        $tasks = array_fill(0, 5, $this->task(false));
        $r = $this->breaker()->analyze(['recent_tasks' => $tasks]); // no threshold key
        $this->assertTrue($r['ceiling_detected']);
        $this->assertSame(5, $r['ceiling_evidence']['stagnation_threshold_used']);
    }

    public function test_rejected_incremental_tasks_equals_non_unlocking_tasks(): void
    {
        $tasks = [$this->task(false), $this->task(true), $this->task(false)];
        $r = $this->breaker()->analyze(['recent_tasks' => $tasks]);
        $this->assertCount(2, $r['rejected_incremental_tasks']);
    }

    // ── AC3: jump qualification ───────────────────────────────────────────────

    public function test_safe_jump_is_proposed_on_ceiling(): void
    {
        $tasks = array_fill(0, 5, $this->task(false));
        $r = $this->breaker()->analyze([
            'recent_tasks'              => $tasks,
            'proposed_capability_jumps' => [$this->jump()],
        ]);
        $this->assertNotNull($r['proposed_jump']);
        $this->assertSame('self_directed_origination', $r['proposed_jump']['name']);
        $this->assertTrue($r['blast_radius_within_bounds']);
        $this->assertTrue($r['risk_within_bounds']);
    }

    public function test_jump_with_excessive_blast_radius_is_rejected(): void
    {
        $tasks = array_fill(0, 5, $this->task(false));
        $r = $this->breaker()->analyze([
            'recent_tasks'              => $tasks,
            'proposed_capability_jumps' => [$this->jump(['blast_radius' => 0.8])],
        ]);
        $this->assertNull($r['proposed_jump']);
        $this->assertCount(1, $r['rejected_jumps']);
        $this->assertContains('blast_radius_exceeds_bound', $r['rejected_jumps'][0]['reasons']);
    }

    public function test_jump_with_excessive_risk_score_is_rejected(): void
    {
        $r = $this->breaker()->analyze([
            'recent_tasks'              => array_fill(0, 5, $this->task(false)),
            'proposed_capability_jumps' => [$this->jump(['risk_score' => 0.9])],
        ]);
        $this->assertNull($r['proposed_jump']);
        $this->assertContains('risk_score_exceeds_bound', $r['rejected_jumps'][0]['reasons']);
    }

    public function test_jump_without_proof_gates_is_rejected(): void
    {
        $r = $this->breaker()->analyze([
            'recent_tasks'              => array_fill(0, 5, $this->task(false)),
            'proposed_capability_jumps' => [$this->jump(['proof_gates' => []])],
        ]);
        $this->assertNull($r['proposed_jump']);
        $this->assertContains('no_proof_gates_defined', $r['rejected_jumps'][0]['reasons']);
    }

    public function test_best_jump_selected_by_lowest_risk_then_blast(): void
    {
        $r = $this->breaker()->analyze([
            'recent_tasks'              => array_fill(0, 5, $this->task(false)),
            'proposed_capability_jumps' => [
                $this->jump(['name' => 'risky',  'risk_score' => 0.55, 'blast_radius' => 0.2]),
                $this->jump(['name' => 'safest', 'risk_score' => 0.30, 'blast_radius' => 0.4]),
                $this->jump(['name' => 'mid',    'risk_score' => 0.30, 'blast_radius' => 0.3]),
            ],
        ]);
        $this->assertSame('mid', $r['proposed_jump']['name']); // lowest risk 0.30, then lowest blast 0.3
    }

    public function test_prerequisites_and_proof_gates_surfaced_from_proposed_jump(): void
    {
        $r = $this->breaker()->analyze([
            'recent_tasks'              => array_fill(0, 5, $this->task(false)),
            'proposed_capability_jumps' => [$this->jump()],
        ]);
        $this->assertSame(['queue_stable', 'worker_healthy'], $r['prerequisites']);
        $this->assertSame(['integration_suite_green', 'no_regression'], $r['proof_gates']);
    }

    public function test_no_proposed_jump_when_no_eligible_jumps(): void
    {
        $r = $this->breaker()->analyze([
            'recent_tasks'              => array_fill(0, 5, $this->task(false)),
            'proposed_capability_jumps' => [],
        ]);
        $this->assertNull($r['proposed_jump']);
        $this->assertEmpty($r['prerequisites']);
        $this->assertEmpty($r['proof_gates']);
    }

    // ── AC1: new ceiling metrics ──────────────────────────────────────────────

    public function test_structural_unlock_rate_in_ceiling_evidence(): void
    {
        // 3 unlocking out of 5 → rate = 0.6
        $tasks = [
            $this->task(true),  $this->task(true),  $this->task(true),
            $this->task(false), $this->task(false),
        ];
        $r = $this->breaker()->analyze(['recent_tasks' => $tasks]);

        $this->assertSame(0.6, $r['ceiling_evidence']['structural_unlock_rate']);
    }

    public function test_repeated_family_rate_computed_from_task_family_field(): void
    {
        // 4 bug-hunt, 1 research → repeated = 4/5 = 0.8
        $tasks = [
            ['unlocks_new_capability' => false, 'task_family' => 'bug-hunt'],
            ['unlocks_new_capability' => false, 'task_family' => 'bug-hunt'],
            ['unlocks_new_capability' => false, 'task_family' => 'bug-hunt'],
            ['unlocks_new_capability' => false, 'task_family' => 'bug-hunt'],
            ['unlocks_new_capability' => false, 'task_family' => 'research'],
        ];
        $r = $this->breaker()->analyze(['recent_tasks' => $tasks]);

        $this->assertSame(0.8, $r['ceiling_evidence']['repeated_family_rate']);
    }

    public function test_saturation_score_is_derived_from_unlock_and_family_rates(): void
    {
        // All non-unlocking (unlock_rate=0.0), all same family (repeated=1.0)
        // saturation = (1.0 + 1.0) / 2 = 1.0
        $tasks = array_fill(0, 3, ['unlocks_new_capability' => false, 'task_family' => 'bug-hunt']);
        $r = $this->breaker()->analyze(['recent_tasks' => $tasks]);

        $this->assertSame(1.0, $r['ceiling_evidence']['saturation_score']);
    }

    public function test_ambition_jump_score_derived_from_risk_and_blast_of_best_jump(): void
    {
        // (1-0.4) * (1-0.3) = 0.6 * 0.7 = 0.42
        $tasks = array_fill(0, 5, $this->task(false));
        $r = $this->breaker()->analyze([
            'recent_tasks'              => $tasks,
            'proposed_capability_jumps' => [$this->jump(['risk_score' => 0.4, 'blast_radius' => 0.3])],
        ]);

        $this->assertSame(0.42, $r['ceiling_evidence']['ambition_jump_score']);
    }

    public function test_ambition_jump_score_zero_when_no_eligible_jump(): void
    {
        $r = $this->breaker()->analyze(['recent_tasks' => array_fill(0, 5, $this->task(false))]);
        $this->assertSame(0.0, $r['ceiling_evidence']['ambition_jump_score']);
    }

    public function test_tasks_without_task_family_do_not_inflate_repeated_family_rate(): void
    {
        // No task_family → repeated_family_rate = 0.0
        $tasks = array_fill(0, 4, $this->task(false));
        $r = $this->breaker()->analyze(['recent_tasks' => $tasks]);

        $this->assertSame(0.0, $r['ceiling_evidence']['repeated_family_rate']);
    }

    // ── AC2: saturation triggers ceiling ──────────────────────────────────────

    public function test_ceiling_detected_by_saturation_without_stagnation_count(): void
    {
        // Only 3 tasks but all same family, all non-unlocking → saturation=1.0 >= 0.60
        $tasks = array_fill(0, 3, ['unlocks_new_capability' => false, 'task_family' => 'bug-hunt']);
        $r = $this->breaker()->analyze(['recent_tasks' => $tasks, 'stagnation_threshold' => 5]);

        $this->assertTrue($r['ceiling_detected']);       // saturation triggered, not stagnation count
        $this->assertSame(3, $r['ceiling_evidence']['non_unlocking_task_count']); // still < threshold
    }

    public function test_saturation_ceiling_proposes_eligible_jump(): void
    {
        $tasks = array_fill(0, 3, ['unlocks_new_capability' => false, 'task_family' => 'bug-hunt']);
        $r = $this->breaker()->analyze([
            'recent_tasks'              => $tasks,
            'stagnation_threshold'      => 5,
            'proposed_capability_jumps' => [$this->jump()],
        ]);

        $this->assertTrue($r['ceiling_detected']);
        $this->assertNotNull($r['proposed_jump']);
        $this->assertSame('self_directed_origination', $r['proposed_jump']['name']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $facts = [
            'recent_tasks'              => [
                ['id' => 'a', 'unlocks_new_capability' => false, 'metric_delta' => 0.01],
                ['id' => 'b', 'unlocks_new_capability' => false, 'metric_delta' => 0.02],
                ['id' => 'c', 'unlocks_new_capability' => false, 'metric_delta' => 0.01],
                ['id' => 'd', 'unlocks_new_capability' => false, 'metric_delta' => 0.01],
                ['id' => 'e', 'unlocks_new_capability' => false, 'metric_delta' => 0.01],
            ],
            'proposed_capability_jumps' => [$this->jump()],
        ];
        $a = $this->breaker()->analyze($facts);
        $b = $this->breaker()->analyze($facts);
        $this->assertSame(json_encode($a), json_encode($b));
    }
}
