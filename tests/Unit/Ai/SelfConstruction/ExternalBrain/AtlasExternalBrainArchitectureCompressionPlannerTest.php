<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainArchitectureCompressionPlanner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainArchitectureCompressionPlannerTest extends TestCase
{
    private function organ(string $id, array $overrides = []): array
    {
        return array_merge([
            'id'                    => $id,
            'capability_labels'     => [$id.'_cap'],
            'files'                 => ['app/Services/'.ucfirst($id).'.php'],
            'line_count'            => 50,
            'is_scaffold'           => false,
            'stale_scaffold_marker' => false,
            'test_coverage'         => true,
            'replacement_owner'     => '',
        ], $overrides);
    }

    public function test_plan_returns_required_envelope_keys(): void
    {
        $result = (new AtlasExternalBrainArchitectureCompressionPlanner)->plan(['organs' => [$this->organ('alpha')]]);

        $this->assertSame(AtlasExternalBrainArchitectureCompressionPlanner::SCHEMA, $result['schema']);
        $this->assertArrayHasKey('candidates', $result);
        $this->assertArrayHasKey('plan_hash', $result);
        $this->assertStringStartsWith('compression_', $result['plan_hash']);
    }

    public function test_duplicate_capability_labels_produce_merge_candidate_with_all_required_fields(): void
    {
        $result = (new AtlasExternalBrainArchitectureCompressionPlanner)->plan([
            'organs' => [
                $this->organ('organ_a', ['capability_labels' => ['shared_cap', 'unique_a']]),
                $this->organ('organ_b', ['capability_labels' => ['shared_cap', 'unique_b']]),
            ],
        ]);

        $merges = array_values(array_filter($result['candidates'], fn (array $c): bool => $c['action'] === 'merge'));
        $this->assertNotEmpty($merges, 'Duplicate capability label must produce a merge candidate');
        $m = $merges[0];
        foreach (['candidate_id', 'action', 'impacted_files', 'expected_line_delta', 'risk_level', 'evidence_floor'] as $key) {
            $this->assertArrayHasKey($key, $m, "merge candidate missing key: {$key}");
        }
        $this->assertStringStartsWith('merge:', $m['candidate_id']);
        $this->assertStringContainsString('shared_cap', $m['evidence_floor']);
    }

    public function test_stale_scaffold_with_owner_and_coverage_produces_delete(): void
    {
        $organ = $this->organ('stale_org', [
            'stale_scaffold_marker' => true,
            'test_coverage'         => true,
            'replacement_owner'     => 'atlas_native_orchestrator',
            'line_count'            => 120,
        ]);

        $result  = (new AtlasExternalBrainArchitectureCompressionPlanner)->plan(['organs' => [$organ]]);
        $deletes = array_values(array_filter($result['candidates'], fn (array $c): bool => $c['action'] === 'delete'));

        $this->assertNotEmpty($deletes, 'Stale scaffold with owner+coverage must produce delete candidate');
        $d = $deletes[0];
        $this->assertSame('delete:stale_org', $d['candidate_id']);
        $this->assertSame(-120, $d['expected_line_delta']);
        $this->assertStringContainsString('stale_scaffold_marker:true', $d['evidence_floor']);
        $this->assertStringContainsString('test_coverage:true', $d['evidence_floor']);
    }

    public function test_stale_scaffold_without_replacement_owner_produces_keep_not_delete(): void
    {
        $organ = $this->organ('stale_no_owner', [
            'stale_scaffold_marker' => true,
            'test_coverage'         => true,
            'replacement_owner'     => '',
        ]);

        $result  = (new AtlasExternalBrainArchitectureCompressionPlanner)->plan(['organs' => [$organ]]);
        $deletes = array_filter($result['candidates'], fn (array $c): bool => $c['action'] === 'delete');
        $keeps   = array_values(array_filter($result['candidates'], fn (array $c): bool => $c['action'] === 'keep'));

        $this->assertEmpty($deletes, 'Must not produce delete when no replacement owner');
        $this->assertNotEmpty($keeps);
        $this->assertSame('no_replacement_owner', $keeps[0]['reason']);
    }

    public function test_stale_scaffold_without_test_coverage_produces_keep_not_delete(): void
    {
        $organ = $this->organ('stale_no_cov', [
            'stale_scaffold_marker' => true,
            'test_coverage'         => false,
            'replacement_owner'     => 'some_owner',
        ]);

        $result  = (new AtlasExternalBrainArchitectureCompressionPlanner)->plan(['organs' => [$organ]]);
        $deletes = array_filter($result['candidates'], fn (array $c): bool => $c['action'] === 'delete');
        $keeps   = array_values(array_filter($result['candidates'], fn (array $c): bool => $c['action'] === 'keep'));

        $this->assertEmpty($deletes, 'Must not produce delete when test coverage is missing');
        $this->assertNotEmpty($keeps);
        $this->assertSame('missing_test_coverage', $keeps[0]['reason']);
    }

    public function test_high_line_count_with_coverage_produces_simplify(): void
    {
        $organ = $this->organ('big_org', ['line_count' => 300, 'test_coverage' => true]);

        $result = (new AtlasExternalBrainArchitectureCompressionPlanner)->plan([
            'organs'           => [$organ],
            'growth_threshold' => 200,
        ]);

        $simplifications = array_values(array_filter($result['candidates'], fn (array $c): bool => $c['action'] === 'simplify'));
        $this->assertNotEmpty($simplifications);
        $s = $simplifications[0];
        $this->assertSame('simplify:big_org', $s['candidate_id']);
        $this->assertLessThan(0, $s['expected_line_delta'], 'Simplify must reduce expected line count');
        $this->assertStringContainsString('test_coverage:true', $s['evidence_floor']);
    }

    public function test_high_line_count_without_coverage_produces_keep_with_reason(): void
    {
        $organ = $this->organ('big_uncovered', ['line_count' => 300, 'test_coverage' => false]);

        $result = (new AtlasExternalBrainArchitectureCompressionPlanner)->plan([
            'organs'           => [$organ],
            'growth_threshold' => 200,
        ]);

        $simplifications = array_filter($result['candidates'], fn (array $c): bool => $c['action'] === 'simplify');
        $keeps           = array_values(array_filter($result['candidates'], fn (array $c): bool => $c['action'] === 'keep'));

        $this->assertEmpty($simplifications, 'Must not simplify without test coverage');
        $this->assertNotEmpty($keeps);
        $this->assertSame('missing_test_coverage_for_simplification', $keeps[0]['reason']);
    }

    public function test_merge_candidates_rank_before_simplify_in_output(): void
    {
        $result = (new AtlasExternalBrainArchitectureCompressionPlanner)->plan([
            'organs' => [
                $this->organ('sa', ['capability_labels' => ['shared'], 'line_count' => 300, 'test_coverage' => true]),
                $this->organ('sb', ['capability_labels' => ['shared'], 'line_count' => 300, 'test_coverage' => true]),
            ],
            'growth_threshold' => 100,
        ]);

        $actions       = array_column($result['candidates'], 'action');
        $firstMerge    = (int) array_search('merge',    $actions, true);
        $firstSimplify = (int) array_search('simplify', $actions, true);
        $this->assertNotFalse(array_search('merge',    $actions, true));
        $this->assertNotFalse(array_search('simplify', $actions, true));
        $this->assertLessThan($firstSimplify, $firstMerge, 'merge must rank before simplify');
    }

    public function test_plan_hash_is_stable_for_identical_inventory(): void
    {
        $planner   = new AtlasExternalBrainArchitectureCompressionPlanner;
        $inventory = ['organs' => [
            $this->organ('x', ['capability_labels' => ['cap_z']]),
            $this->organ('y', ['capability_labels' => ['cap_z']]),
        ]];

        $this->assertSame($planner->plan($inventory)['plan_hash'], $planner->plan($inventory)['plan_hash']);
    }
}
