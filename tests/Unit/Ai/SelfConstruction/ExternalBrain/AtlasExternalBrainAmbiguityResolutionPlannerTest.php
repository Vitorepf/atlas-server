<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmbiguityResolutionPlanner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainAmbiguityResolutionPlannerTest extends TestCase
{
    private AtlasExternalBrainAmbiguityResolutionPlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new AtlasExternalBrainAmbiguityResolutionPlanner;
    }

    private function item(array $overrides = []): array
    {
        return array_merge([
            'item_id'         => 'i-'.uniqid(),
            'claim'           => 'AtlasFooService may already exist.',
            'ambiguity_score' => 0.5,
            'grep_pattern'    => 'AtlasFooService',
        ], $overrides);
    }

    private function input(array ...$items): array
    {
        return ['ambiguity_items' => $items];
    }

    // ── Schema / AC4 required keys ────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->planner->plan($this->input($this->item()));

        foreach (['schema', 'ambiguity_items', 'resolution_actions', 'unresolved_items', 'task_creation_allowed'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainAmbiguityResolutionPlanner::SCHEMA, $result['schema']);
    }

    // ── AC2: action_type mapping ──────────────────────────────────────────────

    public function test_grep_pattern_maps_to_local_grep_check(): void
    {
        $result = $this->planner->plan($this->input($this->item(['grep_pattern' => 'FooService'])));

        $this->assertSame(
            AtlasExternalBrainAmbiguityResolutionPlanner::ACTION_LOCAL_GREP,
            $result['resolution_actions'][0]['action_type'],
        );
    }

    public function test_symbol_name_maps_to_local_grep_check(): void
    {
        $result = $this->planner->plan($this->input($this->item([
            'grep_pattern' => null,
            'symbol_name'  => 'AtlasFoo',
        ])));

        $this->assertSame(
            AtlasExternalBrainAmbiguityResolutionPlanner::ACTION_LOCAL_GREP,
            $result['resolution_actions'][0]['action_type'],
        );
    }

    public function test_target_path_maps_to_target_existence_check(): void
    {
        $result = $this->planner->plan($this->input($this->item([
            'grep_pattern' => null,
            'target_path'  => 'app/Services/FooService.php',
        ])));

        $this->assertSame(
            AtlasExternalBrainAmbiguityResolutionPlanner::ACTION_TARGET_EXISTENCE,
            $result['resolution_actions'][0]['action_type'],
        );
    }

    public function test_task_class_maps_to_queue_collision_check(): void
    {
        $result = $this->planner->plan($this->input($this->item([
            'grep_pattern' => null,
            'task_class'   => 'extraction',
        ])));

        $this->assertSame(
            AtlasExternalBrainAmbiguityResolutionPlanner::ACTION_QUEUE_COLLISION,
            $result['resolution_actions'][0]['action_type'],
        );
    }

    public function test_prior_evidence_id_maps_to_evidence_replay(): void
    {
        $result = $this->planner->plan($this->input($this->item([
            'grep_pattern'      => null,
            'prior_evidence_id' => 'evid-123',
        ])));

        $this->assertSame(
            AtlasExternalBrainAmbiguityResolutionPlanner::ACTION_EVIDENCE_REPLAY,
            $result['resolution_actions'][0]['action_type'],
        );
    }

    public function test_no_specific_fields_maps_to_explicit_escalation(): void
    {
        $result = $this->planner->plan($this->input($this->item([
            'grep_pattern' => null,
            'symbol_name'  => null,
            'target_path'  => null,
            'task_class'   => null,
            'task_family'  => null,
            'prior_evidence_id' => null,
        ])));

        $this->assertSame(
            AtlasExternalBrainAmbiguityResolutionPlanner::ACTION_EXPLICIT_ESCALATION,
            $result['resolution_actions'][0]['action_type'],
        );
    }

    // ── Action priority: evidence_replay > queue_collision > target > grep ────

    public function test_prior_evidence_id_takes_priority_over_grep(): void
    {
        $result = $this->planner->plan($this->input($this->item([
            'prior_evidence_id' => 'evid-456',
            'grep_pattern'      => 'SomeClass',
        ])));

        $this->assertSame(
            AtlasExternalBrainAmbiguityResolutionPlanner::ACTION_EVIDENCE_REPLAY,
            $result['resolution_actions'][0]['action_type'],
        );
    }

    // ── AC3: unresolved items block task creation ─────────────────────────────

    public function test_explicit_escalation_item_is_unresolved(): void
    {
        $result = $this->planner->plan($this->input($this->item([
            'grep_pattern' => null,
            'symbol_name'  => null,
            'target_path'  => null,
            'task_class'   => null,
            'task_family'  => null,
            'prior_evidence_id' => null,
        ])));

        $this->assertNotEmpty($result['unresolved_items']);
        $this->assertFalse($result['task_creation_allowed']);
    }

    public function test_resolved_item_allows_task_creation(): void
    {
        $result = $this->planner->plan($this->input($this->item(['grep_pattern' => 'FooService'])));

        $this->assertSame([], $result['unresolved_items']);
        $this->assertTrue($result['task_creation_allowed']);
    }

    public function test_mix_of_resolved_and_unresolved_blocks_task_creation(): void
    {
        $resolved   = $this->item(['grep_pattern' => 'Bar']);
        $unresolved = $this->item([
            'grep_pattern' => null,
            'symbol_name'  => null,
            'target_path'  => null,
            'task_class'   => null,
            'task_family'  => null,
            'prior_evidence_id' => null,
        ]);

        $result = $this->planner->plan($this->input($resolved, $unresolved));

        $this->assertFalse($result['task_creation_allowed']);
        $this->assertCount(1, $result['unresolved_items']);
    }

    // ── AC4: ambiguity_items are echoed in output ─────────────────────────────

    public function test_ambiguity_items_are_returned_in_output(): void
    {
        $item   = $this->item(['item_id' => 'echoed']);
        $result = $this->planner->plan($this->input($item));

        $ids = array_column($result['ambiguity_items'], 'item_id');
        $this->assertContains('echoed', $ids);
    }

    // ── Resolution actions contain check_command ──────────────────────────────

    public function test_local_grep_action_has_check_command(): void
    {
        $result = $this->planner->plan($this->input($this->item(['grep_pattern' => 'Baz'])));

        $this->assertArrayHasKey('check_command', $result['resolution_actions'][0]);
        $this->assertStringContainsString('Baz', $result['resolution_actions'][0]['check_command']);
    }

    public function test_explicit_escalation_action_has_null_check_command(): void
    {
        $result = $this->planner->plan($this->input($this->item([
            'grep_pattern' => null,
            'symbol_name'  => null,
            'target_path'  => null,
            'task_class'   => null,
            'task_family'  => null,
            'prior_evidence_id' => null,
        ])));

        $this->assertNull($result['resolution_actions'][0]['check_command']);
    }

    // ── Empty input ───────────────────────────────────────────────────────────

    public function test_empty_items_allows_task_creation(): void
    {
        $result = $this->planner->plan(['ambiguity_items' => []]);

        $this->assertTrue($result['task_creation_allowed']);
        $this->assertSame([], $result['unresolved_items']);
    }
}
