<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmbiguityResolutionPlanner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainAmbiguityResolutionPlannerTest extends TestCase
{
    private function planner(): AtlasExternalBrainAmbiguityResolutionPlanner
    {
        return new AtlasExternalBrainAmbiguityResolutionPlanner;
    }

    // ── AC2: local check fields → deterministic action types ─────────────────

    public function test_prior_evidence_id_maps_to_evidence_replay(): void
    {
        $r = $this->planner()->plan(['ambiguity_items' => [
            ['item_id' => 'a', 'claim' => 'x', 'prior_evidence_id' => 'ev-42'],
        ]]);

        $this->assertSame(AtlasExternalBrainAmbiguityResolutionPlanner::ACTION_EVIDENCE_REPLAY, $r['resolution_actions'][0]['action_type']);
        $this->assertTrue($r['task_creation_allowed']);
    }

    public function test_task_family_maps_to_queue_collision_check(): void
    {
        $r = $this->planner()->plan(['ambiguity_items' => [
            ['item_id' => 'b', 'claim' => 'y', 'task_family' => 'brain-task'],
        ]]);

        $this->assertSame(AtlasExternalBrainAmbiguityResolutionPlanner::ACTION_QUEUE_COLLISION, $r['resolution_actions'][0]['action_type']);
    }

    public function test_target_path_maps_to_target_existence_check(): void
    {
        $r = $this->planner()->plan(['ambiguity_items' => [
            ['item_id' => 'c', 'claim' => 'z', 'target_path' => 'app/Services/Foo.php'],
        ]]);

        $this->assertSame(AtlasExternalBrainAmbiguityResolutionPlanner::ACTION_TARGET_EXISTENCE, $r['resolution_actions'][0]['action_type']);
    }

    public function test_grep_pattern_maps_to_local_grep_check(): void
    {
        $r = $this->planner()->plan(['ambiguity_items' => [
            ['item_id' => 'd', 'claim' => 'w', 'grep_pattern' => 'MyClass'],
        ]]);

        $this->assertSame(AtlasExternalBrainAmbiguityResolutionPlanner::ACTION_LOCAL_GREP, $r['resolution_actions'][0]['action_type']);
    }

    public function test_symbol_name_maps_to_local_grep_check(): void
    {
        $r = $this->planner()->plan(['ambiguity_items' => [
            ['item_id' => 'e', 'claim' => 'v', 'symbol_name' => 'MyMethod'],
        ]]);

        $this->assertSame(AtlasExternalBrainAmbiguityResolutionPlanner::ACTION_LOCAL_GREP, $r['resolution_actions'][0]['action_type']);
    }

    public function test_capability_claim_maps_to_capability_claim_check(): void
    {
        $r = $this->planner()->plan(['ambiguity_items' => [
            ['item_id' => 'f', 'claim' => 'u', 'capability_claim' => 'AtlasMemoryService'],
        ]]);

        $this->assertSame(AtlasExternalBrainAmbiguityResolutionPlanner::ACTION_CAPABILITY_CLAIM, $r['resolution_actions'][0]['action_type']);
    }

    public function test_prior_evidence_id_takes_priority_over_task_family(): void
    {
        $r = $this->planner()->plan(['ambiguity_items' => [
            ['item_id' => 'g', 'claim' => 't', 'prior_evidence_id' => 'ev-1', 'task_family' => 'some-family'],
        ]]);

        $this->assertSame(AtlasExternalBrainAmbiguityResolutionPlanner::ACTION_EVIDENCE_REPLAY, $r['resolution_actions'][0]['action_type']);
    }

    // ── AC3: high-ambiguity without local check → unresolved + blocked ────────

    public function test_high_ambiguity_no_check_fields_is_unresolved(): void
    {
        $r = $this->planner()->plan(['ambiguity_items' => [
            ['item_id' => 'h', 'claim' => 'uncertain', 'ambiguity_score' => 0.90],
        ]]);

        $this->assertCount(1, $r['unresolved_items']);
        $this->assertFalse($r['task_creation_allowed']);
    }

    public function test_explicit_escalation_when_no_fields_present(): void
    {
        $r = $this->planner()->plan(['ambiguity_items' => [
            ['item_id' => 'i', 'claim' => 'unknown', 'ambiguity_score' => 0.50],
        ]]);

        $this->assertSame(AtlasExternalBrainAmbiguityResolutionPlanner::ACTION_EXPLICIT_ESCALATION, $r['resolution_actions'][0]['action_type']);
        $this->assertFalse($r['task_creation_allowed']);
    }

    public function test_high_ambiguity_with_grep_pattern_is_not_unresolved(): void
    {
        $r = $this->planner()->plan(['ambiguity_items' => [
            ['item_id' => 'j', 'claim' => 's', 'ambiguity_score' => 0.90, 'grep_pattern' => 'FooBar'],
        ]]);

        $this->assertCount(0, $r['unresolved_items']);
        $this->assertTrue($r['task_creation_allowed']);
    }

    // ── AC4: output always has required keys ──────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->planner()->plan(['ambiguity_items' => []]);

        foreach (['ambiguity_items', 'resolution_actions', 'unresolved_items', 'task_creation_allowed'] as $key) {
            $this->assertArrayHasKey($key, $r, "Missing key: {$key}");
        }
    }

    public function test_task_creation_allowed_true_when_no_unresolved(): void
    {
        $r = $this->planner()->plan(['ambiguity_items' => [
            ['item_id' => 'k', 'claim' => 'r', 'target_path' => 'app/Foo.php'],
        ]]);

        $this->assertTrue($r['task_creation_allowed']);
        $this->assertCount(0, $r['unresolved_items']);
    }

    public function test_plan_is_deterministic(): void
    {
        $input = ['ambiguity_items' => [
            ['item_id' => 'x', 'claim' => 'c', 'ambiguity_score' => 0.60, 'grep_pattern' => 'Sym'],
            ['item_id' => 'y', 'claim' => 'd', 'ambiguity_score' => 0.95],
        ]];

        $a = $this->planner()->plan($input);
        $b = $this->planner()->plan($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
