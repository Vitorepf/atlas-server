<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasExternalBrainContextBudgetDistillerWiringWiredTest extends TestCase
{
    private string $tempBase = '';

    private string $inputFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempBase = sys_get_temp_dir().'/atlas-originator-quality-cbd-'.bin2hex(random_bytes(6));
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

    public function test_context_budget_distiller_section_retains_tier1_sections(): void
    {
        $this->writeInput([
            'opportunities' => [],
            'context_budget_distiller' => [
                'budget_tokens' => 100,
                'context_sections' => [
                    ['id' => 'sec1', 'type' => 'canonical_decision', 'content' => 'x', 'signal_score' => 1.0, 'token_count' => 10],
                ],
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame('low', $decoded['context_budget_distiller']['risk_of_loss']);
        $this->assertCount(1, $decoded['context_budget_distiller']['retained_sections']);
        $this->assertSame('sec1', $decoded['context_budget_distiller']['retained_sections'][0]['id']);
    }

    public function test_context_budget_distiller_section_is_absent_when_not_supplied(): void
    {
        $this->writeInput(['opportunities' => []]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertArrayNotHasKey('context_budget_distiller', $decoded);
    }
}
