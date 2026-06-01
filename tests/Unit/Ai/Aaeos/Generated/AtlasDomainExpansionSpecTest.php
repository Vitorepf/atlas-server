<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDomainExpansionSpecService;
use Tests\TestCase;

/**
 * Pins the executable governance rules from the Domain Expansion Spec doc: the
 * atlas.domain.v1 schema, the 12-item mandatory checklist with its load-bearing
 * thresholds (>=3 depts, >=3 evidence, >=5 gates split 3 universal + 2 specific,
 * maturity must start L0), the cross-dept dependency matrix per kind, the
 * sovereignty Security gate, and "domain sem doc canonico nao recebe roteamento".
 * Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/atlas-domain-expansion-spec.md
 */
class AtlasDomainExpansionSpecTest extends TestCase
{
    private function service(): AtlasDomainExpansionSpecService
    {
        return new AtlasDomainExpansionSpecService;
    }

    /**
     * A proposal that satisfies every documented rule is approved to L0 and
     * routing is enabled. This is the "cyber" worked example from the doc.
     *
     * @return array<string,mixed>
     */
    private function compliantCyberProposal(): array
    {
        return [
            'schema' => 'atlas.domain.v1',
            'id' => 'cyber',
            'human_name' => 'Cyber',
            'scope' => 'defense + threat-intel',
            'kind' => 'trading', // pick a matrix kind to also exercise cross-dept
            'intents_supported' => ['detect_threat', 'harden_surface'],
            // trading matrix requires: architect, security, qa, delivery, memory
            'departments_touched' => ['architect', 'security', 'qa', 'delivery', 'memory'],
            'sovereignty_class' => 'cyber',
            'data_sources' => [
                ['id' => 'threat_feed', 'kind' => 'stream', 'sovereignty_class' => 'cyber'],
            ],
            'skills_required' => ['atlas.skill.threat_intel', 'atlas.skill.sandbox_run'],
            'evidence_required' => ['secret_scan_report', 'sandbox_log', 'architect_receipt'],
            // 5 gates: 3 universal + 2 domain-specific; includes a security-flavoured gate.
            'gates' => ['evidence_required', 'doc_canonical', 'architect_review', 'secret_scan', 'sandbox_only'],
            'maturity_level' => 'L0',
            'canonical_doc_path' => 'docs/engineering-knowledge-base/domains/cyber.md',
            'scope_overlaps_existing' => false,
            'architect_approved' => true,
        ];
    }

    public function test_compliant_proposal_is_promoted_to_l0_active_and_routable(): void
    {
        $decision = $this->service()->evaluateProposal($this->compliantCyberProposal());

        $this->assertSame(AtlasDomainExpansionSpecService::VERDICT_L0_ACTIVE, $decision['verdict']);
        $this->assertSame([], $decision['blocking_reasons']);
        $this->assertSame(12, $decision['checklist_passed']);
        $this->assertSame(12, $decision['checklist_total']);
        $this->assertTrue($decision['routing_enabled']);
        $this->assertTrue($decision['schema_valid']);
        // cyber sovereignty -> Security gate required AND present.
        $this->assertTrue($decision['security_gate']['required']);
        $this->assertTrue($decision['security_gate']['present']);
    }

    public function test_empty_proposal_routes_to_refine_with_routing_disabled(): void
    {
        // Regras para IA: "Domain sem doc canonico nao recebe roteamento."
        $decision = $this->service()->evaluateProposal([]);

        $this->assertSame(AtlasDomainExpansionSpecService::VERDICT_REFINE, $decision['verdict']);
        $this->assertFalse($decision['routing_enabled']);
        $this->assertFalse($decision['schema_valid']);
        // Almost everything is unmet; only the vacuous data_sources item (no
        // entries to misclassify) may pass, so well under the full 12.
        $this->assertLessThan(2, $decision['checklist_passed']);
        $this->assertContains('schema_invalid', $decision['blocking_reasons']);
        $this->assertContains('checklist:item_1_canonical_doc', $decision['blocking_reasons']);
        $this->assertContains('checklist:item_11_maturity_l0', $decision['blocking_reasons']);
    }

