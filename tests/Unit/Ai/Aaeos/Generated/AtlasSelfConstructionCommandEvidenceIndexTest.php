<?php

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasSelfConstructionCommandEvidenceIndexService as Index;
use Tests\TestCase;

/**
 * Pins the load-bearing rules of the Self-Construction Command Evidence Index
 * v1 doc: every catalogued command is read-only and carries a failure_meaning,
 * a mutating row is rejected from the index, any *_allowed flag true means the
 * projection regressed, status "available" never implies runtime, a misaligned
 * pointer blocks macro-sprint advance, and a changed replay hash is
 * non-deterministic. Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/self-construction/evidence-index/atlas-self-construction-command-evidence-index-v1.md
 */
class AtlasSelfConstructionCommandEvidenceIndexTest extends TestCase
{
    private function service(): Index
    {
        return new Index;
    }

    public function test_every_catalogued_command_is_read_only_and_has_a_failure_meaning(): void
    {
        $service = $this->service();

        // The whole canonical index must pass integrity: no mutating rows, no
        // row with an empty failure_meaning (the headline operational gain).
        $integrity = $service->catalogIntegrity();
        $this->assertTrue($integrity['valid'], 'canonical index must be intact');
        $this->assertSame([], $integrity['mutating_rows']);
        $this->assertSame([], $integrity['rows_missing_failure_meaning']);
        $this->assertSame(8, $integrity['row_count']);

        // And per command: read_only is derived true, failure_meaning non-empty.
        foreach ($service->commands() as $cmd) {
            $row = $service->evidenceFor($cmd);
            $this->assertTrue($row['known'], "{$cmd} must be known");
            $this->assertFalse($row['writes_storage'], "{$cmd} must not write storage");
            $this->assertFalse($row['writes_ledger'], "{$cmd} must not write ledger");
            $this->assertTrue($row['read_only'], "{$cmd} must be read-only");
            $this->assertNotSame('', $row['failure_meaning'], "{$cmd} must keep failure_meaning");
        }
    }

    public function test_a_mutating_command_is_rejected_from_the_read_only_index(): void
    {
        $service = $this->service();

        // "nunca incluir comandos mutating" — a row that writes ledger/storage,
        // plus a row with a silenced (empty) failure_meaning, must both fail.
        $badRows = Index::INDEX + [
            'ledger_write' => [
                'purpose' => 'persist a decision receipt',
                'safe_parallel' => Index::PARALLEL_FREE,
                'writes_storage' => true,
                'writes_ledger' => true,
                'roles' => [],
                'key_fields' => [],
                'failure_meaning' => 'x',
            ],
            'silenced' => [
                'purpose' => 'probe',
                'safe_parallel' => Index::PARALLEL_FREE,
                'writes_storage' => false,
                'writes_ledger' => false,
                'roles' => [],
                'key_fields' => [],
                'failure_meaning' => '',
            ],
        ];

        $integrity = $service->catalogIntegrity($badRows);

        $this->assertFalse($integrity['valid']);
        $this->assertSame(['ledger_write'], $integrity['mutating_rows']);
        $this->assertSame(['silenced'], $integrity['rows_missing_failure_meaning']);
        $this->assertContains('mutating_command_in_read_only_index:ledger_write', $integrity['violations']);
        $this->assertContains('missing_failure_meaning:silenced', $integrity['violations']);
    }

    public function test_any_allowed_flag_true_means_the_projection_regressed(): void
    {
        $service = $this->service();

        // The baseline evidence: all *_allowed flags false -> read-only preserved.
        $safe = $service->runtimeSafetyVerdict([
            'execution_allowed' => false,
            'completion_allowed' => false,
            'dispatch_allowed' => false,
            'claim_persisted' => false,
            'ledger_write_allowed' => false,
        ], true);
        $this->assertTrue($safe['read_only_safe']);
        $this->assertFalse($safe['regressed']);
        $this->assertSame([], $safe['offending_flags']);

        // A single allowed flag flipping true is a critical regression.
        $regressed = $service->runtimeSafetyVerdict(['dispatch_allowed' => true]);
        $this->assertTrue($regressed['regressed']);
        $this->assertFalse($regressed['read_only_safe']);
        $this->assertSame(['dispatch_allowed'], $regressed['offending_flags']);
        $this->assertTrue($service->runtimeSafetyRegressed(['dispatch_allowed' => true]));

        // runtime_safety_all_false=false is itself a regression even with no per-flag.
        $aggregate = $service->runtimeSafetyVerdict([], false);
        $this->assertTrue($aggregate['regressed']);
        $this->assertSame('runtime_safety_all_false_is_false_projection_allows_runtime', $aggregate['reason']);
    }

