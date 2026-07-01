<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev;

use App\Services\Ai\Programming\AtlasDev\Pipeline\DesignPathSelector;
use App\Services\Ai\Programming\AtlasDev\Pipeline\TaskClassification;
use PHPUnit\Framework\TestCase;

final class DesignPathSelectorTest extends TestCase
{
    private function selector(): DesignPathSelector
    {
        return new DesignPathSelector;
    }

    // (a) a bug-shaped fact set selects bugfix_root_cause with caller evidence in the reason ────

    public function test_bug_shaped_facts_select_bugfix_root_cause_with_caller_evidence_in_reason(): void
    {
        $result = $this->selector()->select([
            'task_kind' => TaskClassification::KIND_REPAIR,
            'risk' => 'R2',
            'likely_files' => ['app/Services/Foo.php'],
            'callers' => ['App\\Services\\Foo::bar', 'App\\Services\\Baz::qux'],
            'tests' => ['tests/Unit/FooTest.php'],
        ]);

        $this->assertSame(DesignPathSelector::PATH_BUGFIX_ROOT_CAUSE, $result['design_path']);
        $this->assertStringContainsString('caller', $result['reason']);
        $this->assertSame(2, $result['evidence']['caller_count']);
    }

    // (b) a duplicate-capability fact set selects deletion_first or adapter_composition ────────

    public function test_duplicate_capability_with_no_callers_selects_deletion_first(): void
    {
        $result = $this->selector()->select([
            'task_kind' => TaskClassification::KIND_PATCH,
            'risk' => 'R1',
            'duplicate_capability' => true,
            'callers' => [],
        ]);

        $this->assertSame(DesignPathSelector::PATH_DELETION_FIRST, $result['design_path']);
    }

    public function test_duplicate_capability_with_live_callers_selects_adapter_composition(): void
    {
        $result = $this->selector()->select([
            'task_kind' => TaskClassification::KIND_PATCH,
            'risk' => 'R2',
            'duplicate_capability' => true,
            'callers' => ['App\\Services\\Consumer::use'],
        ]);

        $this->assertSame(DesignPathSelector::PATH_ADAPTER_COMPOSITION, $result['design_path']);
        $this->assertStringContainsString('caller', $result['reason']);
    }

    // (c) the same facts always select the same path (determinism) ───────────────────────────

    public function test_same_facts_always_select_the_same_path(): void
    {
        $facts = [
            'task_kind' => TaskClassification::KIND_REPAIR,
            'risk' => 'R2',
            'likely_files' => ['a.php'],
            'callers' => ['c1'],
            'tests' => ['t1'],
        ];

        $a = $this->selector()->select($facts);
        $b = $this->selector()->select($facts);

        $this->assertSame($a, $b);
    }

    // ── other design paths ─────────────────────────────────────────────────────

    public function test_risky_task_kind_selects_gate_hardening(): void
    {
        $result = $this->selector()->select(['task_kind' => TaskClassification::KIND_RISKY, 'risk' => 'R4']);

        $this->assertSame(DesignPathSelector::PATH_GATE_HARDENING, $result['design_path']);
    }

    public function test_reusable_pattern_available_selects_replay_of_proven_pattern(): void
    {
        $result = $this->selector()->select([
            'task_kind' => TaskClassification::KIND_PATCH,
            'reusable_pattern_available' => true,
        ]);

        $this->assertSame(DesignPathSelector::PATH_REPLAY_OF_PROVEN_PATTERN, $result['design_path']);
    }

    public function test_refactor_only_selects_safe_refactor(): void
    {
        $result = $this->selector()->select([
            'task_kind' => TaskClassification::KIND_PATCH,
            'refactor_only' => true,
        ]);

        $this->assertSame(DesignPathSelector::PATH_SAFE_REFACTOR, $result['design_path']);
    }

    public function test_default_patch_selects_feature_slice(): void
    {
        $result = $this->selector()->select(['task_kind' => TaskClassification::KIND_PATCH]);

        $this->assertSame(DesignPathSelector::PATH_FEATURE_SLICE, $result['design_path']);
    }

    public function test_duplicate_capability_takes_priority_over_repair_task_kind(): void
    {
        $result = $this->selector()->select([
            'task_kind' => TaskClassification::KIND_REPAIR,
            'duplicate_capability' => true,
            'callers' => [],
        ]);

        $this->assertSame(DesignPathSelector::PATH_DELETION_FIRST, $result['design_path']);
    }

    public function test_evidence_reflects_input_counts(): void
    {
        $result = $this->selector()->select([
            'task_kind' => TaskClassification::KIND_PATCH,
            'likely_files' => ['a.php', 'b.php'],
            'callers' => ['c1'],
            'tests' => ['t1', 't2', 't3'],
        ]);

        $this->assertSame(2, $result['evidence']['likely_file_count']);
        $this->assertSame(1, $result['evidence']['caller_count']);
        $this->assertSame(3, $result['evidence']['test_count']);
    }

    public function test_output_has_required_keys(): void
    {
        $result = $this->selector()->select([]);

        $this->assertArrayHasKey('design_path', $result);
        $this->assertArrayHasKey('reason', $result);
        $this->assertArrayHasKey('evidence', $result);
    }
}
