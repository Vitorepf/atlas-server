<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * atlas:external-brain:originator-stop-pivot is a read-only control surface composing the
 * stop/pivot advisor, theme saturation meter, batch value auditor, and spec novelty gate into
 * one anti-template-farm origination verdict.
 */
final class AtlasExternalBrainOriginatorStopPivotCommandTest extends TestCase
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
        $this->factsFile = sys_get_temp_dir().'/atlas_stop_pivot_facts_'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($this->factsFile, (string) json_encode($facts));

        Artisan::call('atlas:external-brain:originator-stop-pivot', [
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

        $this->assertArrayHasKey('next_action', $output);
        $this->assertArrayHasKey('theme_saturation', $output);
        $this->assertArrayHasKey('batch_value', $output);
        $this->assertArrayHasKey('candidate_novelty', $output);
    }

    public function test_no_facts_file_defaults_to_empty_facts_without_error(): void
    {
        Artisan::call('atlas:external-brain:originator-stop-pivot', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('next_action', $decoded);
    }

    public function test_queue_below_target_with_healthy_batch_does_not_stop_for_research(): void
    {
        $output = $this->exec([
            'queue' => ['claimable_depth' => 1, 'target_min_claimable' => 5, 'active_leases' => 2, 'servable_now' => 1],
            'recent_batch_tasks' => [
                ['has_runnable_proof' => true, 'objective' => 'Implement Foo capability'],
            ],
        ]);

        // A batch with runnable proof must never trigger research_before_originating —
        // that action is reserved for batches the auditor itself flags as unproven.
        $this->assertNotSame('research_before_originating', $output['next_action']);
        $this->assertSame('proceed', $output['batch_value']['recommendation']);
    }

    public function test_duplicate_candidate_is_flagged_as_not_safe_to_enqueue(): void
    {
        $output = $this->exec([
            'queue' => ['claimable_depth' => 1, 'target_min_claimable' => 5],
            'next_candidates' => [
                ['class_name' => 'FooService', 'objective' => 'Implement Foo capability exactly as before'],
            ],
            'existing_class_names' => ['FooService'],
        ]);

        $this->assertFalse($output['candidate_novelty']['results'][0]['safe_to_enqueue']);
    }
}
