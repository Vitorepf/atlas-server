<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Tests\TestCase;

final class AtlasExternalBrainAutonomyRegressionSentinelWiringWiredTest extends TestCase
{
    private string $inputFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->inputFile = sys_get_temp_dir().'/atlas-originator-quality-regression-sentinel-'.bin2hex(random_bytes(6)).'.json';
    }

    protected function tearDown(): void
    {
        if ($this->inputFile !== '' && is_file($this->inputFile)) {
            @unlink($this->inputFile);
        }
        parent::tearDown();
    }

    public function test_autonomy_regression_scan_is_invoked_when_section_supplied(): void
    {
        file_put_contents($this->inputFile, json_encode([
            'opportunities' => [],
            'autonomy_regression_scan' => [
                'requires_human_approval' => true,
            ],
        ], JSON_UNESCAPED_SLASHES));

        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:external-brain:originator-quality', ['--input' => $this->inputFile]);
        $decoded = json_decode($kernel->output(), true);

        $this->assertSame(0, $exit);
        $this->assertArrayHasKey('autonomy_regression_scan', $decoded);
        $this->assertTrue($decoded['autonomy_regression_scan']['is_regression']);
        $this->assertSame('fail', $decoded['autonomy_regression_scan']['sentinel_verdict']);
        $this->assertContains('human_dependency', $decoded['autonomy_regression_scan']['regression_types']);
    }

    public function test_autonomy_regression_scan_absent_when_section_not_supplied(): void
    {
        file_put_contents($this->inputFile, json_encode([
            'opportunities' => [],
        ], JSON_UNESCAPED_SLASHES));

        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:external-brain:originator-quality', ['--input' => $this->inputFile]);
        $decoded = json_decode($kernel->output(), true);

        $this->assertSame(0, $exit);
        $this->assertArrayNotHasKey('autonomy_regression_scan', $decoded);
    }
}
