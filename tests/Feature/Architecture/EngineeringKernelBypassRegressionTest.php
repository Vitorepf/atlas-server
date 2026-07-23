<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Services\Ai\EngineeringKernel\Adapters\TaskLaneMergeActuatorAdapter;
use App\Services\Ai\EngineeringKernel\Coverage\EngineeringExecutionCoverage;
use App\Services\Ai\EngineeringKernel\Coverage\EngineeringExecutionSurfaceRegistry;
use App\Services\Ai\EngineeringKernel\MergeActuator;
use App\Services\Ai\EngineeringKernel\Quality\QualityFoundryMutativeSurfaceStaticScanner;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

final class EngineeringKernelBypassRegressionTest extends TestCase
{
    public function test_engineering_execution_coverage_contract_classes_exist(): void
    {
        $coverage = new ReflectionClass(EngineeringExecutionCoverage::class);
        $registry = new ReflectionClass(EngineeringExecutionSurfaceRegistry::class);

        $this->assertTrue($coverage->hasMethod('record'));
        $this->assertTrue($coverage->hasMethod('report'));
        $this->assertSame('atlas.engineering_execution_coverage.v2', EngineeringExecutionCoverage::SCHEMA_VERSION);
        $this->assertSame('engineering_execution_coverage.v2', EngineeringExecutionCoverage::EVENT_TYPE);
        $this->assertTrue($registry->hasMethod('all'));
        $this->assertTrue($registry->hasMethod('ids'));
    }

    public function test_known_mutative_engineering_entrypoints_are_registered(): void
    {
        $registered = EngineeringExecutionSurfaceRegistry::ids();
        $nonBypassAllowlist = [];

        foreach ([
            'atlas_dev.pipeline_run_executor',
            'atlas_forge.work_packet_execution_cycle',
            'atlas_autonomos.task_serving',
            'atlas_autonomos.native_worker',
            'atlas_autonomos.commit_governance',
            'engineering_kernel.merge_actuator',
        ] as $entrypoint) {
            $this->assertTrue(
                in_array($entrypoint, $registered, true) || in_array($entrypoint, $nonBypassAllowlist, true),
                "{$entrypoint} must be registered for engineering execution coverage or explicitly classified non-bypass.",
            );
        }

        $this->assertSame($registered, array_values(array_unique($registered)));
        foreach (EngineeringExecutionSurfaceRegistry::all() as $surface) {
            $this->assertTrue($surface['mutative'], "{$surface['id']} must remain a confirmed mutative surface.");
            $this->assertNotSame('', $surface['path']);
        }
    }

    public function test_merge_actuator_requires_authorized_actuation_with_v1_revert_translator(): void
    {
        $this->assertSame(['act', 'prepareRevert', 'revert'], $this->publicMethodNames(MergeActuator::class));
        $this->assertSame(['act', 'prepareRevert', 'revert'], $this->publicMethodNames(TaskLaneMergeActuatorAdapter::class));
    }

    public function test_quality_foundry_mutative_static_guard_scans_canonical_governed_sources(): void
    {
        $paths = [
            'app/Services/Ai/EngineeringKernel/MergeActuator.php',
            'app/Services/Ai/EngineeringKernel/KernelEvidenceAuthority.php',
            'app/Services/Ai/Programming/AtlasDev/Execution/EliteExecutorKernelDevAdapter.php',
            'app/Services/Ai/Programming/Forge/Execution/ForgeObraRuntime.php',
            'app/Services/Ai/SelfConstruction/AtlasTaskScopedCommitter.php',
        ];
        $files = [];
        foreach ($paths as $path) {
            $files[$path] = (string) file_get_contents(base_path($path));
        }

        $report = (new QualityFoundryMutativeSurfaceStaticScanner)->scan($files);

        $this->assertSame(QualityFoundryMutativeSurfaceStaticScanner::SCHEMA, $report['schema']);
        $this->assertSame('pass', $report['status'], json_encode($report['violations']));
        $this->assertSame([], $report['violations']);
    }

    public function test_forge_provider_execution_cannot_fallback_to_planned_without_kernel(): void
    {
        $source = (string) file_get_contents(base_path('app/Services/Ai/Programming/Forge/Execution/ForgeObraRuntime.php'));

        $this->assertStringContainsString(
            "elite_executor_kernel_unavailable",
            $source,
            'Forge provider execution must fail closed when the shared Kernel is unavailable.',
        );
        $this->assertStringContainsString(
            'if ($budget->allowProvider) {',
            $source,
            'Forge mutative provider execution must enter the shared Kernel branch only after the null guard.',
        );
    }

    public function test_forge_obra_runtime_delegates_real_packet_execution_to_canonical_cycle_port(): void
    {
        $source = (string) file_get_contents(base_path('app/Services/Ai/Programming/Forge/Execution/ForgeObraRuntime.php'));

        $this->assertStringContainsString(
            'executeRealCycle(',
            $source,
            'Forge Obra must execute packets through the canonical cycle service and its shared port.',
        );
        $this->assertStringNotContainsString(
            '$this->kernel->execute($order)',
            $source,
            'Forge Obra must not own a second direct Kernel execution path.',
        );
    }

    public function test_autonomos_task_serving_cannot_certify_mutation_without_shared_kernel(): void
    {
        $source = (string) file_get_contents(base_path('app/Services/Ai/SelfConstruction/AtlasTaskServingService.php'));

        $this->assertStringContainsString(
            "elite_kernel_required_for_mutation",
            $source,
            'Autônomos mutation must fail closed when the shared Kernel is unavailable.',
        );
        $this->assertStringContainsString(
            "context_runtime_required_for_mutation",
            $source,
            'Autônomos mutation must fail closed when context certification is unavailable.',
        );
    }

    /**
     * @param  class-string  $class
     * @return list<string>
     */
    private function publicMethodNames(string $class): array
    {
        $names = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );
        $names = array_values(array_filter($names, static fn (string $name): bool => $name !== '__construct'));
        sort($names);

        return $names;
    }
}
