<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Tests\TestCase;

final class AtlasExternalBrainAmplifierFallbackRunbookWiringWiredTest extends TestCase
{
    private string $inputFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->inputFile = sys_get_temp_dir().'/atlas-originator-quality-amplifier-fallback-'.bin2hex(random_bytes(6)).'.json';
    }

    protected function tearDown(): void
    {
        if ($this->inputFile !== '' && is_file($this->inputFile)) {
            @unlink($this->inputFile);
        }
        parent::tearDown();
    }

    public function test_amplifier_fallback_runbook_is_invoked_when_section_supplied(): void
    {
        file_put_contents($this->inputFile, json_encode([
            'opportunities' => [],
            'amplifier_fallback_runbook' => [
                'context_assembly' => ['file_a.php'],
                'benchmark_results' => [
                    ['metric' => 'accuracy', 'actual' => 0.4, 'threshold' => 0.8, 'passed' => false],
                ],
            ],
        ], JSON_UNESCAPED_SLASHES));

        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:external-brain:originator-quality', ['--input' => $this->inputFile]);
        $decoded = json_decode($kernel->output(), true);

        $this->assertSame(0, $exit);
        $this->assertArrayHasKey('amplifier_fallback_runbook', $decoded);
        $this->assertTrue($decoded['amplifier_fallback_runbook']['escalation_recommended']);
        $this->assertContains('benchmark_miss', $decoded['amplifier_fallback_runbook']['escalation_triggers']);
    }

    public function test_amplifier_fallback_runbook_absent_when_section_not_supplied(): void
    {
        file_put_contents($this->inputFile, json_encode([
            'opportunities' => [],
        ], JSON_UNESCAPED_SLASHES));

        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:external-brain:originator-quality', ['--input' => $this->inputFile]);
        $decoded = json_decode($kernel->output(), true);

        $this->assertSame(0, $exit);
        $this->assertArrayNotHasKey('amplifier_fallback_runbook', $decoded);
    }
}
