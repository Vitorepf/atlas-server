<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Tests\TestCase;

final class AtlasExternalBrainAmplifierEndToEndTrialWiringWiredTest extends TestCase
{
    private string $inputFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->inputFile = sys_get_temp_dir().'/atlas-originator-quality-amplifier-e2e-'.bin2hex(random_bytes(6)).'.json';
    }

    protected function tearDown(): void
    {
        if ($this->inputFile !== '' && is_file($this->inputFile)) {
            @unlink($this->inputFile);
        }
        parent::tearDown();
    }

    public function test_amplifier_end_to_end_trial_is_invoked_when_section_supplied(): void
    {
        file_put_contents($this->inputFile, json_encode([
            'opportunities' => [],
            'amplifier_end_to_end_trial' => [
                'benchmark_dimensions' => [
                    'accuracy' => ['small_model_score' => 0.5, 'scaffolded_score' => 0.9, 'frontier_score' => 0.95],
                ],
            ],
        ], JSON_UNESCAPED_SLASHES));

        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:external-brain:originator-quality', ['--input' => $this->inputFile]);
        $decoded = json_decode($kernel->output(), true);

        $this->assertSame(0, $exit);
        $this->assertArrayHasKey('amplifier_end_to_end_trial', $decoded);
        $this->assertSame('promote_scaffold', $decoded['amplifier_end_to_end_trial']['recommendation']);
    }

    public function test_amplifier_end_to_end_trial_absent_when_section_not_supplied(): void
    {
        file_put_contents($this->inputFile, json_encode([
            'opportunities' => [],
        ], JSON_UNESCAPED_SLASHES));

        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:external-brain:originator-quality', ['--input' => $this->inputFile]);
        $decoded = json_decode($kernel->output(), true);

        $this->assertSame(0, $exit);
        $this->assertArrayNotHasKey('amplifier_end_to_end_trial', $decoded);
    }
}
