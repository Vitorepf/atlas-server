<?php

namespace Tests\Unit\Ai\Hermes\Mesh;

use App\Models\AiJob;
use App\Services\Ai\Hermes\HermesDelegationAdapter;
use App\Services\Ai\Hermes\Mesh\HermesCheckpointPolicy;
use App\Services\Ai\Hermes\Mesh\HermesExecutiveMeshPlanner;
use App\Services\Ai\Hermes\Mesh\HermesExecutiveMeshService;
use App\Services\Ai\Hermes\Mesh\HermesMeshJobRunner;
use App\Services\Ai\Hermes\Mesh\HermesMeshProcessWorkerFactory;
use App\Services\Ai\Hermes\Mesh\HermesMeshReconciler;
use App\Services\Ai\Hermes\Mesh\HermesProfileResolver;
use App\Services\Ai\Hermes\Mesh\MeshWorkerHandle;
use Tests\TestCase;

/**
 * Proves the AtlasDecide → mesh execution route: gating is triple fail-closed and
 * any non-mesh / not-opted-in / nothing-dispatched case returns null so the worker
 * falls back to a single provider; a real fan-out is mapped to an AiProviderResult.
 * The end-to-end orchestration is exercised against the REAL mesh service with a
 * FAKE fleet (no `hermes` process, no token spend).
 */
class HermesMeshJobRunnerTest extends TestCase
{
    private function meshJob(array $subtasks = [['objective' => 'do A', 'role' => 'coder']]): AiJob
    {
        return new AiJob([
            'kind' => 'mesh',
            'payload' => ['hermes' => ['mesh' => ['subtasks' => $subtasks]]],
        ]);
    }

    private function bothSwitchesOn(): void
    {
        config([
            'atlas.ai.providers.hermes_cli.mesh.policy' => 'atlas_adapter',
            'atlas.ai.providers.hermes_cli.mesh.auto_route' => true,
        ]);
    }

    private function runnerWithMockedService(\Closure $stub): HermesMeshJobRunner
    {
        $service = $this->createMock(HermesExecutiveMeshService::class);
        $stub($service);
        $factory = $this->createMock(HermesMeshProcessWorkerFactory::class);
        $factory->method('workerFor')->willReturn(fn (array $child): MeshWorkerHandle => new FakeRunnerMeshHandle(0));

        return new HermesMeshJobRunner($service, $factory);
    }

    public function test_returns_null_when_job_is_not_mesh_kind(): void
    {
        $this->bothSwitchesOn();
        $service = $this->createMock(HermesExecutiveMeshService::class);
        $service->expects($this->never())->method('run');
        $factory = $this->createMock(HermesMeshProcessWorkerFactory::class);

        $job = new AiJob(['kind' => 'interaction', 'payload' => ['hermes' => ['mesh' => ['subtasks' => [['objective' => 'x']]]]]]);

        $this->assertNull((new HermesMeshJobRunner($service, $factory))->run($job));
    }

    public function test_returns_null_when_policy_off(): void
    {
        config(['atlas.ai.providers.hermes_cli.mesh.policy' => 'off', 'atlas.ai.providers.hermes_cli.mesh.auto_route' => true]);
        $service = $this->createMock(HermesExecutiveMeshService::class);
        $service->expects($this->never())->method('run');

        $this->assertNull((new HermesMeshJobRunner($service, $this->createMock(HermesMeshProcessWorkerFactory::class)))->run($this->meshJob()));
    }

    public function test_returns_null_when_auto_route_off(): void
    {
        config(['atlas.ai.providers.hermes_cli.mesh.policy' => 'atlas_adapter', 'atlas.ai.providers.hermes_cli.mesh.auto_route' => false]);
        $service = $this->createMock(HermesExecutiveMeshService::class);
        $service->expects($this->never())->method('run');

        $this->assertNull((new HermesMeshJobRunner($service, $this->createMock(HermesMeshProcessWorkerFactory::class)))->run($this->meshJob()));
    }

    public function test_returns_null_when_no_subtasks(): void
    {
        $this->bothSwitchesOn();
        $service = $this->createMock(HermesExecutiveMeshService::class);
        $service->expects($this->never())->method('run');

        $job = new AiJob(['kind' => 'mesh', 'payload' => ['hermes' => ['mesh' => ['requested' => true]]]]);

        $this->assertNull((new HermesMeshJobRunner($service, $this->createMock(HermesMeshProcessWorkerFactory::class)))->run($job));
    }

