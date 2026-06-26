<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Console\Commands\AtlasSelfConstructionControlPlaneCommand;
use App\Services\Ai\SelfConstruction\ControlPlane\AtlasSelfConstructionOrganReadinessComposer;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Tests\TestCase;

final class AtlasSelfConstructionControlPlaneCommandTest extends TestCase
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
        $path = sys_get_temp_dir().'/atlas-cp-facts-'.bin2hex(random_bytes(4)).'.json';
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

    private function readyAllOrgans(): array
    {
        $out = [];
        foreach (AtlasSelfConstructionOrganReadinessComposer::CANONICAL_ORGANS as $organ) {
            $out[$organ] = ['status' => 'ready'];
        }

        return $out;
    }

    public function test_inspect_reports_required_services_and_non_execution_guarantees(): void
    {
        [$exit, $out] = $this->runCmd(['action' => 'inspect', '--json' => true]);
        $this->assertSame(AtlasSelfConstructionControlPlaneCommand::EXIT_OK, $exit);

        $decoded = json_decode(trim($out), true);
        $this->assertContains(AtlasSelfConstructionOrganReadinessComposer::class, $decoded['required_services']);
        foreach (['never_enqueues', 'never_calls_providers', 'never_merges_to_main'] as $guarantee) {
            $this->assertContains($guarantee, $decoded['non_execution_guarantees']);
        }
    }

    public function test_mode_off_emits_blocked_to_execute(): void
    {
        $path = $this->fixture(['autonomy_mode' => ['mode' => 'off']]);
        [$exit, $out] = $this->runCmd(['action' => 'mode', '--facts' => $path, '--json' => true]);
        $this->assertSame(AtlasSelfConstructionControlPlaneCommand::EXIT_OK, $exit);

        $decoded = json_decode(trim($out), true);
        $this->assertSame('off', $decoded['autonomy_mode']);
        $this->assertFalse($decoded['allowed_to_execute']);
    }

    public function test_scope_allowed_round_trip(): void
    {
        $path = $this->fixture(['scope_gate' => ['allowed' => true]]);
        [$exit, $out] = $this->runCmd(['action' => 'scope', '--facts' => $path, '--json' => true]);
        $this->assertSame(AtlasSelfConstructionControlPlaneCommand::EXIT_OK, $exit);

        $decoded = json_decode(trim($out), true);
        $this->assertTrue($decoded['scope_gate_allowed']);
    }

    public function test_next_holds_position_when_autonomy_off(): void
    {
        $path = $this->fixture([
            'autonomy_mode' => ['mode' => 'off'],
            'scope_gate' => ['allowed' => true],
            'organ_facts' => $this->readyAllOrgans(),
            'work_queue' => ['backlog_acceptance_items' => 9],
        ]);
        [$exit, $out] = $this->runCmd(['action' => 'next', '--facts' => $path, '--json' => true]);
        $this->assertSame(AtlasSelfConstructionControlPlaneCommand::EXIT_OK, $exit);

        $decoded = json_decode(trim($out), true);
        $this->assertSame('hold_position', $decoded['action']);
        $this->assertContains('autonomy_mode_off', $decoded['reasons']);
    }

    public function test_next_selects_create_task_packets_under_full_readiness_and_backlog(): void
    {
        $path = $this->fixture([
            'autonomy_mode' => ['mode' => 'execute'],
            'scope_gate' => ['allowed' => true],
            'organ_facts' => $this->readyAllOrgans(),
            'work_queue' => ['backlog_acceptance_items' => 3],
        ]);
        [$exit, $out] = $this->runCmd(['action' => 'next', '--facts' => $path, '--json' => true]);
        $this->assertSame(AtlasSelfConstructionControlPlaneCommand::EXIT_OK, $exit);

        $decoded = json_decode(trim($out), true);
        $this->assertSame('create_task_packets', $decoded['action']);
    }

    public function test_unknown_action_fails_closed(): void
    {
        [$exit] = $this->runCmd(['action' => 'BOGUS']);
        $this->assertSame(AtlasSelfConstructionControlPlaneCommand::EXIT_USAGE, $exit);
    }

    public function test_missing_facts_path_on_facts_actions_fails_closed(): void
    {
        [$exitMode] = $this->runCmd(['action' => 'mode']);
        [$exitScope] = $this->runCmd(['action' => 'scope']);
        [$exitNext] = $this->runCmd(['action' => 'next']);
        [$exitScopeGate] = $this->runCmd(['action' => 'scope-gate']);

        $this->assertSame(AtlasSelfConstructionControlPlaneCommand::EXIT_USAGE, $exitMode);
        $this->assertSame(AtlasSelfConstructionControlPlaneCommand::EXIT_USAGE, $exitScope);
        $this->assertSame(AtlasSelfConstructionControlPlaneCommand::EXIT_USAGE, $exitNext);
        $this->assertSame(AtlasSelfConstructionControlPlaneCommand::EXIT_USAGE, $exitScopeGate);
    }

    public function test_scope_gate_allows_when_requested_scope_within_lane_roots_and_budgets_present(): void
    {
        $path = $this->fixture([
            'requested_scope' => ['/Users/vitorepf/develop/Atlas/atlas-server/app/Demo/Foo.php'],
            'risk_class' => 'medium',
            'task_budget' => 5,
            'cost_budget_units' => 100,
            'project_lane' => [
                'project_id' => 'demo',
                'allowed_scope_roots' => ['/Users/vitorepf/develop/Atlas/atlas-server/app'],
            ],
            'forbidden_organs' => ['restricted_organ'],
            'touched_organs' => ['normal_organ'],
            'rollback_ready' => true,
        ]);
        [$exit, $out] = $this->runCmd(['action' => 'scope-gate', '--facts' => $path, '--json' => true]);
        $this->assertSame(AtlasSelfConstructionControlPlaneCommand::EXIT_OK, $exit);

        $decoded = json_decode(trim($out), true);
        $this->assertSame(
            'atlas.controlplane.scope_risk_budget_gate.v1',
            $decoded['scope_risk_budget_gate']['schema']
        );
        $this->assertTrue($decoded['scope_risk_budget_gate']['allowed']);
        $this->assertSame([], $decoded['scope_risk_budget_gate']['blockers']);
    }

    public function test_scope_gate_blocks_when_requested_scope_outside_lane_roots(): void
    {
        $path = $this->fixture([
            'requested_scope' => ['/tmp/completely-outside-the-lane.php'],
            'risk_class' => 'low',
            'task_budget' => 1,
            'cost_budget_units' => 10,
            'project_lane' => [
                'project_id' => 'demo',
                'allowed_scope_roots' => ['/Users/vitorepf/develop/Atlas/atlas-server/app'],
            ],
            'forbidden_organs' => [],
            'touched_organs' => [],
        ]);
        [$exit, $out] = $this->runCmd(['action' => 'scope-gate', '--facts' => $path, '--json' => true]);
        $this->assertSame(AtlasSelfConstructionControlPlaneCommand::EXIT_OK, $exit);

        $decoded = json_decode(trim($out), true);
        $this->assertFalse($decoded['scope_risk_budget_gate']['allowed']);
        $this->assertNotEmpty($decoded['scope_risk_budget_gate']['blockers']);
        $blockers = (array) $decoded['scope_risk_budget_gate']['blockers'];
        $hit = false;
        foreach ($blockers as $b) {
            if (is_string($b) && str_starts_with($b, 'scope_outside_lane:')) {
                $hit = true;
                break;
            }
        }
        $this->assertTrue($hit, 'expected a scope_outside_lane:* blocker; got: '.json_encode($blockers));
    }

    public function test_scope_gate_blocks_when_touched_organ_is_forbidden(): void
    {
        $path = $this->fixture([
            'requested_scope' => ['/Users/vitorepf/develop/Atlas/atlas-server/app/Demo/Foo.php'],
            'risk_class' => 'low',
            'task_budget' => 1,
            'cost_budget_units' => 10,
            'project_lane' => [
                'project_id' => 'demo',
                'allowed_scope_roots' => ['/Users/vitorepf/develop/Atlas/atlas-server/app'],
            ],
            'forbidden_organs' => ['restricted_organ'],
            'touched_organs' => ['restricted_organ'],
        ]);
        [$exit, $out] = $this->runCmd(['action' => 'scope-gate', '--facts' => $path, '--json' => true]);
        $this->assertSame(AtlasSelfConstructionControlPlaneCommand::EXIT_OK, $exit);

        $decoded = json_decode(trim($out), true);
        $this->assertFalse($decoded['scope_risk_budget_gate']['allowed']);
        $blockers = (array) $decoded['scope_risk_budget_gate']['blockers'];
        $hit = false;
        foreach ($blockers as $b) {
            if (is_string($b) && str_starts_with($b, 'forbidden_organ_touched:')) {
                $hit = true;
                break;
            }
        }
        $this->assertTrue($hit, 'expected a forbidden_organ_touched:* blocker; got: '.json_encode($blockers));
    }
}
