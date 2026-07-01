<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAutonomousSpine;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainAutonomousSpineTest extends TestCase
{
    private function spine(): AtlasExternalBrainAutonomousSpine
    {
        return new AtlasExternalBrainAutonomousSpine;
    }

    /** @return array<string, array<string,mixed>> */
    private function readySections(array $overrides = []): array
    {
        return array_merge([
            'context_intake' => ['ready' => true],
            'leverage_ranking' => ['ready' => true],
            'task_fabric' => ['ready' => true],
            'queue_self_healing' => ['ready' => true],
            'outcome_learning' => ['ready' => true],
            'simplification' => ['ready' => true],
        ], $overrides);
    }

    // ── AC: named sections present in output ──────────────────────────────────

    public function test_snapshot_includes_named_sections_for_every_organ(): void
    {
        $r = $this->spine()->compile(['sections' => $this->readySections()]);

        foreach (['context_intake', 'leverage_ranking', 'task_fabric', 'queue_self_healing', 'outcome_learning', 'simplification', 'next_action'] as $key) {
            $this->assertArrayHasKey($key, $r, "Missing section: {$key}");
        }
    }

    public function test_all_ready_sections_yield_ready_true_and_no_gaps(): void
    {
        $r = $this->spine()->compile(['sections' => $this->readySections()]);

        $this->assertTrue($r['ready']);
        $this->assertSame([], $r['integration_gaps']);
    }

    // ── AC: missing required section → integration_gaps, ready=false ─────────

    public function test_missing_section_reports_integration_gap_instead_of_ready_true(): void
    {
        $sections = $this->readySections();
        unset($sections['task_fabric']);

        $r = $this->spine()->compile(['sections' => $sections]);

        $this->assertFalse($r['ready']);
        $this->assertContains('task_fabric_missing', $r['integration_gaps']);
        $this->assertFalse($r['task_fabric']['present']);
    }

    public function test_section_present_but_not_ready_reports_integration_gap(): void
    {
        $sections = $this->readySections(['outcome_learning' => ['ready' => false]]);

        $r = $this->spine()->compile(['sections' => $sections]);

        $this->assertFalse($r['ready']);
        $this->assertContains('outcome_learning_not_ready', $r['integration_gaps']);
        $this->assertTrue($r['outcome_learning']['present']);
    }

    public function test_empty_input_reports_all_six_sections_missing(): void
    {
        $r = $this->spine()->compile([]);

        $this->assertFalse($r['ready']);
        $this->assertCount(6, $r['integration_gaps']);
    }

    // ── AC: next_action is derived, not hardcoded ─────────────────────────────

    public function test_next_action_targets_first_integration_gap_when_sections_missing(): void
    {
        $sections = $this->readySections();
        unset($sections['context_intake']);

        $r = $this->spine()->compile(['sections' => $sections]);

        $this->assertSame('close_integration_gap:context_intake_missing', $r['next_action']);
    }

    public function test_next_action_repairs_queue_when_queue_self_healing_flags_urgent_repair(): void
    {
        $sections = $this->readySections(['queue_self_healing' => ['ready' => true, 'needs_repair' => true]]);

        $r = $this->spine()->compile(['sections' => $sections]);

        $this->assertSame('repair_queue', $r['next_action']);
    }

    public function test_next_action_executes_simplification_when_candidates_approved(): void
    {
        $sections = $this->readySections(['simplification' => ['ready' => true, 'has_approved_candidates' => true]]);

        $r = $this->spine()->compile(['sections' => $sections]);

        $this->assertSame('execute_simplification', $r['next_action']);
    }

    public function test_next_action_creates_task_from_top_leverage_candidate(): void
    {
        $sections = $this->readySections(['leverage_ranking' => ['ready' => true, 'top_candidate' => 'gap-42']]);

        $r = $this->spine()->compile(['sections' => $sections]);

        $this->assertSame('create_task:gap-42', $r['next_action']);
    }

    public function test_next_action_waits_when_all_ready_and_no_signal(): void
    {
        $r = $this->spine()->compile(['sections' => $this->readySections()]);

        $this->assertSame('wait_for_new_signal', $r['next_action']);
    }

    public function test_next_action_is_not_hardcoded_across_varied_inputs(): void
    {
        $gap = $this->spine()->compile(['sections' => []]);
        $repair = $this->spine()->compile(['sections' => $this->readySections(['queue_self_healing' => ['ready' => true, 'needs_repair' => true]])]);
        $simplify = $this->spine()->compile(['sections' => $this->readySections(['simplification' => ['ready' => true, 'has_approved_candidates' => true]])]);
        $create = $this->spine()->compile(['sections' => $this->readySections(['leverage_ranking' => ['ready' => true, 'top_candidate' => 'x']])]);
        $wait = $this->spine()->compile(['sections' => $this->readySections()]);

        $actions = [$gap['next_action'], $repair['next_action'], $simplify['next_action'], $create['next_action'], $wait['next_action']];

        $this->assertSame($actions, array_unique($actions));
    }

    // ── Determinism ─────────────────────────────────────────────────────────

    public function test_compile_is_deterministic(): void
    {
        $facts = ['sections' => $this->readySections()];
        $a = $this->spine()->compile($facts);
        $b = $this->spine()->compile($facts);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_schema_version_present(): void
    {
        $r = $this->spine()->compile([]);
        $this->assertSame(AtlasExternalBrainAutonomousSpine::SCHEMA, $r['schema']);
    }
}
