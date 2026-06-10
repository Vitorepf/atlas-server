<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Obra;

use App\Services\Ai\Obra\AtlasObraCertificationService;
use Tests\TestCase;

/**
 * AOBG N3.F3 — INTEGRATION CERTIFICATION of the WHOLE obra (the pure decision).
 *
 * Locks {@see AtlasObraCertificationService}: the whole-obra verdict is fail-closed
 * and whole > parts. A per-step pass NEVER implies the obra integrates — certified is
 * true ONLY when no step halted AND a SUPPLIED integrated check ran and passed.
 *
 * These tests are pure (no IO, no git, no provider) — the integrated measure result
 * is handed in, so the decision logic is proven in isolation, cost-free.
 */
final class AtlasObraCertificationServiceTest extends TestCase
{
    private function svc(): AtlasObraCertificationService
    {
        return new AtlasObraCertificationService;
    }

    /** A canonical 2-step all-green node-receipt list. */
    private function passingNodes(): array
    {
        return [
            ['id' => 'o:n0', 'seq' => 0, 'status' => 'done', 'commit' => 'aaa111', 'gate_receipt' => str_repeat('a', 40), 'files_changed' => ['a.php']],
            ['id' => 'o:n1', 'seq' => 1, 'status' => 'done', 'commit' => 'bbb222', 'gate_receipt' => str_repeat('b', 40), 'files_changed' => ['b.php']],
        ];
    }

    // ------------------------------------------------------------------
    // certified=true ONLY when steps passed AND the integrated check passed.
    // ------------------------------------------------------------------

    public function test_passing_steps_plus_passing_integrated_check_is_certified(): void
    {
        $env = $this->svc()->certify([
            'obra_id' => 'obra-x',
            'branch' => 'atlas/obra/obra-x',
            'halted' => false,
            'nodes' => $this->passingNodes(),
            'integrated' => ['ran' => true, 'passed' => true, 'cmd' => 'phpunit', 'exit_code' => 0, 'output_tail' => 'OK (2 tests)'],
            'integrated_supplied' => true,
        ]);

        $this->assertTrue($env['certified']);
        $this->assertSame(AtlasObraCertificationService::DISPOSITION_CERTIFIED, $env['disposition']);
        $this->assertSame('certified', $env['status']);
        $this->assertNull($env['reason']);
        $this->assertSame(2, $env['node_count']);
        $this->assertSame(2, $env['files_total']);
        $this->assertTrue($env['integrated_test_result']['passed']);
        $this->assertNotEmpty($env['receipt_hash']);
    }

    // ------------------------------------------------------------------
    // THE F3 INVARIANT — per-step passes do NOT imply integration.
    // ------------------------------------------------------------------

    public function test_passing_steps_but_a_failing_integrated_check_is_needs_review_not_certified(): void
    {
        $env = $this->svc()->certify([
            'obra_id' => 'obra-y',
            'branch' => 'atlas/obra/obra-y',
            'halted' => false,
            // EVERY step certified in isolation...
            'nodes' => $this->passingNodes(),
            // ...yet the ASSEMBLED branch's integrated test FAILED (the whole did not integrate).
            'integrated' => ['ran' => true, 'passed' => false, 'cmd' => 'phpunit', 'exit_code' => 1, 'output_tail' => 'FAILURES! Tests: 2, Failures: 1'],
            'integrated_supplied' => true,
        ]);

        $this->assertFalse($env['certified'], 'a per-step pass must NOT certify a non-integrating obra');
        $this->assertSame(AtlasObraCertificationService::DISPOSITION_INTEGRATION_FAILED, $env['disposition']);
        $this->assertSame('needs_review', $env['status']);
        $this->assertSame('integrated_check_failed', $env['reason']);
        // The per-step receipts are still in the envelope (the branch is kept for review).
        $this->assertSame(2, $env['node_count']);
        $this->assertFalse($env['integrated_test_result']['passed']);
    }

    public function test_a_supplied_integrated_check_that_could_not_run_is_needs_review_fail_closed(): void
    {
        $env = $this->svc()->certify([
            'obra_id' => 'obra-z',
            'branch' => 'atlas/obra/obra-z',
            'halted' => false,
            'nodes' => $this->passingNodes(),
            // Supplied but unrunnable (e.g. the worktree was gone) — NOT a pass.
            'integrated' => ['ran' => false, 'passed' => false, 'cmd' => 'phpunit', 'exit_code' => null, 'output_tail' => 'worktree_invalid'],
            'integrated_supplied' => true,
        ]);

        $this->assertFalse($env['certified'], 'an unrunnable integrated check is never a pass');
        $this->assertSame(AtlasObraCertificationService::DISPOSITION_INTEGRATION_UNRUNNABLE, $env['disposition']);
        $this->assertSame('needs_review', $env['status']);
        $this->assertSame('integrated_check_unrunnable', $env['reason']);
    }

