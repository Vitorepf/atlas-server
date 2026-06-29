<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\SelfConstruction\TaskGraph\AtlasSelfConstructionTaskGraphCoverageDossier;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the task-graph coverage dossier is live at the operator surface: total equals the task-node count and
 * missing_count matches the uncovered nodes; a blocked organ flips the dossier status to blocked.
 */
final class AtlasLoopTaskGraphCoverageCommandTest extends TestCase
{
    private function export(array $facts): array
    {
        $exit = Artisan::call('atlas:loop:taskgraph-coverage', [
            '--facts' => (string) json_encode($facts),
            '--json' => true,
        ]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_rollup_counts_covered_and_missing_nodes(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->export([
            'coverage' => [
                'passed' => false,
                'organ_coverage' => ['A' => 'covered', 'B' => 'missing', 'C' => 'covered'],
                'missing_organs' => ['B'],
            ],
            'planner' => ['drafts' => [], 'withheld_gaps' => []],
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasSelfConstructionTaskGraphCoverageDossier::SCHEMA, $d['schema']);
        $this->assertSame(3, $d['organ_summary']['total'], (string) json_encode($d));
        $this->assertSame(2, $d['organ_summary']['covered_count']);
        $this->assertSame(1, $d['organ_summary']['missing_count']);
        // no blockers, coverage not passed ⇒ hold
        $this->assertSame(AtlasSelfConstructionTaskGraphCoverageDossier::STATUS_HOLD, $d['status']);
    }

    public function test_blocked_organ_flips_status_to_blocked(): void
    {
        ['d' => $d] = $this->export([
            'coverage' => [
                'passed' => false,
                'organ_coverage' => ['A' => 'covered', 'X' => 'blocked'],
                'blocked_organs' => ['X'],
            ],
            'planner' => ['drafts' => [], 'withheld_gaps' => []],
        ]);

        $this->assertSame(AtlasSelfConstructionTaskGraphCoverageDossier::STATUS_BLOCKED, $d['status'], (string) json_encode($d));
        $this->assertSame(1, $d['organ_summary']['blocked_count']);
        $this->assertNotEmpty($d['blockers']);
    }

    public function test_all_covered_and_passed_is_ready(): void
    {
        ['d' => $d] = $this->export([
            'coverage' => [
                'passed' => true,
                'organ_coverage' => ['A' => 'covered', 'B' => 'covered'],
            ],
            'planner' => ['drafts' => [], 'withheld_gaps' => []],
        ]);

        $this->assertSame(AtlasSelfConstructionTaskGraphCoverageDossier::STATUS_READY, $d['status'], (string) json_encode($d));
        $this->assertSame(2, $d['organ_summary']['covered_count']);
        $this->assertSame([], $d['blockers']);
    }

    public function test_empty_facts_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:taskgraph-coverage', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
