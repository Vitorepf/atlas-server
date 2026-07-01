<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Tests\TestCase;

final class AtlasExternalBrainAutonomyDependencyInverterWiringWiredTest extends TestCase
{
    private string $inputFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->inputFile = sys_get_temp_dir().'/atlas-originator-quality-dependency-inverter-'.bin2hex(random_bytes(6)).'.json';
    }

    protected function tearDown(): void
    {
        if ($this->inputFile !== '' && is_file($this->inputFile)) {
            @unlink($this->inputFile);
        }
        parent::tearDown();
    }

    public function test_autonomy_dependency_inversion_is_invoked_when_section_supplied(): void
    {
        file_put_contents($this->inputFile, json_encode([
            'opportunities' => [],
            'autonomy_dependency_inversion' => [
                'dependencies' => [
                    ['stage' => 'review', 'dependency_type' => 'human'],
                ],
            ],
        ], JSON_UNESCAPED_SLASHES));

        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:external-brain:originator-quality', ['--input' => $this->inputFile]);
        $decoded = json_decode($kernel->output(), true);

        $this->assertSame(0, $exit);
        $this->assertArrayHasKey('autonomy_dependency_inversion', $decoded);
        $this->assertSame(1, $decoded['autonomy_dependency_inversion']['inversion_count']);
        $this->assertSame('critical', $decoded['autonomy_dependency_inversion']['inversions'][0]['severity']);
    }

    public function test_autonomy_dependency_inversion_absent_when_section_not_supplied(): void
    {
        file_put_contents($this->inputFile, json_encode([
            'opportunities' => [],
        ], JSON_UNESCAPED_SLASHES));

        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:external-brain:originator-quality', ['--input' => $this->inputFile]);
        $decoded = json_decode($kernel->output(), true);

        $this->assertSame(0, $exit);
        $this->assertArrayNotHasKey('autonomy_dependency_inversion', $decoded);
    }
}
