<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasExternalBrainCapabilityProofMapCommandTest extends TestCase
{
    private string $tempBase = '';

    private string $inputFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempBase = sys_get_temp_dir().'/atlas-capability-proof-map-cli-'.bin2hex(random_bytes(6));
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
        $exit = $kernel->call('atlas:external-brain:capability-proof-map', $args);

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

    public function test_empty_sections_produce_empty_proof_map(): void
    {
        $this->writeInput([]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame([], $decoded['capability_proof_map']);
        $this->assertSame([], $decoded['summary']);
    }

    public function test_not_implemented_capability_is_flagged(): void
    {
        $this->writeInput([
            'capabilities' => [
                ['id' => 'cap-a', 'is_implemented' => false, 'is_wired' => false],
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame('cap-a', $decoded['capability_proof_map'][0]['capability_id']);
        $this->assertSame('not_implemented', $decoded['capability_proof_map'][0]['final_status']);
    }

    public function test_implemented_wired_and_proven_capability_is_fully_proven(): void
    {
        $this->writeInput([
            'capabilities' => [
                ['id' => 'cap-b', 'is_implemented' => true, 'is_wired' => true],
            ],
            'capability_evidence' => [
                [
                    'capability_id' => 'cap-b',
                    'evidence' => [['type' => 'runnable_test_or_gate', 'age_days' => 1]],
                    'downstream_uses' => ['consumer_x'],
                ],
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $entry = $decoded['capability_proof_map'][0];
        $this->assertSame('cap-b', $entry['capability_id']);
        $this->assertSame('implemented_and_wired', $entry['final_status']);
    }

    public function test_capability_with_no_evidence_is_blocked_by_missing_evidence(): void
    {
        $this->writeInput([
            'capabilities' => [
                ['id' => 'cap-c', 'is_implemented' => true, 'is_wired' => true],
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame('blocked_by_missing_evidence', $decoded['capability_proof_map'][0]['final_status']);
    }

    public function test_stale_evidence_capability_is_flagged_stale(): void
    {
        $this->writeInput([
            'capabilities' => [
                ['id' => 'cap-d', 'is_implemented' => true, 'is_wired' => true],
            ],
            'capability_evidence' => [
                [
                    'capability_id' => 'cap-d',
                    'evidence' => [['type' => 'runnable_test_or_gate', 'age_days' => 90]],
                    'downstream_uses' => ['consumer_x'],
                ],
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame('stale_evidence', $decoded['capability_proof_map'][0]['final_status']);
    }

    public function test_capability_with_evidence_but_no_downstream_use_is_unproven_for_leverage(): void
    {
        $this->writeInput([
            'capabilities' => [
                ['id' => 'cap-e', 'is_implemented' => true, 'is_wired' => true],
            ],
            'capability_evidence' => [
                [
                    'capability_id' => 'cap-e',
                    'evidence' => [['type' => 'runnable_test_or_gate', 'age_days' => 1]],
                    'downstream_uses' => [],
                ],
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame('unproven_for_leverage', $decoded['capability_proof_map'][0]['final_status']);
    }

    public function test_debt_ledger_and_integration_map_sections_are_included(): void
    {
        $this->writeInput([
            'capabilities' => [
                ['id' => 'cap-f', 'is_implemented' => true, 'is_wired' => false],
            ],
            'debt_records' => [
                ['debt_id' => 'debt-1', 'capability_dimension' => 'weak_scoring', 'leverage' => 3, 'risk' => 2],
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertArrayHasKey('integration_map', $decoded);
        $this->assertArrayHasKey('capability_debt_ledger', $decoded);
        $this->assertTrue($decoded['capability_debt_ledger']['maturity_blocked']);
        $this->assertSame(1, $decoded['capability_debt_ledger']['unresolved_count']);
    }
}
