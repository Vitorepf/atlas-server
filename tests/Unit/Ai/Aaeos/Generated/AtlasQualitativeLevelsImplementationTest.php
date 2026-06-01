<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasQualitativeLevelsImplementationService;
use Tests\TestCase;

/**
 * Pins the documented Atlas AI Qualitative Levels Implementation Queue contract:
 * the QL-0 .. QL-7 queue table with its verbatim statuses and normalized states,
 * and the hard "## Rule" P4+ promotion gate — a pending readiness (even with a
 * scheduled review) is discipline, not proof, and is blocked; only a literal
 * "ready" status WITH all three required gates allows a P4+ claim; claims below
 * P4 are not gated. Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/roadmap/qualitative-levels-implementation.md
 */
class AtlasQualitativeLevelsImplementationTest extends TestCase
{
    private function service(): AtlasQualitativeLevelsImplementationService
    {
        return new AtlasQualitativeLevelsImplementationService();
    }

    /**
     * Doc queue table: exactly the eight items QL-0 .. QL-7 with verbatim
     * statuses, and statuses normalize into the documented vocabulary. QL-0
     * (Done) is delivered; QL-5 (Future) and QL-7 (Scaffold active) are not.
     */
    public function test_queue_table_matches_doc_items_and_states(): void
    {
        $queue = $this->service()->queue();

        $this->assertSame('ok', $queue['status']);
        $this->assertSame(8, $queue['item_count']);

        $byId = collect($queue['items'])->keyBy('id');

        $this->assertSame('Done', $byId['QL-0']['status']);
        $this->assertSame('done', $byId['QL-0']['implementation_state']);
        $this->assertTrue($byId['QL-0']['is_delivered']);

        $this->assertSame('Implemented', $byId['QL-1']['status']);
        $this->assertSame('implemented', $byId['QL-1']['implementation_state']);

        $this->assertSame('Implemented scaffold', $byId['QL-3']['status']);
        $this->assertSame('scaffold', $byId['QL-3']['implementation_state']);
        $this->assertFalse($byId['QL-3']['is_delivered']);

        $this->assertSame('Future', $byId['QL-5']['status']);
        $this->assertSame('future', $byId['QL-5']['implementation_state']);
        $this->assertFalse($byId['QL-5']['is_delivered']);

        $this->assertSame('Scaffold active', $byId['QL-7']['status']);
        $this->assertSame('scaffold', $byId['QL-7']['implementation_state']);
        $this->assertFalse($byId['QL-7']['is_delivered']);
    }

    /**
     * Doc queue summary counts items by normalized state. From the table:
     * 1 done (QL-0), 2 implemented (QL-1, QL-2), 2 scaffold (QL-3, QL-7),
     * 2 started (QL-4, QL-6), 1 future (QL-5).
     */
    public function test_queue_summary_counts_states_correctly(): void
    {
        $summary = $this->service()->queue()['summary'];

        $this->assertSame(1, $summary['done']);
        $this->assertSame(2, $summary['implemented']);
        $this->assertSame(2, $summary['scaffold']);
        $this->assertSame(2, $summary['started']);
        $this->assertSame(1, $summary['future']);
        $this->assertSame(0, $summary['unknown']);
    }

    /**
     * Doc "## Rule": "p4_promotion_readiness.status=ready is required before any
     * P4 claim; pending scheduled reviews are evidence of discipline, not proof
     * of outcome." A P4 claim with pending readiness + a scheduled review + no
     * gates MUST be blocked, and the discipline-not-proof reason is recorded.
     */
    public function test_pending_p4_claim_with_scheduled_review_is_blocked(): void
    {
        $r = $this->service()->evaluateP4Claim([
            'level' => 'P4',
            'readiness_status' => 'pending',
            'scheduled_review' => true,
            'gates' => [],
        ]);

        $this->assertTrue($r['gate_applies']);
        $this->assertFalse($r['allowed']);
        $this->assertFalse($r['readiness_ready']);
        $this->assertContains('p4_promotion_readiness_not_ready', $r['reasons']);
        $this->assertContains('pending_scheduled_review_is_discipline_not_proof', $r['reasons']);
        $this->assertContains('missing_evidence_ledger_gate', $r['reasons']);
        $this->assertContains('missing_comparative_strategy_evidence_gate', $r['reasons']);
        $this->assertContains('missing_human_agency_gate', $r['reasons']);
        // The Rule itself states pending review is not proof.
        $this->assertFalse($r['rule']['pending_review_is_proof']);
    }

    /**
     * Doc "## Rule": a P4 claim is allowed ONLY when readiness is literally
     * "ready" AND all three named gates (Evidence Ledger, comparative-strategy
     * evidence, human agency) are present. With all four satisfied the claim is
     * allowed and no gate is missing. P5 (>= gate level) behaves identically.
     */
    public function test_ready_p4_claim_with_all_gates_is_allowed(): void
    {
        $r = $this->service()->evaluateP4Claim([
            'level' => 'P4',
            'readiness_status' => 'ready',
            'gates' => [
                'evidence_ledger' => true,
                'comparative_strategy_evidence' => true,
                'human_agency' => true,
            ],
        ]);

        $this->assertTrue($r['gate_applies']);
        $this->assertTrue($r['allowed']);
        $this->assertTrue($r['readiness_ready']);
        $this->assertSame([], $r['missing_gates']);
        $this->assertSame([], $r['reasons']);

        // P5 is also at/above the gate level and stays gated.
        $p5 = $this->service()->evaluateP4Claim([
            'level' => 5,
            'readiness_status' => 'ready',
            'gates' => ['evidence_ledger' => true, 'comparative_strategy_evidence' => true, 'human_agency' => true],
        ]);
        $this->assertTrue($p5['gate_applies']);
        $this->assertTrue($p5['allowed']);
    }

    /**
     * Doc "## Rule": even with readiness "ready", a single missing gate blocks the
     * P4+ claim — the evidence requirement is conjunctive, not satisfied by
     * readiness alone. A falsey gate flag counts as missing (no silent inflation).
     */
    public function test_ready_but_missing_one_gate_still_blocks(): void
    {
        $r = $this->service()->evaluateP4Claim([
            'level' => 'P4',
            'readiness_status' => 'ready',
            'gates' => [
                'evidence_ledger' => true,
                'comparative_strategy_evidence' => false, // falsey -> not proof
                'human_agency' => true,
            ],
        ]);

        $this->assertTrue($r['readiness_ready']);
        $this->assertFalse($r['allowed']);
        $this->assertSame(['comparative_strategy_evidence'], $r['missing_gates']);
        $this->assertContains('missing_comparative_strategy_evidence_gate', $r['reasons']);
    }

    /**
     * Doc "## Rule" scope: the gate applies at P4 and above only. A P3 claim is
     * below the gate, so it is allowed with no readiness/gate requirement.
     */
    public function test_sub_p4_claim_is_not_gated(): void
    {
        $r = $this->service()->evaluateP4Claim([
            'level' => 'P3',
            'readiness_status' => 'pending',
            'gates' => [],
        ]);

        $this->assertFalse($r['gate_applies']);
        $this->assertTrue($r['allowed']);
        $this->assertSame(3, $r['level']);
        $this->assertSame([], $r['reasons']);
    }
}
