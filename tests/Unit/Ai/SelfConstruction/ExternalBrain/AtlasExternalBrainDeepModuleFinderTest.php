<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainDeepModuleFinder;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainDeepModuleFinderTest extends TestCase
{
    private function finder(): AtlasExternalBrainDeepModuleFinder
    {
        return new AtlasExternalBrainDeepModuleFinder;
    }

    public function test_schema_present(): void
    {
        $result = $this->finder()->find([]);

        $this->assertSame(AtlasExternalBrainDeepModuleFinder::SCHEMA, $result['schema']);
    }

    public function test_output_has_required_keys(): void
    {
        $result = $this->finder()->find([]);

        foreach (['schema', 'seams', 'preserved', 'needs_review'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
    }

    public function test_empty_input_yields_no_seams(): void
    {
        $result = $this->finder()->find(['organs' => []]);

        $this->assertSame([], $result['seams']);
        $this->assertSame([], $result['preserved']);
        $this->assertSame([], $result['needs_review']);
    }

    // ── multi_organ_grouping_case ───────────────────────────────────────────

    public function test_three_organs_sharing_purpose_are_grouped_into_one_seam(): void
    {
        $result = $this->finder()->find(['organs' => [
            ['organ_id' => 'wrap-a', 'purpose' => 'task_serving_receipt'],
            ['organ_id' => 'wrap-b', 'purpose' => 'task_serving_receipt'],
            ['organ_id' => 'wrap-c', 'purpose' => 'task_serving_receipt'],
        ]]);

        $this->assertCount(1, $result['seams']);
        $seam = $result['seams'][0];
        $this->assertSame(['wrap-a', 'wrap-b', 'wrap-c'], $seam['organ_ids']);
        $this->assertContains('task_serving_receipt', $seam['shared_purpose']);
        $this->assertContains('shared_purpose', $seam['overlap_signals']);
        $this->assertStringContainsString('task_serving_receipt', $seam['proposed_deep_module']);
        $this->assertSame([], $result['preserved']);
    }

    public function test_organs_sharing_consumers_are_grouped(): void
    {
        $result = $this->finder()->find(['organs' => [
            ['organ_id' => 'a', 'consumer_ids' => ['maestro']],
            ['organ_id' => 'b', 'consumer_ids' => ['maestro']],
        ]]);

        $this->assertCount(1, $result['seams']);
        $this->assertContains('maestro', $result['seams'][0]['shared_consumers']);
        $this->assertContains('shared_consumers', $result['seams'][0]['overlap_signals']);
    }

    public function test_organs_sharing_io_contracts_are_grouped(): void
    {
        $result = $this->finder()->find(['organs' => [
            ['organ_id' => 'a', 'inputs' => ['task_packet'], 'outputs' => ['verdict']],
            ['organ_id' => 'b', 'inputs' => ['task_packet'], 'outputs' => ['decision']],
        ]]);

        $this->assertCount(1, $result['seams']);
        $this->assertContains('task_packet', $result['seams'][0]['shared_io_contracts']);
        $this->assertContains('shared_io_contracts', $result['seams'][0]['overlap_signals']);
    }

    public function test_two_disjoint_pairs_produce_two_separate_seams(): void
    {
        $result = $this->finder()->find(['organs' => [
            ['organ_id' => 'a1', 'purpose' => 'alpha'],
            ['organ_id' => 'a2', 'purpose' => 'alpha'],
            ['organ_id' => 'b1', 'purpose' => 'beta'],
            ['organ_id' => 'b2', 'purpose' => 'beta'],
        ]]);

        $this->assertCount(2, $result['seams']);
        $seamOrganSets = array_map(fn (array $s): array => $s['organ_ids'], $result['seams']);
        $this->assertContains(['a1', 'a2'], $seamOrganSets);
        $this->assertContains(['b1', 'b2'], $seamOrganSets);
    }

    // ── singletons preserved ────────────────────────────────────────────────

    public function test_singleton_organ_with_no_shared_signal_is_preserved(): void
    {
        $result = $this->finder()->find(['organs' => [
            ['organ_id' => 'lonely', 'purpose' => 'unique_purpose'],
        ]]);

        $this->assertSame([], $result['seams']);
        $this->assertSame(['lonely'], $result['preserved']);
    }

    public function test_mixed_singleton_and_group_only_singleton_is_preserved(): void
    {
        $result = $this->finder()->find(['organs' => [
            ['organ_id' => 'lonely', 'purpose' => 'unique_purpose'],
            ['organ_id' => 'a', 'purpose' => 'shared_purpose'],
            ['organ_id' => 'b', 'purpose' => 'shared_purpose'],
        ]]);

        $this->assertCount(1, $result['seams']);
        $this->assertSame(['lonely'], $result['preserved']);
    }

    // ── behavior_unique_guard_case ──────────────────────────────────────────

    public function test_behavior_unique_organ_is_excluded_from_seam_and_flagged_needs_review(): void
    {
        $result = $this->finder()->find(['organs' => [
            ['organ_id' => 'normal-a', 'purpose' => 'shared_purpose'],
            ['organ_id' => 'normal-b', 'purpose' => 'shared_purpose'],
            ['organ_id' => 'unique-c', 'purpose' => 'shared_purpose', 'behavior_unique' => true],
        ]]);

        $this->assertCount(1, $result['seams']);
        $this->assertSame(['normal-a', 'normal-b'], $result['seams'][0]['organ_ids']);
        $this->assertNotContains('unique-c', $result['seams'][0]['organ_ids']);

        $this->assertCount(1, $result['needs_review']);
        $this->assertSame('unique-c', $result['needs_review'][0]['organ_id']);
        $this->assertSame('behavior_unique_excluded_from_seam', $result['needs_review'][0]['reason']);
        $this->assertSame([], $result['preserved']);
    }

    public function test_all_organs_in_group_behavior_unique_produces_no_seam(): void
    {
        $result = $this->finder()->find(['organs' => [
            ['organ_id' => 'u1', 'purpose' => 'shared_purpose', 'behavior_unique' => true],
            ['organ_id' => 'u2', 'purpose' => 'shared_purpose', 'behavior_unique' => true],
        ]]);

        $this->assertSame([], $result['seams']);
        $this->assertCount(2, $result['needs_review']);
        $reviewedIds = array_column($result['needs_review'], 'organ_id');
        $this->assertContains('u1', $reviewedIds);
        $this->assertContains('u2', $reviewedIds);
    }

    public function test_behavior_unique_singleton_is_preserved_not_reviewed(): void
    {
        // No shared signal at all — never even enters a group, so it's a plain preserve.
        $result = $this->finder()->find(['organs' => [
            ['organ_id' => 'solo-unique', 'purpose' => 'nobody_else_has_this', 'behavior_unique' => true],
        ]]);

        $this->assertSame([], $result['needs_review']);
        $this->assertSame(['solo-unique'], $result['preserved']);
    }

    public function test_result_is_deterministic_for_identical_input(): void
    {
        $organs = [
            ['organ_id' => 'a', 'purpose' => 'x'],
            ['organ_id' => 'b', 'purpose' => 'x'],
        ];

        $finder = $this->finder();
        $this->assertSame($finder->find(['organs' => $organs]), $finder->find(['organs' => $organs]));
    }

    public function test_capability_labels_fallback_used_when_purpose_absent(): void
    {
        $result = $this->finder()->find(['organs' => [
            ['organ_id' => 'a', 'capability_labels' => ['origination']],
            ['organ_id' => 'b', 'capability_labels' => ['origination']],
        ]]);

        $this->assertCount(1, $result['seams']);
        $this->assertContains('origination', $result['seams'][0]['shared_purpose']);
    }
}
