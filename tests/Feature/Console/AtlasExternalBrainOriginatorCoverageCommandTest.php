<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * atlas:external-brain:originator-coverage is a read-only control surface composing roadmap
 * coverage, surface saturation, backlog aging, and impact diversity into one governance verdict.
 */
final class AtlasExternalBrainOriginatorCoverageCommandTest extends TestCase
{
    private string $factsFile = '';

    protected function tearDown(): void
    {
        if ($this->factsFile !== '' && is_file($this->factsFile)) {
            unlink($this->factsFile);
        }
        parent::tearDown();
    }

    private function exec(array $facts): array
    {
        $this->factsFile = sys_get_temp_dir().'/atlas_originator_coverage_facts_'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($this->factsFile, (string) json_encode($facts));

        Artisan::call('atlas:external-brain:originator-coverage', [
            '--facts-file' => $this->factsFile,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    public function test_json_output_contains_all_four_organ_sections(): void
    {
        $output = $this->exec([]);

        $this->assertArrayHasKey('route_to_gaps', $output);
        $this->assertArrayHasKey('roadmap_coverage', $output);
        $this->assertArrayHasKey('surface_saturation', $output);
        $this->assertArrayHasKey('backlog_aging', $output);
        $this->assertArrayHasKey('impact_diversity', $output);
    }

    public function test_no_facts_file_defaults_to_empty_facts_without_error(): void
    {
        Artisan::call('atlas:external-brain:originator-coverage', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('route_to_gaps', $decoded);
    }

    public function test_overcovered_gap_routes_to_gaps(): void
    {
        $output = $this->exec([
            'roadmap_gaps' => [
                ['gap_id' => 'gap-a', 'priority' => 'high', 'target_coverage' => 1],
            ],
            'queued_tasks' => [
                ['task_id' => 't1', 'gap_id' => 'gap-a'],
            ],
        ]);

        $this->assertTrue($output['route_to_gaps']);
        $this->assertContains('roadmap_gaps_overcovered', $output['reasons']);
        $this->assertContains('gap-a', $output['roadmap_coverage']['overcovered_gaps']);
    }

    public function test_retired_backlog_task_does_not_count_as_coverage(): void
    {
        $output = $this->exec([
            'roadmap_gaps' => [
                ['gap_id' => 'gap-b', 'priority' => 'high', 'target_coverage' => 1],
            ],
            'queued_tasks' => [
                ['task_id' => 't-retired', 'gap_id' => 'gap-b'],
            ],
            'backlog_tasks' => [
                ['task_id' => 't-retired', 'theme' => 'x', 'target' => 'y', 'implementation_status' => 'done'],
            ],
        ]);

        // The retired task's gap_id must not count toward gap-b's coverage, so gap-b stays
        // undercovered even though a queued_tasks entry references it.
        $this->assertArrayNotHasKey('gap-b', array_flip($output['roadmap_coverage']['overcovered_gaps']));
        $this->assertContains('t-retired', array_column($output['backlog_aging']['retire_candidates'], 'task_id'));
    }

    public function test_saturated_surface_routes_to_gaps(): void
    {
        // duplicate_rate high, yield high (not both low) -> pure ROTATE branch, no mode-coverage
        // gate involved.
        $recentCandidates = array_fill(0, 5, ['duplicate' => true, 'yield' => 0.9]);
        $output = $this->exec([
            'recent_candidates' => $recentCandidates,
        ]);

        $this->assertSame('rotate', $output['surface_saturation']['verdict']);
        $this->assertTrue($output['route_to_gaps']);
    }

    public function test_healthy_surface_and_diverse_batch_does_not_route_to_gaps(): void
    {
        $output = $this->exec([
            'recent_candidates' => [
                ['duplicate' => false, 'yield' => 0.9],
                ['duplicate' => false, 'yield' => 0.9],
                ['duplicate' => false, 'yield' => 0.9],
            ],
            'candidate_batches' => [
                ['task_packet_id' => 't1', 'impact_class' => 'task_fabric'],
                ['task_packet_id' => 't2', 'impact_class' => 'outcome_learning'],
            ],
        ]);

        $this->assertSame('deepen', $output['surface_saturation']['verdict']);
        $this->assertFalse($output['route_to_gaps']);
    }
}
