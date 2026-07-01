<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Simplification;

use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionBehaviorEquivalenceDossier;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionBehaviorEquivalenceDossierTest extends TestCase
{
    private AtlasSelfConstructionBehaviorEquivalenceDossier $dossier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dossier = new AtlasSelfConstructionBehaviorEquivalenceDossier();
    }

    // AC 2: matching names but different output contracts → unsafe with output_shape_mismatch
    public function test_output_shape_mismatch_marked_unsafe(): void
    {
        $result = $this->dossier->assess([
            'candidate_name' => 'ServiceA',
            'original_name' => 'ServiceA',
            'callgraph' => ['match' => true],
            'output_shape' => ['match' => false],
            'proof_command' => ['command' => 'php artisan test', 'passed' => true],
        ]);

        $this->assertSame('unsafe', $result['verdict']);
        $this->assertFalse($result['safe_to_consolidate']);
        $this->assertContains('output_shape_mismatch', $result['reasons']);
    }

    // AC 3: matching behavior + runnable proof → safe_to_consolidate
    public function test_matching_behavior_with_proof_marked_safe(): void
    {
        $result = $this->dossier->assess([
            'candidate_name' => 'ServiceA',
            'original_name' => 'ServiceA',
            'callgraph' => ['match' => true],
            'output_shape' => ['match' => true],
            'proof_command' => ['command' => 'php artisan test', 'passed' => true],
        ]);

        $this->assertSame('safe_to_consolidate', $result['verdict']);
        $this->assertTrue($result['safe_to_consolidate']);
    }

    // AC 4: missing callgraph evidence → inconclusive, not safe
    public function test_missing_callgraph_evidence_keeps_inconclusive(): void
    {
        $result = $this->dossier->assess([
            'candidate_name' => 'ServiceA',
            'original_name' => 'ServiceA',
            'output_shape' => ['match' => true],
            'proof_command' => ['command' => 'php artisan test', 'passed' => true],
        ]);

        $this->assertSame('inconclusive', $result['verdict']);
        $this->assertFalse($result['safe_to_consolidate']);
        $this->assertContains('missing_callgraph_evidence', $result['reasons']);
    }

    public function test_all_evidence_missing_is_inconclusive(): void
    {
        $result = $this->dossier->assess([]);

        $this->assertSame('inconclusive', $result['verdict']);
        $this->assertFalse($result['safe_to_consolidate']);
    }

    public function test_proof_command_failed_marked_unsafe(): void
    {
        $result = $this->dossier->assess([
            'callgraph' => ['match' => true],
            'output_shape' => ['match' => true],
            'proof_command' => ['command' => 'php artisan test', 'passed' => false],
        ]);

        $this->assertSame('unsafe', $result['verdict']);
        $this->assertContains('proof_command_failed', $result['reasons']);
    }

    public function test_callgraph_mismatch_marked_unsafe(): void
    {
        $result = $this->dossier->assess([
            'callgraph' => ['match' => false],
            'output_shape' => ['match' => true],
            'proof_command' => ['command' => 'php artisan test', 'passed' => true],
        ]);

        $this->assertSame('unsafe', $result['verdict']);
        $this->assertContains('callgraph_mismatch', $result['reasons']);
    }

    public function test_missing_output_shape_evidence_is_inconclusive(): void
    {
        $result = $this->dossier->assess([
            'callgraph' => ['match' => true],
            'proof_command' => ['command' => 'php artisan test', 'passed' => true],
        ]);

        $this->assertSame('inconclusive', $result['verdict']);
        $this->assertContains('missing_output_shape_evidence', $result['reasons']);
    }

    public function test_missing_proof_command_is_inconclusive(): void
    {
        $result = $this->dossier->assess([
            'callgraph' => ['match' => true],
            'output_shape' => ['match' => true],
        ]);

        $this->assertSame('inconclusive', $result['verdict']);
        $this->assertContains('missing_proof_command', $result['reasons']);
    }
}