    public function test_checklist_thresholds_are_enforced_exactly(): void
    {
        $svc = $this->service();

        // Exactly at the floor passes (3 depts, 3 evidence).
        $atFloor = $svc->checklist([
            'departments_touched' => ['a', 'b', 'c'],
            'evidence_required' => ['e1', 'e2', 'e3'],
        ]);
        $this->assertTrue($atFloor['item_5_departments_min_3']['ok']);
        $this->assertTrue($atFloor['item_9_evidence_min_3']['ok']);

        // One below the floor fails (2 depts, 2 evidence).
        $belowFloor = $svc->checklist([
            'departments_touched' => ['a', 'b'],
            'evidence_required' => ['e1', 'e2'],
        ]);
        $this->assertFalse($belowFloor['item_5_departments_min_3']['ok']);
        $this->assertFalse($belowFloor['item_9_evidence_min_3']['ok']);

        // maturity must be exactly L0 — L1 is forbidden for a brand-new domain.
        $this->assertFalse($svc->checklist(['maturity_level' => 'L1'])['item_11_maturity_l0']['ok']);
        $this->assertTrue($svc->checklist(['maturity_level' => 'L0'])['item_11_maturity_l0']['ok']);
    }

    public function test_gate_split_requires_three_universal_plus_two_domain_specific(): void
    {
        $svc = $this->service();

        // 5 gates but all "universal" -> fails the 2-domain-specific requirement.
        $allUniversal = $svc->checklist([
            'gates' => ['evidence_required', 'doc_canonical', 'architect_review', 'evidence_x', 'review_gate'],
        ]);
        $this->assertFalse($allUniversal['item_10_gates_min_5_split']['ok']);

        // Correct split: 3 universal + 2 domain-specific.
        $split = $svc->gateSplit(['evidence_required', 'doc_canonical', 'architect_review', 'secret_scan', 'sandbox_only']);
        $this->assertSame(3, $split['universal']);
        $this->assertSame(2, $split['domain_specific']);

        // Only 4 gates total -> fails the >=5 minimum regardless of split.
        $tooFew = $svc->checklist([
            'gates' => ['evidence_required', 'doc_canonical', 'secret_scan', 'sandbox_only'],
        ]);
        $this->assertFalse($tooFew['item_10_gates_min_5_split']['ok']);
    }

    public function test_cross_dept_matrix_names_missing_departments_per_kind(): void
    {
        $svc = $this->service();

        // programming requires the full 9-dept set; drop "security" -> missing it.
        $programming = $svc->crossDept([
            'kind' => 'programming',
            'departments_touched' => ['product', 'architect', 'dev', 'forge', 'review', 'qa', 'delivery', 'memory'],
        ]);
        $this->assertFalse($programming['ok']);
        $this->assertSame(['security'], $programming['missing']);

        // finance with its exact documented minimum set -> ok, nothing missing.
        $finance = $svc->crossDept([
            'kind' => 'finance',
            'departments_touched' => ['product', 'architect', 'security', 'review', 'qa', 'delivery', 'memory'],
        ]);
        $this->assertTrue($finance['ok']);
        $this->assertSame([], $finance['missing']);
    }

    public function test_sensitive_sovereignty_requires_extra_security_gate(): void
    {
        $svc = $this->service();

        // sensitive sovereignty but NO security dept / gate -> gate missing -> blocks.
        $base = $this->compliantCyberProposal();
        $base['sovereignty_class'] = 'sensitive';
        $base['departments_touched'] = ['architect', 'qa', 'delivery', 'memory']; // drop security
        $base['gates'] = ['evidence_required', 'doc_canonical', 'architect_review', 'audit_trail', 'retention_policy'];

        $decision = $svc->evaluateProposal($base);
        $this->assertTrue($decision['security_gate']['required']);
        $this->assertFalse($decision['security_gate']['present']);
        $this->assertContains('security_gate_missing', $decision['blocking_reasons']);
        $this->assertSame(AtlasDomainExpansionSpecService::VERDICT_REFINE, $decision['verdict']);

        // ok_to_share sovereignty -> no extra Security gate demanded.
        $shareable = $svc->securityGate(['sovereignty_class' => 'ok_to_share']);
        $this->assertFalse($shareable['required']);
        $this->assertTrue($shareable['ok']);
    }
}
