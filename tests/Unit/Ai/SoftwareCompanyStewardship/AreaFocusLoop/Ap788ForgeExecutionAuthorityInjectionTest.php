<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Console\Commands\AtlasSoftwareCompanyAutonomousEvolutionSessionCommand;
use App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusBranchSandboxMaterializer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusBranchSandboxMaterializerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDeepFindingEngineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\Ap786OwnerFlowExecutor;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\Ap786OwnerFlowRunner;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\ForgeOwnerRuntimeDispatchBridge;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchMergeGovernor;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipPriorityRanker;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipRuntimeResultProjector;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * AP-788 · the CLI must forward real Forge execution authority into AP-786/AP-787
 * without fabricating Obra, without the direct provider router, and while
 * blocking honestly (clear JSON errors, honest forge blocks) when authority is
 * missing or malformed.
 */
final class Ap788ForgeExecutionAuthorityInjectionTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap788_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function forgeFinding(string $id, array $overrides = []): array
    {
        return array_merge([
            'finding_id' => $id,
            'finding_hash' => 'sha256:'.$id,
            'title' => 'Forge owner work '.$id,
            'detail' => 'Forge owner work detail',
            'why_it_matters' => 'Throughput matters.',
            'kind' => 'bug',
            'severity' => 'high',
            'owner_candidate' => 'forge',
            'affected_files' => ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php'],
            'affected_docs' => [],
            'evidence_refs' => ['expected_test:AutonomousEvolutionSessionServiceTest.php'],
            'origin_type' => 'runtime_gap',
            'auto_execution_allowed' => true,
            'operator_review_required' => false,
        ], $overrides);
    }

    /**
     * @return array<string,mixed>
     */
    private function materializedSandbox(): array
    {
        return [
            'status' => AreaFocusBranchSandboxMaterializerService::STATUS_MATERIALIZED,
            'sandbox_id' => 'afsb_test',
            'materialization' => [
                'worktree_path' => $this->tmp.'/worktree',
                'branch_name' => 'atlas/area-focus/agentic_engineering_os/forge/test',
            ],
        ];
    }

    public function test_cli_forwards_forge_authority_into_owner_flow_execute(): void
    {
        $captured = null;

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock): void {
            $mock->shouldReceive('scan')->once()->andReturn(['findings' => [$this->forgeFinding('afdf_forge')], 'status' => 'ready']);
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->once()->andReturn(['top_candidate' => ['candidate_id' => 'afdf_forge']]);
        });
        $this->mock(AreaFocusBranchSandboxMaterializer::class, function ($mock): void {
            $mock->shouldReceive('materialize')->once()->andReturn($this->materializedSandbox());
        });
        // Never the direct provider driver router.
        $this->mock(AtlasForgeProviderInvocationDriverRouter::class)->shouldNotReceive('driverInvoke');
        $this->mock(Ap786OwnerFlowRunner::class, function ($mock) use (&$captured): void {
            $mock->shouldReceive('execute')->once()->andReturnUsing(function (array $input) use (&$captured): array {
                $captured = $input;

                return [
                    'status' => Ap786OwnerFlowExecutor::STATUS_FORGE_PLANNED,
                    'owner' => 'forge',
                    'uses_full_owner_runtime_chain' => true,
                    'provider_router_used' => false,
                    'merge_allowed' => false,
                    'forge_planned' => true,
                    'dispatch_kind' => 'forge_runtime_dispatch',
                    'blockers' => ['forge_runtime_dispatch_planned_only'],
                    'execution_result' => ['result_status' => 'partial', 'changed_files' => []],
                ];
            });
        });
        $this->mock(StewardshipRuntimeResultProjector::class, function ($mock): void {
            $mock->shouldReceive('project')->once()->andReturn(['result_bridge_id' => 'srrb_forge', 'inbox_item_id' => null]);
        });
        // A planned Forge dispatch must never reach AP-769/AP-774 merge governance.
        $this->mock(StewardshipBranchMergeGovernor::class)->shouldNotReceive('evaluate');

        $exit = Artisan::call('atlas:software-company-stewardship:autonomous-evolution-session', [
            '--area' => 'agentic_engineering_os',
            '--cycles' => 1,
            '--execute' => true,
            '--repo-root' => $this->tmp,
            '--forge-obra' => '11111111-1111-1111-1111-111111111111',
            '--forge-live-topology-json' => '{"status":"live","providers":["cursor_cli"]}',
            '--forge-live-decision-json' => '{"decision":"approve_forge_run","operator_actor":"vitor"}',
            '--forge-dispatch-mode' => 'forge_runtime_dispatch',
            '--forge-role' => 'primary_builder',
            '--json' => true,
        ]);

        $this->assertNotNull($captured, 'owner flow execute must have been called');
        $this->assertSame('11111111-1111-1111-1111-111111111111', $captured['forge_obra'] ?? null);
        $this->assertSame('live', $captured['forge_live_topology']['status'] ?? null);
        $this->assertSame('approve_forge_run', $captured['forge_live_decision']['decision'] ?? null);
        $this->assertSame('vitor', $captured['forge_live_decision']['operator_actor'] ?? null);
        $this->assertSame('forge_runtime_dispatch', $captured['forge_dispatch_mode'] ?? null);
        $this->assertSame('primary_builder', $captured['forge_role'] ?? null);

        $payload = json_decode(Artisan::output(), true);
        $this->assertTrue($payload['forge_authority']['obra_supplied']);
        $this->assertTrue($payload['forge_authority']['live_topology_live']);
        $this->assertTrue($payload['forge_authority']['live_decision_supplied']);
        $this->assertTrue($payload['forge_authority']['never_uses_direct_provider_router']);
        // A planned-only Forge dispatch is real-but-not-merged: waiting review, never a fake completion.
        $this->assertSame('cycle_completed_waiting_review_or_merge', $payload['cycles'][0]['final_status']);
        $this->assertSame(0, $exit, 'a planned (not blocked) session exits success');
    }

    public function test_invalid_forge_live_topology_json_blocks_with_clear_error(): void
    {
        $exit = Artisan::call('atlas:software-company-stewardship:autonomous-evolution-session', [
            '--area' => 'agentic_engineering_os',
            '--forge-live-topology-json' => '{not valid json',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertSame(self::failureExit(), $exit);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('forge_authority_json_invalid', $payload['reason']);
        $this->assertStringContainsString('--forge-live-topology-json', $payload['detail']);
    }

    public function test_invalid_forge_live_decision_json_blocks_with_clear_error(): void
    {
        $exit = Artisan::call('atlas:software-company-stewardship:autonomous-evolution-session', [
            '--forge-live-decision-json' => '["not","an","object"]',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertSame(self::failureExit(), $exit);
        $this->assertSame('forge_authority_json_invalid', $payload['reason']);
        $this->assertStringContainsString('--forge-live-decision-json', $payload['detail']);
        $this->assertStringContainsString('must be a JSON object', $payload['detail']);
    }

    public function test_forge_bridge_blocks_without_authority_honestly(): void
    {
        $plan = (new ForgeOwnerRuntimeDispatchBridge())->plan(['finding' => []]);

        $this->assertFalse($plan['ok']);
        $this->assertSame('forge_obra_required', $plan['blocker']);
        $this->assertFalse($plan['provider_router_used']);
    }

    public function test_cli_parsed_authority_reaches_ap759_runtime_dispatch_command(): void
    {
        // Exactly the authority the CLI builds from its flags.
        $forge = AtlasSoftwareCompanyAutonomousEvolutionSessionCommand::parseForgeAuthority([
            'obra' => '22222222-2222-2222-2222-222222222222',
            'topology_json' => '{"status":"live"}',
            'decision_json' => '{"decision":"approve_forge_run","operator_actor":"vitor"}',
            'dispatch_mode' => 'forge_runtime_dispatch',
            'role' => 'primary_builder',
            'provider_authorization' => false,
            'budget_approved' => false,
        ]);

        $plan = (new ForgeOwnerRuntimeDispatchBridge())->plan(array_merge(['finding' => []], $forge));

        $this->assertTrue($plan['ok']);
        $this->assertSame('forge_runtime_dispatch', $plan['dispatch_kind']);
        $this->assertTrue($plan['plan_only']);
        $this->assertFalse($plan['provider_router_used']);
        $this->assertContains('atlas:forge:runtime-dispatch', $plan['command']);
        $this->assertContains('--obra=22222222-2222-2222-2222-222222222222', $plan['command']);
    }

    public function test_session_continues_to_next_finding_when_forge_blocks_with_continue_on_blocked(): void
    {
        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock): void {
            $mock->shouldReceive('scan')->twice()->andReturn([
                'findings' => [$this->forgeFinding('afdf_forge_1'), $this->forgeFinding('afdf_forge_2')],
                'status' => 'ready',
            ]);
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->twice()->andReturn(
                ['top_candidate' => ['candidate_id' => 'afdf_forge_1']],
                ['top_candidate' => ['candidate_id' => 'afdf_forge_2']],
            );
        });
        $this->mock(AreaFocusBranchSandboxMaterializer::class, function ($mock): void {
            $mock->shouldReceive('materialize')->twice()->andReturn($this->materializedSandbox());
        });
        $this->mock(AtlasForgeProviderInvocationDriverRouter::class)->shouldNotReceive('driverInvoke');
        // Both cycles block honestly for missing Forge authority; the loop must
        // still advance to the second finding rather than abort the session.
        $this->mock(Ap786OwnerFlowRunner::class, function ($mock): void {
            $mock->shouldReceive('execute')->twice()->andReturn([
                'status' => Ap786OwnerFlowExecutor::STATUS_BLOCKED,
                'reason' => 'forge_obra_required',
                'blockers' => ['forge_obra_required'],
                'uses_full_owner_runtime_chain' => true,
                'provider_router_used' => false,
                'merge_allowed' => false,
            ]);
        });
        $this->mock(StewardshipBranchMergeGovernor::class)->shouldNotReceive('evaluate');

        $payload = app(AutonomousEvolutionSessionService::class)->run([
            'execute' => true,
            'repo_root' => $this->tmp,
            'cycles' => 2,
            'continue_on_blocked' => true,
        ]);

        $this->assertSame(2, $payload['cycles_attempted']);
        $this->assertContains('forge_obra_required', $payload['blockers']);
        foreach ($payload['cycles'] as $cycle) {
            $this->assertSame('blocked', $cycle['final_status']);
            $this->assertFalse((bool) ($cycle['provider_called'] ?? false));
        }
    }

    private static function failureExit(): int
    {
        return AtlasSoftwareCompanyAutonomousEvolutionSessionCommand::FAILURE;
    }
}
