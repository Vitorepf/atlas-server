<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasExternalBrainSimplificationBurnDownCommandTest extends TestCase
{
    private string $tempBase = '';

    private string $inputFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempBase = sys_get_temp_dir().'/atlas-simplification-burndown-cli-'.bin2hex(random_bytes(6));
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
        $exit = $kernel->call('atlas:external-brain:simplification-burndown', $args);

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

    public function test_empty_organs_produces_empty_batch(): void
    {
        $this->writeInput([]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame('ok', $decoded['status']);
        $this->assertSame([], $decoded['first_safe_batch']);
        $this->assertSame(0, $decoded['expected_line_delta']);
        $this->assertTrue($decoded['yield_preserved']);
    }

    public function test_low_evidence_organ_with_safety_produces_retire_batch(): void
    {
        $this->writeInput([
            'organs' => [
                [
                    'organ_id' => 'stale_wrapper',
                    'capability_labels' => ['legacy_wrapper'],
                    'evidence_strength' => 0.05,
                    'consumer_count' => 0,
                    'line_count' => 300,
                    'has_replacement_owner' => true,
                    'has_test_coverage' => true,
                    'has_capability_preservation_evidence' => true,
                    'overlap_organs' => [],
                    'claimable_yield' => 2,

                    // Execution safety context (required by the safety runner gate)
                    'pre_image_refs' => ['pre-image-001'],
                    'touched_files' => ['src/Stale.php'],
                    'replay_gates' => ['php artisan test'],
                    'restore_steps' => ['git checkout src/Stale.php'],
                    'behavior_equivalence_proven' => true,
                    'rollback_plan_present' => true,
                    'replacement_owner' => 'NewOwnerService',
                    'rollback_path' => 'git revert HEAD',
                    'canonical_owner' => 'NewOwnerService',
                    'consumers_mapped' => true,
                    'replacement_capability' => true,
                    'replay_proof' => true,
                    'rollback_receipt' => true,
                    'docs_sync' => true,
                    'target_symbol' => 'App\\Stale',
                    'replacement_symbol' => 'App\\NewOwner',
                    'allowed_files' => ['src/Stale.php'],
                    'required_tests' => ['tests/Unit/StaleTest.php'],
                    'runtime_consumers' => [],
                    'public_contract_consumers' => [],
                    'dynamic_consumers' => [],
                    'evidence_refs' => ['test:stale-removed'],
                    'consumer_scan_result' => ['consumers' => []],
                    'parity_decision' => ['equivalent' => true],
                    'public_command_consumers' => [],
                    'command_replay_expectations' => [],
                ],
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame('ok', $decoded['status']);
        $this->assertContains('stale_wrapper', $decoded['first_safe_batch']);
        $this->assertLessThan(0, $decoded['expected_line_delta']);
        $this->assertNotEmpty($decoded['required_tests']);
        $this->assertArrayHasKey('task_feed_impact', $decoded);
        $this->assertTrue($decoded['yield_preserved']);
    }

    public function test_merge_that_would_drop_yield_without_compensation_is_blocked(): void
    {
        $this->writeInput([
            'organs' => [
                [
                    'organ_id' => 'organ_a',
                    'capability_labels' => ['x'],
                    'evidence_strength' => 0.9,
                    'consumer_count' => 5,
                    'line_count' => 100,
                    'has_replacement_owner' => true,
                    'has_test_coverage' => true,
                    'overlap_organs' => ['organ_b'],
                    'claimable_yield' => 10,
                    'merge_expected_yield_after' => 2,
                ],
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame('merge_blocked', $decoded['ranked_actions'][0]['action']);
        $this->assertNotContains('organ_a', $decoded['first_safe_batch']);
        $this->assertContains('worker_feed_or_yield_proof', $decoded['required_prework_by_organ']['organ_a']);
    }

    public function test_retire_blocked_organ_reports_replacement_owner_and_behavior_coverage_prework(): void
    {
        $this->writeInput([
            'organs' => [
                [
                    'organ_id' => 'stale_no_owner',
                    'capability_labels' => ['legacy'],
                    'evidence_strength' => 0.05,
                    'consumer_count' => 0,
                    'line_count' => 200,
                    'has_replacement_owner' => false,
                    'has_test_coverage' => false,
                    'overlap_organs' => [],
                ],
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame('retire_blocked', $decoded['ranked_actions'][0]['action']);
        $this->assertNotContains('stale_no_owner', $decoded['first_safe_batch']);
        $this->assertContains('replacement_owner', $decoded['required_prework_by_organ']['stale_no_owner']);
        $this->assertContains('behavior_coverage', $decoded['required_prework_by_organ']['stale_no_owner']);
    }

    public function test_first_safe_batch_organs_have_no_required_prework_entry(): void
    {
        $this->writeInput([
            'organs' => [
                [
                    'organ_id' => 'stale_wrapper',
                    'capability_labels' => ['legacy_wrapper'],
                    'evidence_strength' => 0.05,
                    'consumer_count' => 0,
                    'line_count' => 300,
                    'has_replacement_owner' => true,
                    'has_test_coverage' => true,
                    'has_capability_preservation_evidence' => true,
                    'overlap_organs' => [],
                    'claimable_yield' => 2,

                    // Execution safety context
                    'pre_image_refs' => ['pre-image-001'],
                    'touched_files' => ['src/Stale.php'],
                    'replay_gates' => ['php artisan test'],
                    'restore_steps' => ['git checkout src/Stale.php'],
                    'behavior_equivalence_proven' => true,
                    'rollback_plan_present' => true,
                    'replacement_owner' => 'NewOwnerService',
                    'rollback_path' => 'git revert HEAD',
                    'canonical_owner' => 'NewOwnerService',
                    'consumers_mapped' => true,
                    'replacement_capability' => true,
                    'replay_proof' => true,
                    'rollback_receipt' => true,
                    'docs_sync' => true,
                    'target_symbol' => 'App\\Stale',
                    'replacement_symbol' => 'App\\NewOwner',
                    'allowed_files' => ['src/Stale.php'],
                    'required_tests' => ['tests/Unit/StaleTest.php'],
                    'runtime_consumers' => [],
                    'public_contract_consumers' => [],
                    'dynamic_consumers' => [],
                    'evidence_refs' => ['test:stale-removed'],
                    'consumer_scan_result' => ['consumers' => []],
                    'parity_decision' => ['equivalent' => true],
                    'public_command_consumers' => [],
                    'command_replay_expectations' => [],
                ],
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertArrayNotHasKey('stale_wrapper', $decoded['required_prework_by_organ']);
    }
}
