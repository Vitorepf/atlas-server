<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ControlPlane;

use App\Console\Commands\AtlasSelfConstructionControlPlaneCommand;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Tests\TestCase;

/**
 * Proves AtlasSelfConstructionBrainAuditAutoPriorityPolicy is wired via the new
 * `audit-priority` action on AtlasSelfConstructionControlPlaneCommand, mirroring
 * how the sibling AtlasSelfConstructionAutonomyModePolicy is wired via `policy`.
 */
final class AtlasSelfConstructionBrainAuditAutoPriorityPolicyWiringWiredTest extends TestCase
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
        $path = sys_get_temp_dir().'/atlas-cp-audit-priority-facts-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($path, (string) json_encode($payload));
        $this->tempFiles[] = $path;

        return $path;
    }

    private function runCmd(array $args): array
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:self-construction:control-plane', $args);

        return [$exit, $kernel->output()];
    }

    public function test_audit_priority_action_delegates_to_policy_for_critical_regression(): void
    {
        $path = $this->fixture([
            'audit_snapshot' => ['status' => 'gate_regression', 'regression_severity' => 'critical'],
            'pending_actions' => ['generate_more_tasks'],
        ]);

        [$exit, $out] = $this->runCmd(['action' => 'audit-priority', '--facts' => $path, '--json' => true]);

        $this->assertSame(AtlasSelfConstructionControlPlaneCommand::EXIT_OK, $exit);
        $decoded = json_decode(trim($out), true);
        $this->assertSame('repair_gate', $decoded['selected_action']);
        $this->assertTrue($decoded['priority_override']);
    }

    public function test_audit_priority_action_delegates_to_policy_for_healthy_snapshot(): void
    {
        $path = $this->fixture([
            'audit_snapshot' => ['status' => 'healthy'],
            'pending_actions' => ['generate_more_tasks'],
        ]);

        [$exit, $out] = $this->runCmd(['action' => 'audit-priority', '--facts' => $path, '--json' => true]);

        $this->assertSame(AtlasSelfConstructionControlPlaneCommand::EXIT_OK, $exit);
        $decoded = json_decode(trim($out), true);
        $this->assertSame('generate_more_tasks', $decoded['selected_action']);
        $this->assertFalse($decoded['priority_override']);
    }

    public function test_audit_priority_action_requires_facts_option(): void
    {
        [$exit] = $this->runCmd(['action' => 'audit-priority']);

        $this->assertSame(AtlasSelfConstructionControlPlaneCommand::EXIT_USAGE, $exit);
    }
}
