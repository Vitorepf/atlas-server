<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Cortex;

use App\Services\Ai\SelfConstruction\Cortex\AtlasSelfConstructionCortexRiskGapLens;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasSelfConstructionCortexRiskGapLens: clean facts ⇒ no gaps; every documented risk class
 * triggers when its input fact is present; envelope carries no scalar score / rank / hype field.
 */
final class AtlasSelfConstructionCortexRiskGapLensTest extends TestCase
{
    public function test_clean_posture_yields_no_gaps(): void
    {
        $r = (new AtlasSelfConstructionCortexRiskGapLens)->project([
            'source_inventory' => ['blockers' => []],
            'verification' => ['server_side_green' => true],
            'merge' => ['posture' => 'safe'],
            'queue_health' => ['malformed_count' => 0, 'repeated_give_back_count' => 0],
            'sweep_health' => ['coverage_unknown' => false],
            'knowledge_sync' => ['conformant' => true, 'blockers' => []],
        ]);
        $this->assertSame([], $r['gaps']);
    }

    public function test_stale_context_gap_triggered_by_missing_required_source(): void
    {
        $r = (new AtlasSelfConstructionCortexRiskGapLens)->project([
            'source_inventory' => ['blockers' => ['missing_required_source:memory']],
        ]);
        $classes = array_column($r['gaps'], 'class');
        $this->assertContains(AtlasSelfConstructionCortexRiskGapLens::GAP_STALE_CONTEXT, $classes);
    }

    public function test_missing_receipts_gap_triggered_by_red_verification(): void
    {
        $r = (new AtlasSelfConstructionCortexRiskGapLens)->project([
            'verification' => ['server_side_green' => false],
        ]);
        $classes = array_column($r['gaps'], 'class');
        $this->assertContains(AtlasSelfConstructionCortexRiskGapLens::GAP_MISSING_RECEIPTS, $classes);
    }

    public function test_unsafe_merge_posture_gap_triggered(): void
    {
        $r = (new AtlasSelfConstructionCortexRiskGapLens)->project([
            'merge' => ['posture' => 'unsafe'],
        ]);
        $classes = array_column($r['gaps'], 'class');
        $this->assertContains(AtlasSelfConstructionCortexRiskGapLens::GAP_UNSAFE_MERGE, $classes);
    }

    public function test_malformed_queue_gap_triggered_by_positive_count(): void
    {
        $r = (new AtlasSelfConstructionCortexRiskGapLens)->project([
            'queue_health' => ['malformed_count' => 5],
        ]);
        $classes = array_column($r['gaps'], 'class');
        $this->assertContains(AtlasSelfConstructionCortexRiskGapLens::GAP_MALFORMED_QUEUE, $classes);
    }

    public function test_unproved_runtime_gap_triggered_by_unknown_coverage(): void
    {
        $r1 = (new AtlasSelfConstructionCortexRiskGapLens)->project([
            'sweep_health' => ['coverage_unknown' => true],
        ]);
        $this->assertContains(AtlasSelfConstructionCortexRiskGapLens::GAP_UNPROVED_RUNTIME, array_column($r1['gaps'], 'class'));
    }

    public function test_repeated_give_back_is_its_own_dedicated_gap_class(): void
    {
        $r = (new AtlasSelfConstructionCortexRiskGapLens)->project([
            'queue_health' => ['repeated_give_back_count' => 4],
        ]);
        $classes = array_column($r['gaps'], 'class');
        $this->assertContains(AtlasSelfConstructionCortexRiskGapLens::GAP_REPEATED_GIVE_BACK, $classes);
        $this->assertNotContains(AtlasSelfConstructionCortexRiskGapLens::GAP_UNPROVED_RUNTIME, $classes,
            'repeated give_back must not also trigger the unrelated unproved_runtime_path class');
    }

    public function test_weak_worker_outcomes_gap_triggered_by_weak_flag(): void
    {
        $r = (new AtlasSelfConstructionCortexRiskGapLens)->project([
            'worker_outcomes' => ['weak' => true],
        ]);
        $this->assertContains(AtlasSelfConstructionCortexRiskGapLens::GAP_WEAK_WORKER_OUTCOMES, array_column($r['gaps'], 'class'));
    }

