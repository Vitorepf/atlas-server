<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAgenticEngineeringOsContractsService;
use Tests\TestCase;

/**
 * Pins the executable contracts from the doc: the 15 universal gates (with the
 * "Regras para IA" rule that delivery cannot be declared without verification +
 * evidence + certification, gates 11/12 conditional), the L0..L7 autonomy
 * ladder, the minimum enterprise evidence pack, and the completion criteria
 * (including the "no chat-memory dependence" clause). Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/atlas-agentic-engineering-os-contracts.md
 */
class AtlasAgenticEngineeringOsContractsTest extends TestCase
{
    private function service(): AtlasAgenticEngineeringOsContractsService
    {
        return new AtlasAgenticEngineeringOsContractsService;
    }

    /** Helper: every required gate passed. */
    private function allGatesPassed(): array
    {
        $passed = [];
        foreach ($this->service()->universalGates() as $gate) {
            $passed[$gate['key']] = true;
        }

        return $passed;
    }

    public function test_there_are_exactly_15_ordered_universal_gates(): void
    {
        // Doc "Gates Universais": numbered 1..15.
        $gates = $this->service()->universalGates();

        $this->assertCount(15, $gates);
        $this->assertSame('intent_classified', $gates[0]['key']);   // gate 1
        $this->assertSame('certification', $gates[14]['key']);      // gate 15
        // Only security (11) and release (12) are conditional.
        $conditional = array_values(array_map(
            fn ($g) => $g['key'],
            array_filter($gates, fn ($g) => $g['conditional'])
        ));
        $this->assertSame(['security_check', 'release_plan'], $conditional);
    }

    public function test_delivery_forbidden_when_certification_missing_even_if_everything_else_passed(): void
    {
        // "Regras para IA": Nao declare delivery sem ... certification.
        $passed = $this->allGatesPassed();
        $passed['certification'] = false;

        // Security + release are relevant here so they count as required.
        $r = $this->service()->evaluateUniversalGates($passed, ['security_check', 'release_plan']);

        $this->assertFalse($r['delivery_ready']);
        $this->assertSame('delivery_forbidden', $r['status']);
        $this->assertContains('certification', $r['delivery_blocking_unmet']);
        $this->assertSame(['certification'], $r['unmet']);
    }

    public function test_missing_test_or_evidence_is_delivery_blocking(): void
    {
        // verification (test_or_blocker) and evidence (evidence_pack) are the
        // other two non-negotiable delivery gates.
        $passed = $this->allGatesPassed();
        $passed['test_or_blocker'] = false;
        $passed['evidence_pack'] = false;

        $r = $this->service()->evaluateUniversalGates($passed, ['security_check', 'release_plan']);

        $this->assertSame('delivery_forbidden', $r['status']);
        $this->assertEqualsCanonicalizing(['test_or_blocker', 'evidence_pack'], $r['delivery_blocking_unmet']);
    }

    public function test_conditional_gates_are_skipped_when_not_relevant(): void
    {
        // A pure refactor with no security/release impact: omit those two gates
        // and everything else passes -> delivery is ready.
        $passed = $this->allGatesPassed();
        unset($passed['security_check'], $passed['release_plan']);

        $r = $this->service()->evaluateUniversalGates($passed, []); // none relevant

        $this->assertTrue($r['delivery_ready']);
        $this->assertSame('delivery_ready', $r['status']);
        $this->assertSame([], $r['unmet']);
        $this->assertNotContains('security_check', $r['required_keys']);
        // 13 required gates remain (15 minus the 2 conditional).
        $this->assertCount(13, $r['required_keys']);
    }

    public function test_gate_claimed_out_of_order_is_flagged_and_blocks_delivery(): void
    {
        // Claim certification (gate 15) while an earlier required gate is unmet.
        $passed = $this->allGatesPassed();
        unset($passed['security_check'], $passed['release_plan']);
        $passed['duplicate_check'] = false; // gate 3 unmet, but later gates "passed"

        $r = $this->service()->evaluateUniversalGates($passed, []);

        $this->assertFalse($r['delivery_ready']);
        $this->assertNotEmpty($r['out_of_order']);
        // contiguous progress stops at gate 2 (owner_docs_loaded) since gate 3 is unmet.
        $this->assertSame(2, $r['highest_contiguous_gate']);
    }

    public function test_autonomy_ladder_maps_levels_to_human_role(): void
    {
        // Doc "Autonomy Ladder": L0 human "Faz tudo"; L6 Company; L7 governs sovereignty.
        $svc = $this->service();
        $this->assertCount(8, $svc->autonomyLadder());

        $l0 = $svc->autonomyLevel('l0');
        $this->assertTrue($l0['known']);
        $this->assertSame('Assist', $l0['name']);
        $this->assertSame('Faz tudo', $l0['human']);
        $this->assertFalse($l0['is_self_evolving']);

        $l6 = $svc->autonomyLevel('L6');
        $this->assertSame('Company', $l6['name']);
        $this->assertSame('Atua como gestor', $l6['human']);

        $l7 = $svc->autonomyLevel('L7');
        $this->assertSame('Self-Evolving', $l7['name']);
        $this->assertTrue($l7['is_self_evolving']);

        $this->assertFalse($svc->autonomyLevel('L9')['known']);
    }

    public function test_enterprise_evidence_pack_requires_all_ten_items(): void
    {
        // Doc "Evidencias": 10-item minimum. Drop certification -> incomplete.
        $svc = $this->service();
        $full = [
            'goal_id', 'spec_design_task_hashes', 'provider_decision_receipt',
            'context_code_intelligence_receipt', 'execution_receipts',
            'test_results_or_blocker', 'review_security_findings',
            'release_rollback_decision', 'docs_cartography_decision', 'certification',
        ];

        $this->assertTrue($svc->evaluateEnterpriseEvidence($full)['complete']);

        $partial = array_diff($full, ['certification']);
        $r = $svc->evaluateEnterpriseEvidence($partial);
        $this->assertFalse($r['complete']);
        $this->assertSame(['certification'], $r['missing']);
        $this->assertSame(9, $r['provided_count']);
    }

    public function test_completion_is_impossible_when_it_depends_on_chat_memory(): void
    {
        // Doc "Completion Criteria": full chain present, but it must NOT depend
        // on chat memory.
        $svc = $this->service();
        $fullChain = [
            'goal', 'spec', 'plan', 'execution', 'tests', 'review',
            'security', 'release', 'docs', 'cartography', 'learning', 'certification',
        ];

        $ok = $svc->evaluateCompletion($fullChain, false);
        $this->assertTrue($ok['complete']);
        $this->assertSame('complete', $ok['status']);

        // Same full chain, but it leans on chat memory -> never complete.
        $leans = $svc->evaluateCompletion($fullChain, true);
        $this->assertFalse($leans['complete']);
        $this->assertSame('depends_on_chat_memory', $leans['status']);

        // Missing a stage -> incomplete with that stage listed.
        $missing = $svc->evaluateCompletion(array_diff($fullChain, ['security']), false);
        $this->assertFalse($missing['complete']);
        $this->assertContains('security', $missing['missing']);
    }
}
