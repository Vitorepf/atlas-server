<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Console\Commands\AtlasTaskMaestroRetryCommand;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasTaskMaestroRetryCommandTest extends TestCase
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
        $this->inputPath = tempnam(sys_get_temp_dir(), 'maestro_retry_input_').'.json';
        file_put_contents($this->inputPath, (string) json_encode($payload));

        return $this->inputPath;
    }

    public function test_evidence_returns_empty_facts_with_exit_zero_and_no_score_field(): void
    {
        $exit = Artisan::call('atlas:task:maestro:retry', ['action' => 'evidence', '--json' => true]);
        $this->assertSame(0, $exit);

        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertSame('evidence', $payload['action']);
        $this->assertIsArray($payload['facts']);

        foreach ($payload['facts'] as $row) {
            foreach (['score', 'rank', 'rating', 'quality'] as $forbidden) {
                $this->assertArrayNotHasKey($forbidden, (array) $row, 'forbidden aggregate field present: '.$forbidden);
            }
            // Required FACT counters per the contract.
            foreach (['attempts', 'successes', 'failures'] as $required) {
                $this->assertArrayHasKey($required, (array) $row);
            }
        }
    }

    public function test_bogus_action_exits_2_and_names_the_four_valid_actions(): void
    {
        $exit = Artisan::call('atlas:task:maestro:retry', ['action' => 'bogus', '--json' => true]);
        $this->assertSame(2, $exit);

        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertSame('refused', $payload['outcome']);
        $this->assertSame('unknown_action', $payload['reason']);
        foreach (['inspect', 'policy', 'evidence', 'history'] as $expected) {
            $this->assertContains($expected, (array) $payload['valid_actions']);
        }
    }

    public function test_policy_and_history_are_read_only(): void
    {
        $policy = Artisan::call('atlas:task:maestro:retry', ['action' => 'policy', '--json' => true]);
        $this->assertSame(0, $policy);

        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertSame('policy', $payload['action']);
        $this->assertArrayHasKey('max_retries', (array) $payload['policy']);

        // Read-only invariant: source file mentions no write/append/provider call.
        $src = (string) file_get_contents(
            (new \ReflectionClass(AtlasTaskMaestroRetryCommand::class))->getFileName(),
        );
        foreach (['->append(', 'AiGatewayService', 'AiProviderManager'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, 'forbidden write/provider call: '.$forbidden);
        }
    }

    // ── inspect + advisory root_cause_summary ──

    public function test_inspect_without_packet_id_or_input_is_refused(): void
    {
        $exit = Artisan::call('atlas:task:maestro:retry', ['action' => 'inspect', '--json' => true]);
        $this->assertSame(1, $exit);

        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertSame('refused', $payload['outcome']);
        $this->assertSame('packet_id_required', $payload['reason']);
    }

    public function test_inspect_with_input_groups_repeated_give_backs_by_root_cause(): void
    {
        $path = $this->writeInput(['give_backs' => [
            ['task_id' => 't1', 'root_cause' => 'missing_scope: no allowed_files declared'],
            ['task_id' => 't2', 'root_cause' => 'missing_scope: file not found'],
            ['task_id' => 't3', 'root_cause' => 'protected_target: forbidden self-target'],
            ['task_id' => 't4', 'root_cause' => 'baseline_test_failure: red before edit'],
            ['task_id' => 't5', 'root_cause' => 'unclear_acceptance: vague criteria'],
        ]]);

        $exit = Artisan::call('atlas:task:maestro:retry', ['action' => 'inspect', '--input' => $path, '--json' => true]);
        $this->assertSame(0, $exit);

        $payload = json_decode(trim(Artisan::output()), true);
        $summary = $payload['root_cause_summary'];
        $this->assertSame(5, $summary['total_give_backs']);
        $this->assertSame(2, $summary['by_cause']['missing_scope']);
        $this->assertSame(1, $summary['by_cause']['protected_target']);
        $this->assertSame(1, $summary['by_cause']['baseline_test_failure']);
        $this->assertSame(1, $summary['by_cause']['unclear_acceptance']);
        $this->assertTrue($summary['advisory_only']);
    }

    public function test_inspect_root_cause_summary_never_enqueues_a_retry(): void
    {
        $path = $this->writeInput(['give_backs' => [
            ['task_id' => 't1', 'root_cause' => 'protected_target'],
        ]]);

        Artisan::call('atlas:task:maestro:retry', ['action' => 'inspect', '--input' => $path, '--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertFalse($payload['root_cause_summary']['auto_retry_enqueued']);

        $src = (string) file_get_contents(
            (new \ReflectionClass(AtlasTaskMaestroRetryCommand::class))->getFileName(),
        );
        foreach (['->append(', 'AiGatewayService', 'AiProviderManager'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, 'forbidden write/provider call: '.$forbidden);
        }
    }

    public function test_inspect_with_input_and_packet_id_includes_both_summary_and_last_decision(): void
    {
        $path = $this->writeInput(['give_backs' => [
            ['task_id' => 't1', 'root_cause' => 'missing_scope'],
        ]]);

        $exit = Artisan::call('atlas:task:maestro:retry', [
            'action' => 'inspect',
            '--packet-id' => 'nonexistent-packet',
            '--input' => $path,
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertArrayHasKey('root_cause_summary', $payload);
        $this->assertArrayHasKey('last_decision', $payload);
        $this->assertSame('nonexistent-packet', $payload['packet_id']);
    }
}