    public function test_weak_worker_outcomes_gap_triggered_by_weak_workers_list(): void
    {
        $r = (new AtlasSelfConstructionCortexRiskGapLens)->project([
            'worker_outcomes' => ['weak_workers' => ['codex-1']],
        ]);
        $byClass = array_column($r['gaps'], null, 'class');
        $this->assertArrayHasKey(AtlasSelfConstructionCortexRiskGapLens::GAP_WEAK_WORKER_OUTCOMES, $byClass);
        $this->assertSame(['codex-1'], $byClass[AtlasSelfConstructionCortexRiskGapLens::GAP_WEAK_WORKER_OUTCOMES]['evidence']['weak_workers']);
    }

    public function test_stale_code_index_gap_triggered(): void
    {
        $r = (new AtlasSelfConstructionCortexRiskGapLens)->project([
            'code_index' => ['stale' => true],
        ]);
        $this->assertContains(AtlasSelfConstructionCortexRiskGapLens::GAP_STALE_CODE_INDEX, array_column($r['gaps'], 'class'));
    }

    public function test_project_lane_leak_gap_triggered(): void
    {
        $r = (new AtlasSelfConstructionCortexRiskGapLens)->project([
            'lane_governance' => ['leak_detected' => true, 'leaked_paths' => ['app/Foo.php']],
        ]);
        $byClass = array_column($r['gaps'], null, 'class');
        $this->assertArrayHasKey(AtlasSelfConstructionCortexRiskGapLens::GAP_PROJECT_LANE_LEAK, $byClass);
        $this->assertSame(['app/Foo.php'], $byClass[AtlasSelfConstructionCortexRiskGapLens::GAP_PROJECT_LANE_LEAK]['evidence']['leaked_paths']);
    }

    // ── repeated give_back / weak worker outcomes point to learning/maestro repair ──

    public function test_repeated_give_back_hints_point_to_maestro_repair_not_generic_task_creation(): void
    {
        $r = (new AtlasSelfConstructionCortexRiskGapLens)->project([
            'queue_health' => ['repeated_give_back_count' => 5],
        ]);
        $byClass = array_column($r['gaps'], null, 'class');
        $hints = $byClass[AtlasSelfConstructionCortexRiskGapLens::GAP_REPEATED_GIVE_BACK]['originator_hints'];

        $this->assertSame('maestro_repair', $hints['suggested_lane']);
        $this->assertSame('maestro', $hints['likely_owner_organ']);
        $this->assertStringContainsString('filing_more_tasks', $hints['avoid_proxy_warning']);
    }

    public function test_weak_worker_outcomes_hints_point_to_maestro_repair_not_generic_task_creation(): void
    {
        $r = (new AtlasSelfConstructionCortexRiskGapLens)->project([
            'worker_outcomes' => ['weak' => true],
        ]);
        $byClass = array_column($r['gaps'], null, 'class');
        $hints = $byClass[AtlasSelfConstructionCortexRiskGapLens::GAP_WEAK_WORKER_OUTCOMES]['originator_hints'];

        $this->assertSame('maestro_repair', $hints['suggested_lane']);
        $this->assertSame('maestro', $hints['likely_owner_organ']);
    }

    public function test_envelope_carries_no_scalar_score_or_rank_field(): void
    {
        $r = (new AtlasSelfConstructionCortexRiskGapLens)->project([
            'source_inventory' => ['blockers' => ['missing_required_source:docs']],
            'queue_health' => ['malformed_count' => 1],
        ]);
        $json = (string) json_encode($r);
        $this->assertDoesNotMatchRegularExpression('/"(score|rank|grade|hype|percent)"/i', $json);
    }

    public function test_gaps_are_sorted_byte_stably_by_class(): void
    {
        $r = (new AtlasSelfConstructionCortexRiskGapLens)->project([
            'source_inventory' => ['blockers' => ['missing_required_source:queue']],
            'verification' => ['server_side_green' => false],
            'merge' => ['posture' => 'unknown'],
            'queue_health' => ['malformed_count' => 1, 'repeated_give_back_count' => 9],
            'sweep_health' => ['coverage_unknown' => true],
        ]);
        $classes = array_column($r['gaps'], 'class');
        $copy = $classes;
        sort($copy, SORT_STRING);
        $this->assertSame($copy, $classes);
    }

