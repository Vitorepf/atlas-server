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

    // ── AC2: nested dotted-path divergence ──────────────────────────────────

    public function test_output_shape_nested_divergence_reports_dotted_path(): void
    {
        $result = $this->dossier->assess([
            'callgraph'    => ['match' => true],
            'output_shape' => [
                'original'  => ['api' => ['status' => ['rate_limit' => 100, 'version' => 'v2']]],
                'candidate' => ['api' => ['status' => ['rate_limit' => 50, 'version' => 'v2']]],
            ],
            'proof_command' => ['command' => 'php artisan test', 'passed' => true],
        ]);

        $this->assertSame('unsafe', $result['verdict']);
        $this->assertContains('output_shape_mismatch', $result['reasons']);
        $this->assertContains('outputs_diverge:api.status.rate_limit', $result['reasons']);
        // version matches — no divergence for that path
        $this->assertNotContains('outputs_diverge:api.status.version', $result['reasons']);
    }

    public function test_multiple_nested_divergences_all_reported(): void
    {
        $result = $this->dossier->assess([
            'callgraph'    => ['match' => true],
            'output_shape' => [
                'original'  => ['a' => ['x' => 1, 'y' => 2], 'b' => 3],
                'candidate' => ['a' => ['x' => 9, 'y' => 2], 'b' => 0],
            ],
            'proof_command' => ['command' => 'php artisan test', 'passed' => true],
        ]);

        $paths = array_values(array_filter($result['reasons'], fn (string $r): bool => str_starts_with($r, 'outputs_diverge:')));
        $this->assertContains('outputs_diverge:a.x', $paths);
        $this->assertContains('outputs_diverge:b', $paths);
        $this->assertNotContains('outputs_diverge:a.y', $paths);
    }

    // ── AC3: tolerated_deltas suppresses specific nested paths ───────────────

    public function test_tolerated_deltas_suppresses_one_path_while_reporting_another(): void
    {
        $result = $this->dossier->assess([
            'callgraph'       => ['match' => true],
            'output_shape'    => [
                'original'  => ['api' => ['status' => ['rate_limit' => 100, 'version' => 'v2']]],
                'candidate' => ['api' => ['status' => ['rate_limit' => 50, 'version' => 'v2']]],
            ],
            'proof_command'    => ['command' => 'php artisan test', 'passed' => true],
            'tolerated_deltas' => ['api.status.rate_limit'],
        ]);

        // All divergences suppressed → safe
        $this->assertSame('safe_to_consolidate', $result['verdict']);
        $this->assertTrue($result['safe_to_consolidate']);
        $this->assertNotContains('outputs_diverge:api.status.rate_limit', $result['reasons']);
    }

    public function test_tolerated_deltas_suppresses_subtree(): void
    {
        $result = $this->dossier->assess([
            'callgraph'       => ['match' => true],
            'output_shape'    => [
                'original'  => ['api' => ['status' => ['rate_limit' => 100, 'version' => 'v2'], 'health' => 'ok']],
                'candidate' => ['api' => ['status' => ['rate_limit' => 50, 'version' => 'v3'], 'health' => 'ok']],
            ],
            'proof_command'    => ['command' => 'php artisan test', 'passed' => true],
            'tolerated_deltas' => ['api.status'],
        ]);

        // tolerated_deltas covers the whole api.status subtree (rate_limit + version)
        $this->assertSame('safe_to_consolidate', $result['verdict']);
        $this->assertNotContains('outputs_diverge:api.status.rate_limit', $result['reasons']);
        $this->assertNotContains('outputs_diverge:api.status.version', $result['reasons']);
    }

    public function test_tolerated_deltas_one_path_suppressed_another_still_diverges(): void
    {
        $result = $this->dossier->assess([
            'callgraph'       => ['match' => true],
            'output_shape'    => [
                'original'  => ['api' => ['status' => 'healthy'], 'version' => 'v1'],
                'candidate' => ['api' => ['status' => 'healthy'], 'version' => 'v2'],
            ],
            'proof_command'    => ['command' => 'php artisan test', 'passed' => true],
            'tolerated_deltas' => ['api.status'],
        ]);

        // version diverges and is NOT tolerated → still unsafe
        $this->assertSame('unsafe', $result['verdict']);
        $this->assertContains('output_shape_mismatch', $result['reasons']);
        $this->assertContains('outputs_diverge:version', $result['reasons']);
    }

    // ── AC4: output_shape_diff and dossier_hash stability ────────────────────

    public function test_output_shape_diff_included_in_result(): void
    {
        $result = $this->dossier->assess([
            'callgraph'       => ['match' => true],
            'output_shape'    => [
                'original'  => ['key' => 'a'],
                'candidate' => ['key' => 'b'],
            ],
            'proof_command'    => ['command' => 'php artisan test', 'passed' => true],
            'tolerated_deltas' => [],
        ]);

        $this->assertArrayHasKey('output_shape_diff', $result);
        $this->assertContains('key', $result['output_shape_diff']);
    }

    public function test_dossier_hash_stable_for_identical_evidence(): void
    {
        $evidence = [
            'callgraph'      => ['match' => true],
            'output_shape'   => ['original' => ['a' => 1], 'candidate' => ['a' => 2]],
            'proof_command'  => ['command' => 'php artisan test', 'passed' => true],
        ];

        $a = $this->dossier->assess($evidence);
        $b = $this->dossier->assess($evidence);

        $this->assertArrayHasKey('dossier_hash', $a);
        $this->assertSame($a['dossier_hash'], $b['dossier_hash']);
    }

    public function test_dossier_hash_64_hex_chars(): void
    {
        $result = $this->dossier->assess([]);

        $this->assertArrayHasKey('dossier_hash', $result);
        $this->assertSame(64, strlen($result['dossier_hash']));
        $this->assertTrue(ctype_xdigit($result['dossier_hash']));
    }

    public function test_dossier_hash_changes_when_evidence_differs(): void
    {
        $a = $this->dossier->assess(['output_shape' => ['match' => true]]);
        $b = $this->dossier->assess(['output_shape' => ['match' => false]]);

        $this->assertNotSame($a['dossier_hash'], $b['dossier_hash']);
    }

    // ── AC4: equivalent nested outputs keep ready_for_simplification ─────────

    public function test_matching_nested_outputs_yields_safe_even_without_flat_match(): void
    {
        $result = $this->dossier->assess([
            'callgraph'    => ['match' => true],
            'output_shape' => [
                'original'  => ['user' => ['name' => 'alice', 'role' => 'admin']],
                'candidate' => ['user' => ['name' => 'alice', 'role' => 'admin']],
            ],
            'proof_command' => ['command' => 'php artisan test', 'passed' => true],
        ]);

        $this->assertSame('safe_to_consolidate', $result['verdict']);
        $this->assertTrue($result['safe_to_consolidate']);
    }
}