    public function test_status_available_is_certification_not_runtime(): void
    {
        $service = $this->service();

        // The central operator-safety rule: available = read-only certification
        // available, NEVER runtime executing.
        $verdict = $service->projectionStatusVerdict('available');
        $this->assertTrue($verdict['certification_available']);
        $this->assertFalse($verdict['runtime_executing']);
        $this->assertSame('read_only_certification_available', $verdict['capability_class']);

        // agent_control_plane_ready is treated the same way.
        $ready = $service->projectionStatusVerdict('agent_control_plane_ready');
        $this->assertTrue($ready['certification_available']);
        $this->assertFalse($ready['runtime_executing']);

        // describe() must never say projection status implies runtime.
        $payload = $service->describe();
        $this->assertFalse($payload['projection_status_ever_implies_runtime']);
    }

    public function test_misaligned_pointer_blocks_advance_and_changed_replay_hash_is_non_deterministic(): void
    {
        $service = $this->service();

        // current != expected -> misaligned -> blocks macro-sprint advance.
        $misaligned = $service->pointerAligned('slice_a', 'slice_b');
        $this->assertFalse($misaligned['aligned']);
        $this->assertTrue($misaligned['blocks_macro_sprint_advance']);
        $this->assertSame('pointer_misaligned_blocks_macro_sprint_advance', $misaligned['reason']);

        $aligned = $service->pointerAligned('slice_a', 'slice_a');
        $this->assertTrue($aligned['aligned']);
        $this->assertFalse($aligned['blocks_macro_sprint_advance']);

        // Any hash changing between two runs (no code change) -> non-determinism.
        $regressed = $service->replayDeterminismVerdict(
            ['replay_hash' => 'h1', 'proof_bundle_hash' => 'p1'],
            ['replay_hash' => 'h2', 'proof_bundle_hash' => 'p1'],
        );
        $this->assertFalse($regressed['deterministic']);
        $this->assertTrue($regressed['regressed']);
        $this->assertSame(['replay_hash'], $regressed['changed_hashes']);

        // Identical hashes -> deterministic.
        $stable = $service->replayDeterminismVerdict(
            ['replay_hash' => 'h1', 'proof_bundle_hash' => 'p1'],
            ['replay_hash' => 'h1', 'proof_bundle_hash' => 'p1'],
        );
        $this->assertTrue($stable['deterministic']);
        $this->assertFalse($stable['regressed']);
    }

    public function test_certifier_role_classification_matches_the_doc(): void
    {
        $service = $this->service();

        // Runtime-safety certifiers: projection + chain-integrity + replay.
        $runtimeSafety = $service->commandsWithRole(Index::ROLE_RUNTIME_SAFETY);
        $this->assertContains(Index::CMD_PROJECTION, $runtimeSafety);
        $this->assertContains(Index::CMD_CHAIN_INTEGRITY, $runtimeSafety);
        $this->assertContains(Index::CMD_REPLAY, $runtimeSafety);

        // Pointer certifiers: chain-integrity + replay (NOT the plain projection).
        $pointer = $service->commandsWithRole(Index::ROLE_POINTER);
        $this->assertContains(Index::CMD_CHAIN_INTEGRITY, $pointer);
        $this->assertContains(Index::CMD_REPLAY, $pointer);
        $this->assertNotContains(Index::CMD_PROJECTION, $pointer);

        // Architecture/docs certifiers: docs-health + architecture-validate.
        $archDocs = $service->commandsWithRole(Index::ROLE_ARCHITECTURE_DOCS);
        $this->assertSame(
            [Index::CMD_DOCS_HEALTH, Index::CMD_ARCHITECTURE_VALIDATE],
            $archDocs,
        );

        // The costly replay is classified free_but_costly per the safe_parallel column.
        $this->assertSame(Index::PARALLEL_FREE_BUT_COSTLY, $service->evidenceFor(Index::CMD_REPLAY)['safe_parallel']);
    }
}
