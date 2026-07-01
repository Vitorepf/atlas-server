<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Tests\TestCase;

final class AtlasExternalBrainBlindSpotCurriculumWiringWiredTest extends TestCase
{
    private string $inputFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->inputFile = sys_get_temp_dir().'/atlas-originator-quality-blind-spot-'.bin2hex(random_bytes(6)).'.json';
    }

    protected function tearDown(): void
    {
        if ($this->inputFile !== '' && is_file($this->inputFile)) {
            @unlink($this->inputFile);
        }
        parent::tearDown();
    }

    public function test_blind_spot_curriculum_build_is_invoked_when_failure_observations_supplied(): void
    {
        file_put_contents($this->inputFile, json_encode([
            'opportunities' => [],
            'blind_spot_curriculum' => [
                'failure_observations' => [
                    ['failure_type' => 'proxy_risk', 'run_id' => 'r1', 'task_class' => 'a'],
                    ['failure_type' => 'proxy_risk', 'run_id' => 'r2', 'task_class' => 'b'],
                ],
            ],
        ], JSON_UNESCAPED_SLASHES));

        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:external-brain:originator-quality', ['--input' => $this->inputFile]);
        $decoded = json_decode($kernel->output(), true);

        $this->assertSame(0, $exit);
        $this->assertArrayHasKey('blind_spot_curriculum', $decoded);
        $this->assertArrayHasKey('curriculum', $decoded['blind_spot_curriculum']);
        $this->assertSame('proxy_risk', $decoded['blind_spot_curriculum']['curriculum']['promoted_blind_spots'][0]['type']);
    }

    public function test_blind_spot_curriculum_rank_is_invoked_when_blind_spots_supplied(): void
    {
        file_put_contents($this->inputFile, json_encode([
            'opportunities' => [],
            'blind_spot_curriculum' => [
                'blind_spots' => [
                    ['blind_spot_id' => 'bs1', 'group' => 'bad_scope', 'recurrence_count' => 5, 'future_quality_lift_estimate' => 0.9],
                ],
            ],
        ], JSON_UNESCAPED_SLASHES));

        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:external-brain:originator-quality', ['--input' => $this->inputFile]);
        $decoded = json_decode($kernel->output(), true);

        $this->assertSame(0, $exit);
        $this->assertArrayHasKey('ranked_learning_items', $decoded['blind_spot_curriculum']);
        $this->assertSame('bs1', $decoded['blind_spot_curriculum']['ranked_learning_items']['ranked_learning_items'][0]['blind_spot_id']);
    }

    public function test_blind_spot_curriculum_absent_when_section_not_supplied(): void
    {
        file_put_contents($this->inputFile, json_encode([
            'opportunities' => [],
        ], JSON_UNESCAPED_SLASHES));

        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:external-brain:originator-quality', ['--input' => $this->inputFile]);
        $decoded = json_decode($kernel->output(), true);

        $this->assertSame(0, $exit);
        $this->assertArrayNotHasKey('blind_spot_curriculum', $decoded);
    }
}
