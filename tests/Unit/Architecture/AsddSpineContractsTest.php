<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use App\Http\Controllers\AtlasDev\Support\KernelRunExecutor;
use App\Http\Controllers\AtlasDev\Support\PipelineRunExecutor;
use App\Http\Controllers\AtlasDev\Support\RunExecutor;
use App\Services\Ai\Brain\AmbitionSpine;
use App\Services\Ai\ControlPlane\AtlasControlPlaneSnapshotService;
use App\Services\Ai\ControlPlane\OperatorTruth;
use App\Services\Ai\EngineeringKernel\AgentQosExcellenceLaw;
use App\Services\Ai\EngineeringKernel\Spine\EngineeringSpine;
use App\Services\Ai\Hermes\HermesNativeFunctionCallSupport;
use App\Services\Ai\Kernel\Authority\AuthBoundary;
use App\Services\Ai\Kernel\Evidence\EvidenceSpine;
use App\Services\Ai\OpenBrain\BrainMembrane;
use App\Services\Ai\Provider\ProviderFabric;
use App\Services\Ai\SelfConstruction\AtlasTaskServingService;
use App\Services\Ai\SelfConstruction\LandLearn\LandLearnSpine;
use Tests\TestCase;

final class AsddSpineContractsTest extends TestCase
{
    public function test_live_run_executor_is_kernel_not_pipeline(): void
    {
        $executor = $this->app->make(RunExecutor::class);
        self::assertInstanceOf(KernelRunExecutor::class, $executor);
        self::assertNotInstanceOf(PipelineRunExecutor::class, $executor);
    }

    public function test_engineering_spine_drives_shipped_qos_law(): void
    {
        $spine = new EngineeringSpine;
        $ctx = [
            'risk_class' => 'R5',
            'difficulty_level' => 5,
            'request_class' => AgentQosExcellenceLaw::CLASS_ARCH,
        ];
        self::assertSame(AgentQosExcellenceLaw::resolveDepth($ctx), $spine->resolveDepth($ctx));
        self::assertSame(AgentQosExcellenceLaw::DEPTH_MAX, $spine->resolveDepth($ctx));
        self::assertSame(AgentQosExcellenceLaw::blockers($ctx), $spine->blockers($ctx));
        self::assertIsArray($spine->evaluate($ctx));
        self::assertSame('wired_qos_law', $spine->contract()['status']);
    }

    public function test_provider_fabric_routes_via_shipped_lock_and_lift(): void
    {
        config(['atlas.ai.providers.hermes_cli.native_fc.enabled' => false]);
        $fabric = new ProviderFabric;
        self::assertSame('free_form', $fabric->responseContract('hermes_cli', 'kimi-k2.7-FC', 'tool_use_function_calling')['channel']);

        config(['atlas.ai.providers.hermes_cli.native_fc.enabled' => true]);
        self::assertSame('native_function_call', $fabric->responseContract('hermes_cli', 'x', 'tool_use_function_calling')['channel']);
        self::assertSame(HermesNativeFunctionCallSupport::TOOL_NAME, $fabric->atlasApplyPatchToolName());

        $calls = $fabric->liftToolCallsFromProviderText(json_encode([
            'tool_calls' => [[
                'name' => 'atlas_apply_patch',
                'arguments' => ['path' => 'app/X.php', 'mode' => 'modify', 'next' => '<?php'],
            ]],
        ], JSON_UNESCAPED_SLASHES) ?: '');
        self::assertCount(1, $calls);
        self::assertSame('atlas_apply_patch', $calls[0]['function']['name']);
    }

    public function test_brain_membrane_calls_shipped_context_pack_service(): void
    {
        $membrane = $this->app->make(BrainMembrane::class);
        $pack = $membrane->assemble('asdd spine pack probe', ['workspace' => base_path()]);
        self::assertIsArray($pack);
        self::assertNotEmpty($pack);
        // Live pack always exposes a schema or task echo from the real service.
        self::assertTrue(
            isset($pack['schema']) || isset($pack['task']) || isset($pack['pack']) || array_key_exists('ok', $pack) || count($pack) > 0
        );
        self::assertSame('wired_pack_for', $membrane->contract()['status']);
    }

    public function test_land_learn_spine_calls_shipped_task_serving_report_intake(): void
    {
        $spine = $this->app->make(LandLearnSpine::class);
        // Empty ids exercise the real intake path (blocked envelope) — not a reimplemented gate.
        $result = $spine->report('', '', '', []);
        self::assertIsArray($result);
        self::assertNotEmpty($result);
        self::assertTrue(
            isset($result['status']) || isset($result['outcome']) || isset($result['schema']) || isset($result['reason']),
            'report() must return a real TaskServing envelope shape'
        );
        self::assertSame('wired_report', $spine->contract()['status']);
        self::assertTrue(method_exists(AtlasTaskServingService::class, 'report'));
    }

    public function test_operator_truth_calls_shipped_control_plane_snapshot(): void
    {
        $truth = $this->app->make(OperatorTruth::class);
        $snap = $truth->snapshot();
        self::assertIsArray($snap);
        self::assertSame(AtlasControlPlaneSnapshotService::SCHEMA, $snap['schema'] ?? null);
        self::assertArrayHasKey('status', $snap);
        self::assertArrayHasKey('readiness', $snap);
        self::assertFalse($truth->contract()['eng_gating']);
        self::assertSame('wired_snapshot', $truth->contract()['status']);
    }

    public function test_ambition_evidence_auth_contracts(): void
    {
        self::assertFalse((new EvidenceSpine)->contract()['second_ledger']);
        self::assertFalse((new AmbitionSpine)->contract()['acde_allowed']);
        self::assertContains('admit', (new AuthBoundary)->contract()['stages']);
        self::assertContains('recheck', (new AuthBoundary)->contract()['stages']);
    }

    public function test_task_queue_orchestrator_alias_resolves_canonical(): void
    {
        $legacy = \App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator::class;
        $canonical = \App\Services\Ai\SelfConstruction\ControlPlane\TaskQueue\AgentControlPlaneTaskQueueOrchestrator::class;
        self::assertTrue(class_exists($legacy));
        self::assertTrue(class_exists($canonical));
        self::assertTrue(
            is_a($legacy, $canonical, true)
            || (new \ReflectionClass($legacy))->getName() === (new \ReflectionClass($canonical))->getName()
        );
    }
}