    public function test_inventory_blockers_are_trimmed_deduped_and_sorted(): void
    {
        $r = (new AtlasSelfConstructionCortexRiskGapLens)->project([
            'source_inventory' => ['blockers' => [
                '  missing_required_source:docs  ',
                'stale_source:code_index',
                'missing_required_source:docs', // duplicate
                '',                             // empty — should be stripped
            ]],
        ]);
        $byClass = array_column($r['gaps'], null, 'class');
        $this->assertArrayHasKey(AtlasSelfConstructionCortexRiskGapLens::GAP_STALE_CONTEXT, $byClass);
        $blockers = $byClass[AtlasSelfConstructionCortexRiskGapLens::GAP_STALE_CONTEXT]['evidence']['inventory_blockers'];
        // trimmed: no leading/trailing spaces
        foreach ($blockers as $b) {
            $this->assertSame(trim($b), $b, 'blockers must be trimmed');
        }
        // deduped: no duplicates
        $this->assertSame(array_unique($blockers), $blockers, 'blockers must be unique');
        // sorted
        $sorted = $blockers;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $blockers, 'blockers must be sorted');
        // empty string stripped
        $this->assertNotContains('', $blockers);
    }

    public function test_negative_malformed_count_treated_as_zero(): void
    {
        $r = (new AtlasSelfConstructionCortexRiskGapLens)->project([
            'queue_health' => ['malformed_count' => -5],
        ]);
        $classes = array_column($r['gaps'], 'class');
        $this->assertNotContains(AtlasSelfConstructionCortexRiskGapLens::GAP_MALFORMED_QUEUE, $classes);
    }

    public function test_negative_repeated_give_back_count_treated_as_zero(): void
    {
        $r = (new AtlasSelfConstructionCortexRiskGapLens)->project([
            'queue_health' => ['repeated_give_back_count' => -99],
            'sweep_health' => ['coverage_unknown' => false],
        ]);
        $classes = array_column($r['gaps'], 'class');
        $this->assertNotContains(AtlasSelfConstructionCortexRiskGapLens::GAP_REPEATED_GIVE_BACK, $classes);
    }

    public function test_invalid_merge_posture_yields_unsafe_merge_gap_with_observed_posture(): void
    {
        $r = (new AtlasSelfConstructionCortexRiskGapLens)->project([
            'merge' => ['posture' => 'totally_invalid_posture'],
        ]);
        $byClass = array_column($r['gaps'], null, 'class');
        $this->assertArrayHasKey(AtlasSelfConstructionCortexRiskGapLens::GAP_UNSAFE_MERGE, $byClass);
        $this->assertSame('totally_invalid_posture', $byClass[AtlasSelfConstructionCortexRiskGapLens::GAP_UNSAFE_MERGE]['evidence']['posture']);
    }

    // ── originator_hints ──────────────────────────────────────────────────────

    public function test_every_gap_carries_originator_hints_with_all_four_keys(): void
    {
        $r = (new AtlasSelfConstructionCortexRiskGapLens)->project([
            'source_inventory' => ['blockers' => ['missing_required_source:docs']],
            'verification'     => ['server_side_green' => false],
            'merge'            => ['posture' => 'unsafe'],
            'queue_health'     => ['malformed_count' => 1, 'repeated_give_back_count' => 0],
            'sweep_health'     => ['coverage_unknown' => true],
        ]);

        $this->assertNotEmpty($r['gaps']);
        foreach ($r['gaps'] as $gap) {
            $this->assertArrayHasKey('originator_hints', $gap, "gap {$gap['class']} must carry originator_hints");
            $hints = $gap['originator_hints'];
            foreach (['suggested_lane', 'required_evidence', 'likely_owner_organ', 'avoid_proxy_warning'] as $k) {
                $this->assertArrayHasKey($k, $hints, "originator_hints must contain {$k} for gap {$gap['class']}");
                $this->assertIsString($hints[$k]);
                $this->assertNotEmpty($hints[$k]);
            }
        }
    }

    public function test_clean_posture_produces_no_gaps_and_no_hints(): void
    {
        $r = (new AtlasSelfConstructionCortexRiskGapLens)->project([
            'source_inventory' => ['blockers' => []],
            'verification'     => ['server_side_green' => true],
            'merge'            => ['posture' => 'safe'],
            'queue_health'     => ['malformed_count' => 0, 'repeated_give_back_count' => 0],
            'sweep_health'     => ['coverage_unknown' => false],
            'knowledge_sync'   => ['conformant' => true, 'blockers' => []],
        ]);

        $this->assertSame([], $r['gaps'], 'clean posture must produce no gaps and therefore no fabricated hints');
    }

    public function test_stale_context_hints_suggest_source_refresh_lane(): void
    {
        $r = (new AtlasSelfConstructionCortexRiskGapLens)->project([
            'source_inventory' => ['blockers' => ['missing_required_source:memory']],
        ]);
        $byClass = array_column($r['gaps'], null, 'class');
        $hints = $byClass[AtlasSelfConstructionCortexRiskGapLens::GAP_STALE_CONTEXT]['originator_hints'];

        $this->assertSame('source_refresh', $hints['suggested_lane']);
        $this->assertStringContainsString('staleness', $hints['avoid_proxy_warning']);
        $this->assertSame('memory', $hints['likely_owner_organ']);
    }

    public function test_missing_receipts_hints_warn_against_self_report(): void
    {
        $r = (new AtlasSelfConstructionCortexRiskGapLens)->project([
            'verification' => ['server_side_green' => false],
        ]);
        $byClass = array_column($r['gaps'], null, 'class');
        $hints = $byClass[AtlasSelfConstructionCortexRiskGapLens::GAP_MISSING_RECEIPTS]['originator_hints'];

        $this->assertSame('verification_closure', $hints['suggested_lane']);
        $this->assertStringContainsString('self_report', $hints['avoid_proxy_warning']);
    }

    public function test_unsafe_merge_hints_reference_cert_gate(): void
    {
        $r = (new AtlasSelfConstructionCortexRiskGapLens)->project([
            'merge' => ['posture' => 'unsafe'],
        ]);
        $byClass = array_column($r['gaps'], null, 'class');
        $hints = $byClass[AtlasSelfConstructionCortexRiskGapLens::GAP_UNSAFE_MERGE]['originator_hints'];

        $this->assertSame('merge_safety_repair', $hints['suggested_lane']);
        $this->assertStringContainsString('cert_gate', $hints['avoid_proxy_warning']);
        $this->assertSame('governance', $hints['likely_owner_organ']);
    }

    public function test_malformed_queue_hints_warn_against_creating_new_tasks(): void
    {
        $r = (new AtlasSelfConstructionCortexRiskGapLens)->project([
            'queue_health' => ['malformed_count' => 3],
        ]);
        $byClass = array_column($r['gaps'], null, 'class');
        $hints = $byClass[AtlasSelfConstructionCortexRiskGapLens::GAP_MALFORMED_QUEUE]['originator_hints'];

        $this->assertSame('queue_repair', $hints['suggested_lane']);
        $this->assertStringContainsString('creating_new_tasks', $hints['avoid_proxy_warning']);
    }

    public function test_unproved_runtime_hints_warn_against_test_count_proxy(): void
    {
        $r = (new AtlasSelfConstructionCortexRiskGapLens)->project([
            'sweep_health' => ['coverage_unknown' => true],
        ]);
        $byClass = array_column($r['gaps'], null, 'class');
        $hints = $byClass[AtlasSelfConstructionCortexRiskGapLens::GAP_UNPROVED_RUNTIME]['originator_hints'];

        $this->assertSame('runtime_coverage', $hints['suggested_lane']);
        $this->assertStringContainsString('test_count', $hints['avoid_proxy_warning']);
        $this->assertSame('runtime_health', $hints['likely_owner_organ']);
    }
}
