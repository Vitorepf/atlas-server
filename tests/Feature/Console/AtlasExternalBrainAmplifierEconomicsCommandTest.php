<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasExternalBrainAmplifierEconomicsCommandTest extends TestCase
{
    private string $tempBase = '';

    private string $inputFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempBase = sys_get_temp_dir().'/atlas-amplifier-economics-cli-'.bin2hex(random_bytes(6));
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
        $exit = $kernel->call('atlas:external-brain:amplifier-economics', $args);

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

    public function test_empty_sections_produce_conservative_report(): void
    {
        $this->writeInput([]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame('ok', $decoded['status']);
        $this->assertArrayHasKey('telemetry', $decoded);
        $this->assertArrayHasKey('escalation_decision', $decoded);
        $this->assertArrayHasKey('promotion_gate', $decoded);
        $this->assertArrayHasKey('backtest_replay', $decoded);
        $this->assertFalse($decoded['safe_to_expand_autonomy_footprint']);
    }

    public function test_rollback_candidate_telemetry_blocks_autonomy_expansion(): void
    {
        $this->writeInput([
            'telemetry' => [
                'shadow_pass_rate' => 0.10,
                'canary_pass_rate' => 0.10,
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame('rollback_candidate', $decoded['telemetry']['status']);
        $this->assertTrue($decoded['rollback_candidate']);
        $this->assertFalse($decoded['safe_to_expand_autonomy_footprint']);
    }

    public function test_weak_evidence_escalation_defers(): void
    {
        $this->writeInput([
            'escalation' => [
                'ambiguity_score' => 0.5,
                'evidence_quality' => 0.2,
                'scaffold_confidence' => 0.5,
                'prefer_defer_for_weak_evidence' => true,
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame('defer_for_more_evidence', $decoded['escalation_decision']['decision']);
        $this->assertFalse($decoded['safe_to_expand_autonomy_footprint']);
    }

    public function test_promotion_gate_blocks_when_shadow_runs_insufficient(): void
    {
        $this->writeInput([
            'promotion' => [
                'shadow_runs' => 5,
                'sustained_lift_ratio' => 0.5,
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertFalse($decoded['promotion_gate']['promote']);
        $this->assertFalse($decoded['safe_to_expand_autonomy_footprint']);
    }

    public function test_backtest_replay_section_is_reported(): void
    {
        $this->writeInput([
            'replay' => [
                'candidates' => [
                    ['compound_impact_score' => 0.9, 'give_back_risk_score' => 0.1, 'outcome' => 'commit_success'],
                    ['compound_impact_score' => 0.1, 'give_back_risk_score' => 0.9, 'outcome' => 'poison'],
                ],
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertArrayHasKey('precision', $decoded['backtest_replay']);
        $this->assertArrayHasKey('recall', $decoded['backtest_replay']);
    }
}