    public function test_returns_null_when_nothing_dispatched(): void
    {
        $this->bothSwitchesOn();
        $runner = $this->runnerWithMockedService(function ($service): void {
            $service->method('run')->willReturn([
                'run' => ['dispatched_count' => 0],
                'reconciliation' => ['aggregate_status' => 'empty'],
            ]);
        });

        $this->assertNull($runner->run($this->meshJob()), 'service launched nothing → single-provider fallback');
    }

    public function test_maps_all_completed_fanout_to_ok_result(): void
    {
        $this->bothSwitchesOn();
        $runner = $this->runnerWithMockedService(function ($service): void {
            $service->method('run')->willReturn([
                'run' => ['schema_version' => 'atlas.hermes.mesh_run.v1', 'dispatched_count' => 3, 'plan_receipt_hash' => 'plan-hash'],
                'reconciliation' => ['aggregate_status' => 'all_completed', 'reconciliation_allowed_now' => true, 'receipt_hash' => 'recon-hash'],
                'checkpoint_actions' => [],
            ]);
        });

        $result = $runner->run($this->meshJob());

        $this->assertNotNull($result);
        $this->assertTrue($result->ok);
        $this->assertStringContainsString('all_completed', $result->output);
        $this->assertStringContainsString('3 child task', $result->output);
        $this->assertSame('mesh', $result->metadata['hermes_transport']);
        $this->assertSame('atlas.hermes.mesh_run.v1', $result->metadata['hermes_mesh_run']['schema_version']);
        $this->assertSame('all_completed', $result->metadata['hermes_mesh_reconciliation']['aggregate_status']);
    }

    public function test_maps_partial_fanout_to_not_ok_result(): void
    {
        $this->bothSwitchesOn();
        $runner = $this->runnerWithMockedService(function ($service): void {
            $service->method('run')->willReturn([
                'run' => ['dispatched_count' => 2],
                'reconciliation' => ['aggregate_status' => 'partial'],
            ]);
        });

        $result = $runner->run($this->meshJob());

        $this->assertNotNull($result);
        $this->assertFalse($result->ok);
        $this->assertSame('mesh_partial', $result->errorCode);
    }

    public function test_integration_real_mesh_service_with_fake_fleet(): void
    {
        // The REAL orchestration brain (plan→bounded dispatch→reconcile) with a FAKE
        // fleet — proves the full runner path end-to-end with zero token spend.
        $this->bothSwitchesOn();

        $service = new HermesExecutiveMeshService(
            new HermesExecutiveMeshPlanner(),
            new HermesProfileResolver(),
            new HermesCheckpointPolicy(),
            new HermesMeshReconciler(),
            new HermesDelegationAdapter(),
        );
        $factory = $this->createMock(HermesMeshProcessWorkerFactory::class);
        $factory->method('workerFor')->willReturn(
            fn (array $child): MeshWorkerHandle => new FakeRunnerMeshHandle((int) ($child['index'] ?? 0)),
        );

        $runner = new HermesMeshJobRunner($service, $factory);

        $result = $runner->run($this->meshJob([
            ['objective' => 'implement', 'role' => 'coder'],
            ['objective' => 'review', 'role' => 'reviewer'],
        ]));

        $this->assertNotNull($result, 'real fan-out produced a result');
        $this->assertTrue($result->ok);
        $this->assertSame('mesh', $result->metadata['hermes_transport']);
        $this->assertSame('all_completed', $result->metadata['hermes_mesh_reconciliation']['aggregate_status']);
        $this->assertSame(2, $result->metadata['hermes_mesh_run']['dispatched_count']);
    }
}

class FakeRunnerMeshHandle implements MeshWorkerHandle
{
    private int $polls = 0;

    public function __construct(private readonly int $index) {}

    public function isFinished(): bool
    {
        return ++$this->polls >= 1;
    }

    /** @return array<string,mixed> */
    public function result(): array
    {
        return [
            'schema_version' => 'atlas.hermes.result_packet.v1',
            'child_index' => $this->index,
            'result_hash' => hash('sha256', 'child-'.$this->index),
            'output' => ['response_hash' => hash('sha256', 'child-'.$this->index), 'response_bytes' => 42],
            'exit_code' => 0,
        ];
    }
}
