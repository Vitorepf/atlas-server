<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasExternalBrainCognitiveWorkPartitionerWiringWiredTest extends TestCase
{
    private string $tempBase = '';

    private string $inputFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempBase = sys_get_temp_dir().'/atlas-originator-quality-cwp-'.bin2hex(random_bytes(6));
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

    public function test_cognitive_work_partitioner_section_assigns_small_model_tier_to_extraction(): void
    {
        $this->writeInput([
            'opportunities' => [],
            'cognitive_work_partitioner' => [
                'phases' => [
                    ['id' => 'phase_1', 'description' => 'gather evidence from the ledger'],
                ],
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame('extraction', $decoded['cognitive_work_partitioner']['phase_plan'][0]['phase_type']);
        $this->assertSame('small_model', $decoded['cognitive_work_partitioner']['phase_plan'][0]['model_tier']);
        $this->assertSame([], $decoded['cognitive_work_partitioner']['escalation_points']);
    }

    public function test_cognitive_work_partitioner_section_is_absent_when_not_supplied(): void
    {
        $this->writeInput(['opportunities' => []]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertArrayNotHasKey('cognitive_work_partitioner', $decoded);
    }
}
