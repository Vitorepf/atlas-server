<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasExternalBrainConsolidationFirstCircuitBreakerWiringWiredTest extends TestCase
{
    private string $tempBase = '';

    private string $inputFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempBase = sys_get_temp_dir().'/atlas-originator-quality-cfcb-'.bin2hex(random_bytes(6));
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
        $exit = $kernel->call('atlas:external-brain:originator-quality', $args);

        return [$exit, $kernel->output()];
    }

    public function test_consolidation_first_section_reports_consolidate_first_for_high_sprawl(): void
    {
        $this->writeInput([
            'opportunities' => [],
            'consolidation_first' => [
                'overlap_score' => 0.9,
                'class_growth_count' => 40,
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame('consolidate_first', $decoded['consolidation_first']['decision']);
        $this->assertNotEmpty($decoded['consolidation_first']['consolidation_actions']);
    }

    public function test_consolidation_first_section_is_absent_when_not_supplied(): void
    {
        $this->writeInput(['opportunities' => []]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertArrayNotHasKey('consolidation_first', $decoded);
    }
}
