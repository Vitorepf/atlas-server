<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricOutcomeConstraintInjector;
use Tests\TestCase;

final class AtlasTaskFabricOutcomeConstraintInjectorTest extends TestCase
{
    private function injector(): AtlasTaskFabricOutcomeConstraintInjector
    {
        return new AtlasTaskFabricOutcomeConstraintInjector;
    }

    // ── AC: completed outcomes add preserve constraints ──

    public function test_completed_outcome_adds_preserve_constraint(): void
    {
        $result = $this->injector()->inject([
            ['result' => 'completed', 'target_family' => 'implementation', 'task_id' => 't1'],
        ]);

        $this->assertSame('preserve', $result['constraints'][0]['constraint']);
        $this->assertSame('implementation', $result['constraints'][0]['target_family']);
    }

    // ── AC: give_back adds reshape constraints ──

    public function test_give_back_outcome_adds_reshape_constraint(): void
    {
        $result = $this->injector()->inject([
            ['result' => 'give_back', 'target_family' => 'refactor', 'reason' => 'scope_too_wide', 'task_id' => 't2'],
        ]);

        $this->assertSame('reshape', $result['constraints'][0]['constraint']);
        $this->assertSame('scope_too_wide', $result['constraints'][0]['reason']);
    }

    // ── AC: blocked adds repair_first constraints ──

    public function test_blocked_outcome_adds_repair_first_constraint(): void
    {
        $result = $this->injector()->inject([
            ['result' => 'blocked', 'target_family' => 'architecture', 'reason' => 'dependency_missing', 'task_id' => 't3'],
        ]);

        $this->assertSame('repair_first', $result['constraints'][0]['constraint']);
        $this->assertSame('dependency_missing', $result['constraints'][0]['reason']);
    }

    // ── AC: quarantined adds do_not_requeue constraints ──

    public function test_quarantined_outcome_adds_do_not_requeue_constraint(): void
    {
        $result = $this->injector()->inject([
            ['result' => 'quarantined', 'target_family' => 'poison', 'reason' => 'repeated_poison', 'task_id' => 't4'],
        ]);

        $this->assertSame('do_not_requeue', $result['constraints'][0]['constraint']);
    }

    // ── constraints separated by type ──

    public function test_constraints_separated_by_type(): void
    {
        $result = $this->injector()->inject([
            ['result' => 'completed', 'target_family' => 'a', 'task_id' => 't1'],
            ['result' => 'give_back', 'target_family' => 'b', 'reason' => 'x', 'task_id' => 't2'],
            ['result' => 'blocked', 'target_family' => 'c', 'reason' => 'y', 'task_id' => 't3'],
            ['result' => 'quarantined', 'target_family' => 'd', 'reason' => 'z', 'task_id' => 't4'],
        ]);

        $this->assertCount(1, $result['preserve_constraints']);
        $this->assertCount(1, $result['reshape_constraints']);
        $this->assertCount(1, $result['repair_first_constraints']);
        $this->assertCount(1, $result['do_not_requeue_constraints']);
        $this->assertSame(4, $result['total_constraints']);
    }

    // ── deduplication ──

    public function test_duplicate_constraints_deduplicated(): void
    {
        $result = $this->injector()->inject([
            ['result' => 'give_back', 'target_family' => 'refactor', 'reason' => 'scope', 'task_id' => 't1'],
            ['result' => 'give_back', 'target_family' => 'refactor', 'reason' => 'scope', 'task_id' => 't2'],
        ]);

        $this->assertCount(1, $result['constraints']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->injector()->inject([]);

        $this->assertSame(AtlasTaskFabricOutcomeConstraintInjector::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('constraints', $result);
        $this->assertArrayHasKey('preserve_constraints', $result);
        $this->assertArrayHasKey('reshape_constraints', $result);
        $this->assertArrayHasKey('repair_first_constraints', $result);
        $this->assertArrayHasKey('do_not_requeue_constraints', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $outcomes = [
            ['result' => 'completed', 'target_family' => 'a', 'task_id' => 't1'],
            ['result' => 'give_back', 'target_family' => 'b', 'reason' => 'x', 'task_id' => 't2'],
        ];

        $a = $this->injector()->inject($outcomes);
        $b = $this->injector()->inject($outcomes);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
