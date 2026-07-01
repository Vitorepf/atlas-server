<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasExternalBrainModelAmplifierCommandTest extends TestCase
{
    private string $tempBase = '';

    private string $inputFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempBase = sys_get_temp_dir().'/atlas-model-amplifier-cli-'.bin2hex(random_bytes(6));
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
        $exit = $kernel->call('atlas:external-brain:model-amplifier', $args);

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

    public function test_invalid_json_fails(): void
    {
        file_put_contents($this->inputFile, 'not json');

        [$exit] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(1, $exit);
    }

    public function test_empty_sections_produce_clean_no_escalation_report(): void
    {
        $this->writeInput([]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame('ok', $decoded['status']);
        $this->assertFalse($decoded['overfit_detected']);
        $this->assertFalse($decoded['amplifier_escalation']['escalate']);
    }

    public function test_proven_lift_with_clean_scaffold_is_safe_to_operate_autonomously(): void
    {
        $this->writeInput([
            'amplifier' => [
                'model_size' => 'small',
                'confidence' => 0.95,
                'proxy_leak_detected' => false,
                'architectural_risk' => 'low',
                'baseline_pass_rate' => 0.40,
                'scaffolded_pass_rate' => 0.85,
                'heldout_sample_size' => 20,
            ],
            'scaffold_metrics' => [
                [
                    'variant_id' => 'v1',
                    'gate_pass_rate' => 0.90,
                    'commit_success_rate' => 0.90,
                    'value_proof_rate' => 0.90,
                    'diversity_score' => 0.80,
                    'compounding_impact' => 0.80,
                    'structural_leverage_score' => 0.80,
                    'give_back_rate' => 0.05,
                    'poison_rate' => 0.0,
                    'benchmark_pass_rate' => 0.90,
                    'heldout_pass_rate' => 0.85,
                ],
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertTrue($decoded['autonomous_execution_allowed']);
        $this->assertFalse($decoded['overfit_detected']);
        $this->assertFalse($decoded['must_escalate']);
        $this->assertTrue($decoded['safe_to_operate_autonomously']);
    }

    public function test_overfit_scaffold_forces_escalation_even_when_amplifier_is_clean(): void
    {
        $this->writeInput([
            'amplifier' => [
                'model_size' => 'small',
                'confidence' => 0.95,
                'proxy_leak_detected' => false,
                'architectural_risk' => 'low',
                'baseline_pass_rate' => 0.40,
                'scaffolded_pass_rate' => 0.85,
                'heldout_sample_size' => 20,
            ],
            'scaffold_metrics' => [
                [
                    'variant_id' => 'v-template',
                    'template_repetition_score' => 0.95,
                ],
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertFalse($decoded['amplifier_escalation']['escalate']);
        $this->assertTrue($decoded['overfit_detected']);
        $this->assertTrue($decoded['must_escalate']);
        $this->assertFalse($decoded['safe_to_operate_autonomously']);
        $this->assertSame('retire_template', $decoded['overfit_recommended_action']);
    }

    public function test_proxy_leak_forces_amplifier_escalation(): void
    {
        $this->writeInput([
            'amplifier' => [
                'model_size' => 'mid',
                'confidence' => 0.95,
                'proxy_leak_detected' => true,
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertTrue($decoded['amplifier_escalation']['escalate']);
        $this->assertTrue($decoded['must_escalate']);
        $this->assertFalse($decoded['safe_to_operate_autonomously']);
    }
}
