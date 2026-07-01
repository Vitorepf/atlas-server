<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Simplification;

use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionSimplificationRedundancyMap;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionSimplificationRedundancyMapTest extends TestCase
{
    private AtlasSelfConstructionSimplificationRedundancyMap $map;

    protected function setUp(): void
    {
        parent::setUp();
        $this->map = new AtlasSelfConstructionSimplificationRedundancyMap();
    }

    // AC 2: classes with shared semantic role, inputs and outputs grouped across namespaces
    public function test_shared_semantic_role_inputs_outputs_grouped_across_namespaces(): void
    {
        $result = $this->map->map([
            [
                'class_name' => 'BrainReporter',
                'namespace' => 'App\\Brain\\Report',
                'semantic_role' => 'rate_reporter',
                'inputs' => ['events', 'window'],
                'outputs' => ['rate_rows'],
                'consumers' => ['MaestroCommand'],
                'estimated_deleted_lines' => 50,
                'risk_level' => 'low',
                'proof_ready' => true,
            ],
            [
                'class_name' => 'MaestroReporter',
                'namespace' => 'App\\Maestro\\Projection',
                'semantic_role' => 'rate_reporter',
                'inputs' => ['events', 'window'],
                'outputs' => ['rate_rows'],
                'consumers' => ['MaestroCommand'],
                'estimated_deleted_lines' => 30,
                'risk_level' => 'low',
                'proof_ready' => true,
            ],
        ]);

        $strongFamilies = array_filter($result['families'], fn ($f) => $f['match_type'] === 'strong_match');
        $this->assertNotEmpty($strongFamilies, 'must group organs with shared role+inputs+outputs across namespaces');

        $family = array_values($strongFamilies)[0];
        $this->assertContains('BrainReporter', $family['members']);
        $this->assertContains('MaestroReporter', $family['members']);
    }

    // AC 3: prefix-only matches without consumer overlap → weak_match
    public function test_prefix_only_without_consumer_overlap_is_weak(): void
    {
        $result = $this->map->map([
            [
                'class_name' => 'ClassA',
                'namespace' => 'App\\Brain\\Foo',
                'semantic_role' => 'role_a',
                'inputs' => ['x'],
                'outputs' => ['y'],
                'consumers' => ['ConsumerA'],
                'estimated_deleted_lines' => 10,
                'risk_level' => 'low',
                'proof_ready' => true,
            ],
            [
                'class_name' => 'ClassB',
                'namespace' => 'App\\Brain\\Bar',
                'semantic_role' => 'role_b',
                'inputs' => ['z'],
                'outputs' => ['w'],
                'consumers' => ['ConsumerB'],
                'estimated_deleted_lines' => 20,
                'risk_level' => 'low',
                'proof_ready' => true,
            ],
        ]);

        $weakFamilies = array_filter($result['families'], fn ($f) => $f['match_type'] === 'weak_match');
        $this->assertNotEmpty($weakFamilies, 'prefix-only without consumer overlap must be weak_match');
    }

    // AC 4: ranked by estimated deleted lines, risk, proof readiness
    public function test_ranked_by_deleted_lines_desc(): void
    {
        $result = $this->map->map([
            [
                'class_name' => 'Small1',
                'namespace' => 'App\\A\\B',
                'semantic_role' => 'r1',
                'inputs' => ['a'],
                'outputs' => ['b'],
                'consumers' => ['C1'],
                'estimated_deleted_lines' => 5,
                'risk_level' => 'low',
                'proof_ready' => true,
            ],
            [
                'class_name' => 'Small2',
                'namespace' => 'App\\A\\C',
                'semantic_role' => 'r1',
                'inputs' => ['a'],
                'outputs' => ['b'],
                'consumers' => ['C1'],
                'estimated_deleted_lines' => 5,
                'risk_level' => 'low',
                'proof_ready' => true,
            ],
            [
                'class_name' => 'Big1',
                'namespace' => 'App\\X\\Y',
                'semantic_role' => 'r2',
                'inputs' => ['c'],
                'outputs' => ['d'],
                'consumers' => ['C2'],
                'estimated_deleted_lines' => 200,
                'risk_level' => 'low',
                'proof_ready' => true,
            ],
            [
                'class_name' => 'Big2',
                'namespace' => 'App\\X\\Z',
                'semantic_role' => 'r2',
                'inputs' => ['c'],
                'outputs' => ['d'],
                'consumers' => ['C2'],
                'estimated_deleted_lines' => 200,
                'risk_level' => 'low',
                'proof_ready' => true,
            ],
        ]);

        $this->assertNotEmpty($result['families']);
        // First family should have higher deleted lines
        $this->assertGreaterThanOrEqual(
            $result['families'][1]['estimated_deleted_lines'] ?? 0,
            $result['families'][0]['estimated_deleted_lines']
        );
    }

    public function test_single_organ_produces_no_families(): void
    {
        $result = $this->map->map([
            [
                'class_name' => 'Lonely',
                'namespace' => 'App\\X',
                'semantic_role' => 'unique',
                'inputs' => ['a'],
                'outputs' => ['b'],
                'consumers' => ['C'],
                'estimated_deleted_lines' => 10,
                'risk_level' => 'low',
                'proof_ready' => true,
            ],
        ]);

        $this->assertEmpty($result['families']);
    }

    public function test_empty_input_produces_empty_families(): void
    {
        $result = $this->map->map([]);
        $this->assertEmpty($result['families']);
    }
}