    // ------------------------------------------------------------------
    // NO-CHECK baseline (F2) — complete, certified, but documented as not integration-tested.
    // ------------------------------------------------------------------

    public function test_no_integrated_check_is_certified_baseline_but_documented(): void
    {
        $env = $this->svc()->certify([
            'obra_id' => 'obra-base',
            'branch' => 'atlas/obra/obra-base',
            'halted' => false,
            'nodes' => $this->passingNodes(),
            'integrated' => null,
            'integrated_supplied' => false,
        ]);

        // The F2 baseline: all steps certified + applied to one branch IS a real result.
        $this->assertTrue($env['certified']);
        $this->assertSame(AtlasObraCertificationService::DISPOSITION_NO_INTEGRATED_CHECK, $env['disposition']);
        $this->assertSame('certified', $env['status']);
        // ...but honestly DOCUMENTED as not integration-tested as a unit.
        $this->assertFalse($env['integrated_test_result']['supplied']);
        $this->assertFalse($env['integrated_test_result']['ran']);
    }

    // ------------------------------------------------------------------
    // HALTED — F2 already not-certified; the envelope echoes it.
    // ------------------------------------------------------------------

    public function test_a_halted_obra_is_never_certified_even_with_an_integrated_pass(): void
    {
        $env = $this->svc()->certify([
            'obra_id' => 'obra-halt',
            'branch' => 'atlas/obra/obra-halt',
            'halted' => true,
            'failed_node' => 'obra-halt:n1',
            'nodes' => [
                ['id' => 'obra-halt:n0', 'seq' => 0, 'status' => 'done', 'commit' => 'c1', 'gate_receipt' => str_repeat('a', 40)],
                ['id' => 'obra-halt:n1', 'seq' => 1, 'status' => 'failed'],
            ],
            // Even if (defensively) an integrated pass were handed in, a halt wins.
            'integrated' => ['ran' => true, 'passed' => true, 'exit_code' => 0],
            'integrated_supplied' => true,
        ]);

        $this->assertFalse($env['certified']);
        $this->assertSame(AtlasObraCertificationService::DISPOSITION_HALTED, $env['disposition']);
        $this->assertStringContainsString('halted_on_node:obra-halt:n1', (string) $env['reason']);
    }

    // ------------------------------------------------------------------
    // Determinism + privacy.
    // ------------------------------------------------------------------

    public function test_receipt_hash_is_deterministic_over_the_same_assembled_state(): void
    {
        $input = [
            'obra_id' => 'obra-det',
            'branch' => 'atlas/obra/obra-det',
            'halted' => false,
            'nodes' => $this->passingNodes(),
            'integrated' => ['ran' => true, 'passed' => true, 'exit_code' => 0],
            'integrated_supplied' => true,
        ];

        $a = $this->svc()->certify($input);
        $b = $this->svc()->certify($input);
        $this->assertSame($a['receipt_hash'], $b['receipt_hash'], 're-certifying the same state yields the same hash');

        // A different integrated verdict flips the hash (tamper-evident).
        $input['integrated']['passed'] = false;
        $c = $this->svc()->certify($input);
        $this->assertNotSame($a['receipt_hash'], $c['receipt_hash']);
    }

    public function test_envelope_carries_no_source_only_refs(): void
    {
        $env = $this->svc()->certify([
            'obra_id' => 'obra-priv',
            'branch' => 'atlas/obra/obra-priv',
            'halted' => false,
            // A delivery might include source-ish keys — they must be DROPPED.
            'nodes' => [[
                'id' => 'o:n0', 'seq' => 0, 'status' => 'done', 'commit' => 'c1',
                'gate_receipt' => str_repeat('a', 40), 'files_changed' => ['a.php'],
                'content' => '<?php secret', 'diff' => '@@ -1 +1 @@', 'source' => 'x',
            ]],
            'integrated' => ['ran' => true, 'passed' => true, 'exit_code' => 0],
            'integrated_supplied' => true,
        ]);

        $node = $env['nodes'][0];
        $this->assertArrayNotHasKey('content', $node);
        $this->assertArrayNotHasKey('diff', $node);
        $this->assertArrayNotHasKey('source', $node);
        $this->assertArrayHasKey('commit', $node);
        $this->assertArrayHasKey('files_changed', $node);
    }
}
