<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasExternalBrainSimplificationGovernorCommandTest extends TestCase
{
    private string $tempBase = '';

    private string $inputFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempBase = sys_get_temp_dir().'/atlas-simplification-governor-cli-'.bin2hex(random_bytes(6));
        @mkdir($this->tempBase, 0o755, true);
        $this->inputFile = $this->tempBase.'/input.json';
    }

    protected function tearDown(): void
    {
        if ($this->tempBase !== '' && is_dir($this->tempBase)) {
            (new Process(['rm', '-rf', $this->tempBase]))->run();
        }
        parent::tearDown();
    }

    private function writeInput(array $data): void
    {
        file_put_contents($this->inputFile, json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    private function runCmd(array $args): array
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:external-brain:simplification-governor', $args);

        return [$exit, $kernel->output()];
    }

    public function test_missing_input_option_fails(): void
    {
        [$exit] = $this->runCmd([]);

        $this->assertSame(1, $exit);
    }

    public function test_nonexistent_input_path_fails(): void
    {
        [$exit] = $this->runCmd(['--input' => $this->tempBase.'/does-not-exist.json']);

        $this->assertSame(1, $exit);
    }

    public function test_empty_sections_produce_empty_governance_report(): void
    {
        $this->writeInput([]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame([], $decoded['compression_plan']['candidates']);
        $this->assertSame([], $decoded['first_safe_consolidation_wave']);
        $this->assertEqualsWithDelta(0.0, $decoded['approved_roi'], 0.001);
    }

    public function test_stale_scaffold_with_owner_and_coverage_is_safe_delete(): void
    {
        $this->writeInput([
            'architecture_inventory' => [
                'organs' => [
                    [
                        'id' => 'organ-a',
                        'files' => ['app/Services/Old.php'],
                        'line_count' => 100,
                        'stale_scaffold_marker' => true,
                        'replacement_owner' => 'organ-b',
                        'test_coverage' => true,
                    ],
                ],
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame('delete', $decoded['compression_plan']['candidates'][0]['action']);
    }

    public function test_worker_feed_low_blocks_deletion_that_feeds_active_workers(): void
    {
        $this->writeInput([
            'architecture_inventory' => [
                'worker_floor_low' => true,
                'organs' => [
                    [
                        'id' => 'organ-c',
                        'files' => ['app/Services/Feeder.php'],
                        'line_count' => 100,
                        'stale_scaffold_marker' => true,
                        'replacement_owner' => 'organ-d',
                        'test_coverage' => true,
                        'feeds_active_workers' => true,
                        'replacement_claimable_path' => false,
                    ],
                ],
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame('keep', $decoded['compression_plan']['candidates'][0]['action']);
        $this->assertNotEmpty($decoded['worker_feed_blocked_deletions']);
    }

    public function test_complexity_burn_down_blocks_delete_with_active_consumers(): void
    {
        $this->writeInput([
            'complexity_candidates' => [
                'candidates' => [
                    [
                        'organ_id' => 'organ-e',
                        'similar_organs' => ['organ-f'],
                        'usage_evidence_count' => 0,
                        'compounding_value' => 0.1,
                        'has_active_consumers' => true,
                    ],
                ],
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame(
            \App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainComplexityDebtBurnDownPlanner::ACTION_MIGRATE_OR_PROVE_FIRST,
            $decoded['complexity_burn_down']['ranked_candidates'][0]['recommended_action'],
        );
    }

    public function test_roi_ledger_refuses_deletion_without_replacement_proof(): void
    {
        $this->writeInput([
            'roi_candidates' => [
                'candidates' => [
                    ['id' => 'cand-1', 'action' => 'delete', 'roi_estimate' => 5.0],
                ],
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame('deletion_without_replacement_proof', $decoded['simplification_roi']['refused'][0]['refusal_reason']);
        $this->assertGreaterThan(0.0, $decoded['refused_roi']);
    }

    public function test_first_safe_consolidation_wave_and_task_feed_impact_present(): void
    {
        $this->writeInput([
            'sprawl_organs' => [
                'organs' => [
                    [
                        'organ_id' => 'organ-g',
                        'evidence_strength' => 0.05,
                        'has_replacement_owner' => true,
                        'has_test_coverage' => true,
                        'line_count' => 50,
                        'capability_labels' => ['x'],
                    ],
                ],
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertContains('organ-g', $decoded['first_safe_consolidation_wave']);
        $this->assertArrayHasKey('handoff_count_before', $decoded['task_feed_impact']);
    }
}
