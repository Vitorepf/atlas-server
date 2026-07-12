<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Services\Ai\EngineeringKernel\Adapters\TaskLaneMergeActuatorAdapter;
use App\Services\Ai\EngineeringKernel\Coverage\EngineeringExecutionCoverage;
use App\Services\Ai\EngineeringKernel\Coverage\EngineeringExecutionSurfaceRegistry;
use App\Services\Ai\EngineeringKernel\MergeActuator;
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
