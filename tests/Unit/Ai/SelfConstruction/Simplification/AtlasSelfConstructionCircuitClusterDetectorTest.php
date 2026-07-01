<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Simplification;

use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionCircuitClusterDetector;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionCircuitClusterDetectorTest extends TestCase
{
    public function test_cluster_formed_from_full_overlap_is_merge_ready(): void
    {
        $result = (new AtlasSelfConstructionCircuitClusterDetector)->detect([
            [
                'name' => 'OrganA',
                'capability_label' => 'task_admission',
                'inputs' => ['packet'],
                'outputs' => ['verdict'],
                'proof_refs' => ['test_run_1'],
                'consumers' => ['TaskFabricCommand'],
            ],
            [
                'name' => 'OrganB',
                'capability_label' => 'task_admission',
                'inputs' => ['packet'],
                'outputs' => ['verdict'],
                'proof_refs' => ['test_run_1'],
                'consumers' => ['TaskFabricCommand'],
            ],
        ]);

        self::assertCount(1, $result['clusters']);
        $cluster = $result['clusters'][0];
        self::assertSame(['OrganA', 'OrganB'], $cluster['members']);
        self::assertSame(['packet'], $cluster['shared_contracts']['inputs']);
        self::assertSame(['verdict'], $cluster['shared_contracts']['outputs']);
        self::assertTrue($cluster['merge_ready']);
        self::assertSame(1.0, $cluster['overlap_score']);
        self::assertSame([], $cluster['false_positive_risks']);
    }

    public function test_capability_label_match_alone_without_other_overlap_is_not_merge_ready(): void
    {
        $result = (new AtlasSelfConstructionCircuitClusterDetector)->detect([
            [
                'name' => 'OrganA',
                'capability_label' => 'task_admission',
                'inputs' => ['packet'],
                'outputs' => ['verdict'],
                'proof_refs' => [],
                'consumers' => [],
            ],
            [
                'name' => 'OrganB',
                'capability_label' => 'task_admission',
                'inputs' => ['other_input'],
                'outputs' => ['other_output'],
                'proof_refs' => [],
                'consumers' => [],
            ],
        ]);

        $cluster = $result['clusters'][0];
        self::assertFalse($cluster['merge_ready']);
        self::assertContains('capability_label_matches_but_no_shared_contracts', $cluster['false_positive_risks']);
        self::assertContains('no_proof_overlap', $cluster['false_positive_risks']);
        self::assertContains('no_consumer_overlap', $cluster['false_positive_risks']);
    }

    public function test_missing_proof_overlap_refuses_merge_ready_even_with_shared_contracts(): void
    {
        $result = (new AtlasSelfConstructionCircuitClusterDetector)->detect([
            [
                'name' => 'OrganA',
                'capability_label' => 'task_admission',
                'inputs' => ['packet'],
                'outputs' => ['verdict'],
                'proof_refs' => [],
                'consumers' => ['Caller'],
            ],
            [
                'name' => 'OrganB',
                'capability_label' => 'task_admission',
                'inputs' => ['packet'],
                'outputs' => ['verdict'],
                'proof_refs' => ['test_run_2'],
                'consumers' => ['Caller'],
            ],
        ]);

        $cluster = $result['clusters'][0];
        self::assertFalse($cluster['merge_ready']);
        self::assertContains('no_proof_overlap', $cluster['false_positive_risks']);
    }

    public function test_missing_consumer_overlap_refuses_merge_ready(): void
    {
        $result = (new AtlasSelfConstructionCircuitClusterDetector)->detect([
            [
                'name' => 'OrganA',
                'capability_label' => 'task_admission',
                'inputs' => ['packet'],
                'outputs' => ['verdict'],
                'proof_refs' => ['test_run_1'],
                'consumers' => ['CallerA'],
            ],
            [
                'name' => 'OrganB',
                'capability_label' => 'task_admission',
                'inputs' => ['packet'],
                'outputs' => ['verdict'],
                'proof_refs' => ['test_run_1'],
                'consumers' => ['CallerB'],
            ],
        ]);

        $cluster = $result['clusters'][0];
        self::assertFalse($cluster['merge_ready']);
        self::assertContains('no_consumer_overlap', $cluster['false_positive_risks']);
    }

    public function test_single_organ_with_unique_capability_forms_no_cluster(): void
    {
        $result = (new AtlasSelfConstructionCircuitClusterDetector)->detect([
            [
                'name' => 'OrganA',
                'capability_label' => 'unique_capability',
                'inputs' => ['packet'],
                'outputs' => ['verdict'],
                'proof_refs' => ['test_run_1'],
                'consumers' => ['Caller'],
            ],
        ]);

        self::assertSame([], $result['clusters']);
    }
}
