<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainGapClosureSnapshot;
use Tests\TestCase;

/**
 * Proves the fail-closed contract of AtlasExternalBrainGapClosureSnapshot:
 * contradictory sections are coerced to not-ready, and overall ready
 * requires loop complete + all sections honest-ready + queue repaired
 * + no outcome leaks.
 */
final class AtlasExternalBrainGapClosureSnapshotTest extends TestCase
{
    private function snapshot(): AtlasExternalBrainGapClosureSnapshot
    {
        return new AtlasExternalBrainGapClosureSnapshot;
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function validInput(array $overrides = []): array
    {
        return array_merge([
            'cycles' => [[
                'id' => 1,
                'origination_receipt' => ['task_packet_id' => 'pk-1'],
                'attribution' => ['owner' => 'muscle-1'],
                'implementation_result' => ['commit_sha' => 'abc123'],
                'runnable_evidence' => ['command' => 'phpunit', 'outcome' => 'passed'],
                'learning_update' => ['pattern_family' => 'refactor'],
                'next_batch_constraint' => ['promoted_rule' => 'keep'],
            ]],
            'spine_sections' => [
                ['name' => 'runtime', 'ready' => true, 'reasons' => []],
                ['name' => 'evidence', 'ready' => true, 'reasons' => []],
            ],
            'queue_repair_summary' => ['repaired' => 2, 'still_broken' => 0],
            'outcome_feedback_summary' => ['learned' => 3, 'leaks' => 0],
        ], $overrides);
    }

    public function test_contradictory_section_ready_true_with_reasons_forced_to_not_ready(): void
    {
        // A section with ready=true but non-empty reasons is contradictory
        // — must be coerced to not-ready.
        $result = $this->snapshot()->snapshot($this->validInput([
            'spine_sections' => [
                ['name' => 'contradictory', 'ready' => true, 'reasons' => ['something_still_blocking']],
                ['name' => 'clean', 'ready' => true, 'reasons' => []],
            ],
        ]));

        $section = $result['spine_sections'][0];
        $this->assertFalse($section['ready'], 'contradictory section must be coerced to not-ready');
        $this->assertContains('ready_true_but_reasons_present', $section['reasons']);

        // Overall ready must be false.
        $this->assertFalse($result['ready']);
        $this->assertSame('close_gap', $result['recommended_next_action']);
    }

    public function test_honest_ready_section_with_true_and_empty_reasons_counts_as_ready(): void
    {
        $result = $this->snapshot()->snapshot($this->validInput());

        foreach ($result['spine_sections'] as $section) {
            $this->assertTrue($section['ready']);
            $this->assertSame([], $section['reasons']);
        }
        $this->assertTrue($result['ready']);
        $this->assertSame('proceed_with_new_organs', $result['recommended_next_action']);
    }

    public function test_empty_spine_sections_yields_not_ready(): void
    {
        // Fail-closed: no spine_sections is NOT treated as "nothing to prove".
        $result = $this->snapshot()->snapshot($this->validInput([
            'spine_sections' => [],
        ]));

        $this->assertFalse($result['ready']);
        $this->assertSame('close_gap', $result['recommended_next_action']);
    }

    public function test_still_broken_queue_blocks_readiness(): void
    {
        $result = $this->snapshot()->snapshot($this->validInput([
            'queue_repair_summary' => ['repaired' => 1, 'still_broken' => 1],
        ]));

        $this->assertFalse($result['ready']);
        $this->assertSame('close_gap', $result['recommended_next_action']);
    }

    public function test_outcome_leaks_block_readiness(): void
    {
        $result = $this->snapshot()->snapshot($this->validInput([
            'outcome_feedback_summary' => ['learned' => 1, 'leaks' => 2],
        ]));

        $this->assertFalse($result['ready']);
        $this->assertSame('close_gap', $result['recommended_next_action']);
    }

    public function test_missing_cycles_and_empty_sections_yields_not_ready(): void
    {
        $result = $this->snapshot()->snapshot($this->validInput([
            'cycles' => [],
            'spine_sections' => [],
        ]));

        // No spine_sections → allSectionsReady=false → not ready
        $this->assertFalse($result['ready']);
    }
}
