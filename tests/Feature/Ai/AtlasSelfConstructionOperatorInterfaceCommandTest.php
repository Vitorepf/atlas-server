<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Console\Commands\AtlasSelfConstructionOperatorInterfaceCommand;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Tests\TestCase;

final class AtlasSelfConstructionOperatorInterfaceCommandTest extends TestCase
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
        $path = sys_get_temp_dir().'/atlas-oi-facts-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($path, (string) json_encode($payload));
        $this->tempFiles[] = $path;

        return $path;
    }

    private function runCmd(array $args): array
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:self-construction:operator-interface', $args);

        return [$exit, $kernel->output()];
    }

    public function test_visibility_action_emits_canonical_labels(): void
    {
        [$exit, $out] = $this->runCmd(['action' => 'visibility', '--json' => true]);
        $this->assertSame(AtlasSelfConstructionOperatorInterfaceCommand::EXIT_OK, $exit);

        $decoded = json_decode(trim($out), true);
        $this->assertSame(AtlasSelfConstructionOperatorInterfaceCommand::FINAL_RUNTIME_OWNER, $decoded['final_runtime_owner']);
        $this->assertSame(AtlasSelfConstructionOperatorInterfaceCommand::INTERFACE_ROLE, $decoded['interface_role']);
        $this->assertNotEmpty($decoded['allowed_emergency_actions']);
        $this->assertNotEmpty($decoded['rejected_ordinary_actions']);
    }

    public function test_snapshot_action_carries_canonical_labels(): void
    {
        $path = $this->fixture([
            'autonomy_mode' => 'execute',
            'queue' => ['pending' => 1, 'leases' => 1, 'backlog' => 0],
            'blockers' => [],
            'organs' => ['cortex' => 'ready'],
            'emergency_state' => 'off',
        ]);

        [$exit, $out] = $this->runCmd(['action' => 'snapshot', '--facts' => $path, '--json' => true]);
        $this->assertSame(AtlasSelfConstructionOperatorInterfaceCommand::EXIT_OK, $exit);
        $decoded = json_decode(trim($out), true);
        $this->assertSame('atlas_native', $decoded['final_runtime_owner']);
        $this->assertSame('visibility_emergency_only', $decoded['interface_role']);
        $this->assertSame('execute', $decoded['autonomy_mode']);
    }

    public function test_emergency_pause_is_accepted(): void
    {
        $path = $this->fixture(['action' => 'pause']);
        [$exit, $out] = $this->runCmd(['action' => 'emergency', '--facts' => $path, '--json' => true]);
        $this->assertSame(AtlasSelfConstructionOperatorInterfaceCommand::EXIT_OK, $exit);
        $decoded = json_decode(trim($out), true);
        $this->assertSame('pause', $decoded['accepted_action']);
    }

    public function test_emergency_ordinary_action_is_rejected(): void
    {
        $path = $this->fixture(['action' => 'merge_to_main']);
        [$exit, $out] = $this->runCmd(['action' => 'emergency', '--facts' => $path, '--json' => true]);
        $this->assertSame(AtlasSelfConstructionOperatorInterfaceCommand::EXIT_OK, $exit);
        $decoded = json_decode(trim($out), true);
        $this->assertSame('merge_to_main', $decoded['rejected_action']);
        $this->assertStringContainsString('ordinary_progress', $decoded['rejection_reason']);
    }

    public function test_dependency_gate_action_returns_audit_verdict(): void
    {
        $path = $this->fixture([
            'evidence' => [
                ['step_id' => 'observe', 'kind' => 'steady_state', 'role' => 'atlas_native'],
                ['step_id' => 'killswitch', 'kind' => 'emergency', 'role' => 'human'],
            ],
        ]);
        [$exit, $out] = $this->runCmd(['action' => 'dependency-gate', '--facts' => $path, '--json' => true]);
        $this->assertSame(AtlasSelfConstructionOperatorInterfaceCommand::EXIT_OK, $exit);
        $decoded = json_decode(trim($out), true);
        $this->assertTrue($decoded['atlas_native']);
    }

    public function test_missing_facts_path_on_facts_actions_fails_closed(): void
    {
        foreach (['snapshot', 'emergency', 'dependency-gate'] as $action) {
            [$exit] = $this->runCmd(['action' => $action]);
            $this->assertSame(AtlasSelfConstructionOperatorInterfaceCommand::EXIT_USAGE, $exit, "{$action} without --facts must fail closed");
        }
    }

    public function test_unknown_action_fails_closed(): void
    {
        [$exit] = $this->runCmd(['action' => 'BOGUS']);
        $this->assertSame(AtlasSelfConstructionOperatorInterfaceCommand::EXIT_USAGE, $exit);
    }
}
