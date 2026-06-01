<?php

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasSelfConstructionVerificationCommandCatalogService as Catalog;
use Tests\TestCase;

/**
 * Pins the load-bearing rules of the Self-Construction Verification Command
 * Catalog v1 doc: read-only-only sequences, chain-integrity-before-replay
 * ordering, the during-sprint avoid-list, and the promotion gate that stays
 * forbidden while not_yet_runtime_capable is non-empty. Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/self-construction/evidence-index/atlas-self-construction-verification-command-catalog-v1.md
 */
class AtlasSelfConstructionVerificationCommandCatalogTest extends TestCase
{
    private function service(): Catalog
    {
        return new Catalog;
    }

    public function test_every_canonical_phase_sequence_is_read_only_and_well_ordered(): void
    {
        $service = $this->service();

        foreach (Catalog::PHASES as $phase) {
            $for = $service->sequenceFor($phase);

            $this->assertTrue($for['known'], "phase {$phase} must be a known phase");
            $this->assertTrue($for['sequence_valid'], "canonical sequence for {$phase} must be a valid read-only sequence");

            $result = $service->validateSequence($for['sequence']);
            $this->assertSame([], $result['mutating_commands'], "phase {$phase} must contain no mutating command");
            $this->assertTrue($result['ordering_ok'], "phase {$phase} must satisfy integrity-before-replay");
        }
    }

    public function test_replay_before_chain_integrity_is_rejected(): void
    {
        $service = $this->service();

        // The doc forbids reordering replay ahead of chain-integrity certification.
        $sequence = [
            Catalog::CMD_PROJECTION,
            Catalog::CMD_REPLAY,
            Catalog::CMD_CHAIN_INTEGRITY,
        ];

        $result = $service->validateSequence($sequence);

        $this->assertFalse($result['valid']);
        $this->assertFalse($result['ordering_ok']);
        $this->assertContains('replay_before_chain_integrity_forbidden', $result['violations']);
        $this->assertSame('sequence_rejected_diagnose_before_running', $result['human_label']);

        // The correct order passes ordering.
        $correct = $service->validateSequence([
            Catalog::CMD_PROJECTION,
            Catalog::CMD_CHAIN_INTEGRITY,
            Catalog::CMD_REPLAY,
        ]);
        $this->assertTrue($correct['valid']);
        $this->assertTrue($correct['ordering_ok']);
    }

    public function test_any_mutating_command_is_forbidden_in_a_sequence(): void
    {
        $service = $this->service();

        // "nunca incluir comando mutating" — even smuggled between read-only ones.
        $sequence = [
            Catalog::CMD_CHAIN_INTEGRITY,
            'ledger_write',
            Catalog::CMD_REPLAY,
        ];

        $result = $service->validateSequence($sequence);

        $this->assertFalse($result['valid']);
        $this->assertSame(['ledger_write'], $result['mutating_commands']);
        $this->assertContains('mutating_command_forbidden:ledger_write', $result['violations']);
    }

    public function test_during_sprint_avoids_replay_and_docs_health_but_allows_projection(): void
    {
        $service = $this->service();

        $replay = $service->duringSprintAdvice(Catalog::CMD_REPLAY);
        $this->assertFalse($replay['run_during_sprint']);
        $this->assertSame('expensive_replay_prefer_begin_or_end_of_sprint', $replay['reason']);

        $docsHealth = $service->duringSprintAdvice(Catalog::CMD_DOCS_HEALTH);
        $this->assertFalse($docsHealth['run_during_sprint']);

        // Projection (agent-control-plane) is explicitly free during sprint.
        $projection = $service->duringSprintAdvice(Catalog::CMD_PROJECTION);
        $this->assertTrue($projection['run_during_sprint']);
        $this->assertSame('read_only_no_lock_contention', $projection['reason']);
    }

    public function test_promotion_is_forbidden_while_not_yet_runtime_capable_is_non_empty(): void
    {
        $service = $this->service();

        // Even with everything read-only green, a pending capability blocks promotion.
        $blocked = $service->promotionDecision(
            ['automatic_dispatch_scheduler_runtime'],
            true,
        );
        $this->assertFalse($blocked['promotion_allowed']);
        $this->assertTrue($blocked['os_under_construction']);
        $this->assertSame('not_yet_runtime_capable_non_empty_promotion_forbidden', $blocked['reason']);
        $this->assertSame('promotion_forbidden_os_not_runtime_complete', $blocked['human_label']);

        // Empty negative list but read-only not green -> still not promotable.
        $notGreen = $service->promotionDecision([], false);
        $this->assertFalse($notGreen['promotion_allowed']);
        $this->assertSame('read_only_checks_not_all_green', $notGreen['reason']);

        // Only when negative list is empty AND read-only green do prechecks clear
        // (and even then the mutating gate lives outside this doc).
        $clear = $service->promotionDecision([], true);
        $this->assertTrue($clear['promotion_allowed']);
        $this->assertFalse($clear['os_under_construction']);
    }

    public function test_describe_never_authorizes_promotion_and_exposes_safety_classification(): void
    {
        $service = $this->service();
        $payload = $service->describe();

        $this->assertFalse($payload['promotion_authorized']);
        $this->assertSame(Catalog::SCHEMA_VERSION, $payload['schema_version']);

        // Parallel-safety classification matches the doc table for the costly/sensitive ones.
        $this->assertSame('free_but_costly', $payload['parallel_safety'][Catalog::CMD_REPLAY]);
        $this->assertSame('free_but_snapshot_sensitive', $payload['parallel_safety'][Catalog::CMD_DOCS_HEALTH]);
        $this->assertSame('free', $payload['parallel_safety'][Catalog::CMD_PWD]);

        // Worked samples baked into describe() prove the rules at runtime.
        $this->assertTrue($payload['sample_valid_sequence']['valid']);
        $this->assertFalse($payload['sample_replay_before_integrity']['valid']);
        $this->assertFalse($payload['sample_mutating_rejected']['valid']);
        $this->assertFalse($payload['sample_promotion_blocked']['promotion_allowed']);
    }
}
