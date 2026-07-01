<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasExternalBrainOriginatorQualityCommandTest extends TestCase
{
    private string $inputPath;

    protected function tearDown(): void
    {
        if (isset($this->inputPath) && is_file($this->inputPath)) {
            unlink($this->inputPath);
        }
        parent::tearDown();
    }

    private function writeInput(array $payload): string
    {
        $this->inputPath = tempnam(sys_get_temp_dir(), 'originator_quality_input_').'.json';
        file_put_contents($this->inputPath, (string) json_encode($payload));

        return $this->inputPath;
    }

    private function callCommand(array $payload): array
    {
        $path = $this->writeInput($payload);
        Artisan::call('atlas:external-brain:originator-quality', ['--input' => $path]);
        $raw = trim(Artisan::output());
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, "Command output is not valid JSON:\n{$raw}");

        return $decoded;
    }

    private function validOpportunity(string $id, array $overrides = []): array
    {
        return array_merge([
            'label' => "opp_{$id}",
            'objective' => "Implement and harden the {$id} subsystem boundary for real",
            'allowed_files' => ["app/Services/{$id}.php", "tests/Unit/{$id}Test.php"],
            'acceptance_criteria' => [
                "./vendor/bin/phpunit tests/Unit/{$id}Test.php",
                'no regression in dependent tests',
            ],
            'required_evidence' => ['tests_or_gates_result'],
            'evidence_refs' => ['tests_or_gates_result'],
            'value_mechanism' => "closes_{$id}_capability_gap",
            'category' => 'bug_fix',
            'capability_unlock' => 0.60,
            'dependency_unblock' => 0.50,
            'implementation_evidence' => 0.70,
            'repeated_pain' => 0.30,
            'blast_radius_safety' => 0.80,
        ], $overrides);
    }

    public function test_missing_input_option_fails(): void
    {
        $exitCode = Artisan::call('atlas:external-brain:originator-quality');
        $this->assertNotSame(0, $exitCode);
    }

    public function test_valid_opportunity_is_emitted_in_the_batch(): void
    {
        $result = $this->callCommand(['opportunities' => [$this->validOpportunity('a')]]);

        $this->assertFalse($result['critique_blocking']);
        $this->assertSame(1, $result['batch_stats']['emitted_count']);
        $this->assertSame('opp_a', $result['emitted_batch'][0]['task_packet_id']);
    }

    public function test_proxy_objective_is_blocked_by_critique_before_scoring(): void
    {
        $result = $this->callCommand(['opportunities' => [
            $this->validOpportunity('a', ['objective' => 'cleanup unused imports in module a']),
        ]]);

        $this->assertTrue($result['critique_blocking']);
        $this->assertSame(0, $result['leverage_ranked_count']);
        $this->assertSame(0, $result['batch_stats']['emitted_count']);
    }

    public function test_operator_dependency_is_blocked_by_critique(): void
    {
        $result = $this->callCommand(['opportunities' => [
            $this->validOpportunity('a', ['acceptance_criteria' => ['human confirms the fix looks right']]),
        ]]);

        $this->assertTrue($result['critique_blocking']);
        $this->assertSame(0, $result['batch_stats']['emitted_count']);
    }

    public function test_missing_runnable_command_is_rejected_by_coverage_matrix(): void
    {
        $result = $this->callCommand(['opportunities' => [
            $this->validOpportunity('a', ['acceptance_criteria' => ['looks correct to me', 'no regressions']]),
        ]]);

        $this->assertFalse($result['critique_blocking']);
        $this->assertCount(1, $result['coverage_rejections']);
        $this->assertSame(0, $result['batch_stats']['emitted_count']);
    }

    public function test_two_high_leverage_candidates_are_ranked_and_both_emitted(): void
    {
        $result = $this->callCommand(['opportunities' => [
            $this->validOpportunity('low', ['capability_unlock' => 0.20]),
            $this->validOpportunity('high', ['capability_unlock' => 0.90, 'implementation_evidence' => 0.90]),
        ]]);

        $this->assertSame(2, $result['batch_stats']['emitted_count']);
        $this->assertSame('opp_high', $result['emitted_batch'][0]['task_packet_id']);
    }

    public function test_bounded_batch_respects_max_batch_cap(): void
    {
        $opportunities = [];
        for ($i = 0; $i < 5; $i++) {
            $opportunities[] = $this->validOpportunity("cap{$i}");
        }

        $result = $this->callCommand(['opportunities' => $opportunities, 'max_batch' => 2]);

        $this->assertSame(2, $result['batch_stats']['emitted_count']);
        $this->assertGreaterThanOrEqual(3, count($result['batch_rejected']));
    }
}
