<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Console\Commands\AtlasSelfConstructionContinuousRuntimeCommand;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasSelfConstructionContinuousRuntimeCommandTest extends TestCase
{
    private string $factsPath = '';

    protected function tearDown(): void
    {
        if ($this->factsPath !== '' && is_file($this->factsPath)) {
            @unlink($this->factsPath);
        }
        parent::tearDown();
    }

    public function test_plan_action_emits_native_runtime_owner_and_evidence_obligations(): void
    {
        $payload = $this->runJson(['action' => 'plan']);

        self::assertSame('atlas_native', $payload['final_runtime_owner']);
        self::assertIsArray($payload['actions']);
        self::assertContains('cycle', $payload['actions']);
        self::assertContains('tests_or_gates_result', $payload['evidence_obligations']);
        self::assertTrue($payload['safety']['no_provider_calls']);
    }

    public function test_replenish_action_runs_with_facts(): void
    {
        $facts = $this->writeFacts([
            'queue_depth' => 0,
            'malformed_count' => 0,
            'safety_stop' => false,
        ]);

        $payload = $this->runJson(['action' => 'replenish', '--facts' => $facts]);

        self::assertSame('atlas_native', $payload['final_runtime_owner']);
        self::assertArrayHasKey('result', $payload);
    }

    public function test_worker_action_runs_with_packet_facts(): void
    {
        $facts = $this->writeFacts([
            'packet' => [
                'task_packet_id' => 'demo-1',
                'allowed_files' => ['app/Foo.php'],
                'objective' => 'demo',
            ],
        ]);

        $payload = $this->runJson(['action' => 'worker', '--facts' => $facts]);

        self::assertSame('atlas_native', $payload['final_runtime_owner']);
        self::assertArrayHasKey('result', $payload);
    }

    public function test_verify_merge_action_runs_with_evidence(): void
    {
        $facts = $this->writeFacts([
            'worker_evidence' => ['allowed_files' => ['x'], 'patches' => []],
            'verification_verdict' => ['verified' => true],
            'rollback_plan' => [],
        ]);

        $payload = $this->runJson(['action' => 'verify-merge', '--facts' => $facts]);

        self::assertArrayHasKey('verification_request', $payload['result']);
        self::assertArrayHasKey('merge_decision', $payload['result']);
    }

    public function test_learn_action_runs_with_outcomes(): void
    {
        $facts = $this->writeFacts([
            'outcomes' => [
                ['class' => 'verification', 'occurrence_count' => 2],
            ],
        ]);

        $payload = $this->runJson(['action' => 'learn', '--facts' => $facts]);

        self::assertSame('atlas_native', $payload['final_runtime_owner']);
        self::assertArrayHasKey('result', $payload);
    }

    public function test_cycle_action_returns_bounded_result_with_runtime_owner_and_stop_reason(): void
    {
        $facts = $this->writeFacts([
            'cycle_id' => 'cycle-test-1',
            'health' => [
                'safety_stop' => false,
                'queue_health' => ['malformed_count' => 0, 'depth' => 0],
                'claimable_packet' => null,
            ],
        ]);

        $payload = $this->runJson(['action' => 'cycle', '--facts' => $facts]);

        self::assertSame('atlas_native', $payload['final_runtime_owner']);
        self::assertSame('no_claimable_task', $payload['result']['stop_reason']);
        self::assertTrue($payload['result']['stopped']);
        self::assertContains('tests_or_gates_result', $payload['evidence_obligations']);
    }

    public function test_cycle_action_honours_safety_stop(): void
    {
        $facts = $this->writeFacts([
            'cycle_id' => 'cycle-safety',
            'health' => [
                'safety_stop' => true,
                'safety_reasons' => ['master_switch_off'],
            ],
        ]);

        $payload = $this->runJson(['action' => 'cycle', '--facts' => $facts]);

        self::assertSame('safety_stop', $payload['result']['stop_reason']);
        self::assertTrue($payload['result']['stopped']);
    }

    public function test_malformed_facts_payload_fails_with_usage_exit_code(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'atlas-cont-runtime-');
        $this->factsPath = $path;
        file_put_contents($path, '{this is not json');

        $exit = Artisan::call('atlas:self-construction:continuous-runtime', [
            'action' => 'cycle',
            '--facts' => $path,
            '--json' => true,
        ]);

        self::assertSame(AtlasSelfConstructionContinuousRuntimeCommand::EXIT_USAGE, $exit);
    }

    public function test_unknown_action_fails_with_usage_exit_code(): void
    {
        $exit = Artisan::call('atlas:self-construction:continuous-runtime', [
            'action' => 'bogus',
            '--json' => true,
        ]);

        self::assertSame(AtlasSelfConstructionContinuousRuntimeCommand::EXIT_USAGE, $exit);
    }

    public function test_command_source_does_not_call_subprocess_or_provider(): void
    {
        $src = (string) file_get_contents(base_path('app/Console/Commands/AtlasSelfConstructionContinuousRuntimeCommand.php'));
        foreach (['shell_exec', 'exec(', 'system(', 'proc_open', 'curl_', 'Http::'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $src, "command must not contain {$forbidden}");
        }
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    private function runJson(array $params): array
    {
        $params['--json'] = true;
        $buffer = new \Symfony\Component\Console\Output\BufferedOutput();
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $exit = $kernel->call('atlas:self-construction:continuous-runtime', $params, $buffer);
        $output = trim($buffer->fetch());
        self::assertSame(AtlasSelfConstructionContinuousRuntimeCommand::EXIT_OK, $exit, 'command exited non-zero: '.$output);
        $decoded = json_decode($output, true);
        self::assertIsArray($decoded, 'command did not emit JSON: '.$output);

        return $decoded;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function writeFacts(array $payload): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'atlas-cont-runtime-');
        $this->factsPath = $path;
        file_put_contents($path, (string) json_encode($payload));

        return $path;
    }
}
