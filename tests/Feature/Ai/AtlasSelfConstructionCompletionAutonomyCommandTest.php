<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Console\Commands\AtlasSelfConstructionCompletionAutonomyCommand;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Tests\TestCase;

final class AtlasSelfConstructionCompletionAutonomyCommandTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function fixture(array $payload): string
    {
        $path = sys_get_temp_dir().'/atlas-completion-facts-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($path, (string) json_encode($payload));
        $this->tempFiles[] = $path;

        return $path;
    }

    private function runCmd(array $args): array
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:self-construction:completion-autonomy', $args);

        return [$exit, $kernel->output()];
    }

    public function test_audit_action_returns_audit_verdict_with_canonical_labels(): void
    {
        $path = $this->fixture([
            'evidence' => [
                ['step_id' => 'observe', 'kind' => 'steady_state', 'role' => 'atlas_native'],
            ],
        ]);
        [$exit, $out] = $this->runCmd(['action' => 'audit', '--facts' => $path, '--json' => true]);
        $this->assertSame(AtlasSelfConstructionCompletionAutonomyCommand::EXIT_OK, $exit);
        $decoded = json_decode(trim($out), true);
        $this->assertTrue($decoded['atlas_native']);
        $this->assertSame('atlas_native', $decoded['final_runtime_owner']);
        $this->assertSame('atlas_server', $decoded['steady_state_runtime_owner']);
    }

    public function test_transition_map_action_emits_replacement_actions(): void
    {
        $path = $this->fixture([
            'evidence' => [
                ['step_id' => 'verify_release', 'kind' => 'steady_state', 'role' => 'operator'],
            ],
        ]);
        [$exit, $out] = $this->runCmd(['action' => 'transition-map', '--facts' => $path, '--json' => true]);
        $this->assertSame(AtlasSelfConstructionCompletionAutonomyCommand::EXIT_OK, $exit);
        $decoded = json_decode(trim($out), true);
        $this->assertNotEmpty($decoded['replacements']);
        $this->assertSame('verify_release', $decoded['replacements'][0]['step_id']);
    }

    public function test_policy_action_echoes_readiness(): void
    {
        $path = $this->fixture(['readiness' => ['state' => 'ready']]);
        [$exit, $out] = $this->runCmd(['action' => 'policy', '--facts' => $path, '--json' => true]);
        $this->assertSame(AtlasSelfConstructionCompletionAutonomyCommand::EXIT_OK, $exit);
        $decoded = json_decode(trim($out), true);
        $this->assertSame(['state' => 'ready'], $decoded['policy_echo']);
    }

    public function test_verdict_action_routes_blocked_dependencies_into_atlas_native_next_actions(): void
    {
        $path = $this->fixture([
            'evidence' => [
                ['step_id' => 'verify_release', 'kind' => 'steady_state', 'role' => 'operator'],
            ],
            'readiness' => ['state' => 'ready'],
        ]);
        [$exit, $out] = $this->runCmd(['action' => 'verdict', '--facts' => $path, '--json' => true]);
        $this->assertSame(AtlasSelfConstructionCompletionAutonomyCommand::EXIT_OK, $exit);
        $decoded = json_decode(trim($out), true);
        $this->assertSame('unsafe', $decoded['verdict']);
        $this->assertFalse($decoded['asks_for_human']);
        $this->assertContains('create_task_packets:run_verification', $decoded['next_atlas_actions']);
    }

    public function test_missing_facts_path_fails_closed(): void
    {
        [$exit] = $this->runCmd(['action' => 'audit']);
        $this->assertSame(AtlasSelfConstructionCompletionAutonomyCommand::EXIT_USAGE, $exit);
    }

    public function test_unknown_action_fails_closed(): void
    {
        $path = $this->fixture([]);
        [$exit] = $this->runCmd(['action' => 'BOGUS', '--facts' => $path]);
        $this->assertSame(AtlasSelfConstructionCompletionAutonomyCommand::EXIT_USAGE, $exit);
    }

    public function test_code_index_readiness_blocks_on_schema_drift(): void
    {
        $path = $this->fixture([
            'code_index' => [
                'schema_drift' => ['passed' => false, 'status' => 'drifted'],
                'automatic_gate' => ['status' => 'ready'],
                'code_status' => ['status' => 'ready', 'indexed_symbols' => 100, 'is_stale' => false],
                'readiness' => ['status' => 'ready', 'blocking_findings' => []],
            ],
        ]);
        [$exit, $out] = $this->runCmd(['action' => 'code-index-readiness', '--facts' => $path, '--json' => true]);
        $this->assertSame(AtlasSelfConstructionCompletionAutonomyCommand::EXIT_OK, $exit);
        $decoded = json_decode(trim($out), true);
        $this->assertSame('blocked', $decoded['status'], (string) $out);
        $this->assertContains('schema_drift_failed:drifted', $decoded['blockers']);
        // canonical owner labels still stamped by the command
        $this->assertSame('atlas_native', $decoded['final_runtime_owner']);
    }

    public function test_code_index_readiness_ready_when_clean(): void
    {
        $path = $this->fixture([
            'code_index' => [
                'schema_drift' => ['passed' => true, 'status' => 'clean'],
                'automatic_gate' => ['status' => 'ready'],
                'code_status' => ['status' => 'ready', 'indexed_symbols' => 100, 'is_stale' => false],
                'readiness' => ['status' => 'ready', 'blocking_findings' => []],
            ],
        ]);
        [$exit, $out] = $this->runCmd(['action' => 'code-index-readiness', '--facts' => $path, '--json' => true]);
        $this->assertSame(AtlasSelfConstructionCompletionAutonomyCommand::EXIT_OK, $exit);
        $decoded = json_decode(trim($out), true);
        $this->assertSame('ready', $decoded['status'], (string) $out);
        $this->assertSame([], $decoded['blockers']);
        $this->assertTrue($decoded['passed']);
    }
}
