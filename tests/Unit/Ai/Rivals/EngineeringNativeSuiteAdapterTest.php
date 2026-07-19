<?php

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\Rivals\Adapters\External\EngineeringNativeSuiteAdapter;
use ReflectionMethod;
use Tests\TestCase;

class EngineeringNativeSuiteAdapterTest extends TestCase
{
    public function test_archbench_declares_continuous_rouge_l_instead_of_binary_success(): void
    {
        $adapter = new EngineeringNativeSuiteAdapter('archbench');
        $mapResults = new ReflectionMethod($adapter, 'mapResults');
        $rows = $mapResults->invoke($adapter, [
            'schema_version' => 'atlas.rivals2.engineering_native_unit.v1',
            'suite_id' => 'archbench',
            'model' => 'kimi-k2.7',
            'results' => [[
                'case_id' => 'archbench_adr_000',
                'repetition' => 1,
                'task_type' => 'architecture_design',
                'status' => 'success',
                'failure_class' => null,
                'failure_reason' => null,
                'score' => 0.42,
                'native_metrics' => ['rougeL' => 0.42],
                'wall_ms' => 1000,
                'tokens_in' => 100,
                'tokens_out' => 20,
                'cost_usd' => 0.0,
                'native_artifact' => [
                    'path' => '/tmp/native_artifact.json',
                    'sha256' => str_repeat('a', 64),
                ],
            ]],
        ]);

        $this->assertSame(
            'continuous',
            data_get($rows, '0.metadata.native.measurement_type'),
        );
        $this->assertSame(
            'rougeL',
            data_get($rows, '0.metadata.native.score_metric'),
        );
        $this->assertSame(0.42, data_get($rows, '0.metadata.native.score'));
    }
}
