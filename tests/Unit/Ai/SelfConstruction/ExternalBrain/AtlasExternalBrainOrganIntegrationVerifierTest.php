<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOrganIntegrationVerifier;
use Tests\TestCase;

final class AtlasExternalBrainOrganIntegrationVerifierTest extends TestCase
{
    private function svc(): AtlasExternalBrainOrganIntegrationVerifier
    {
        return new AtlasExternalBrainOrganIntegrationVerifier;
    }

    private function organ(string $id, bool $tests = true, bool $impl = true): array
    {
        return ['organ_id' => $id, 'has_tests' => $tests, 'has_implementation' => $impl];
    }

    /** Full circuit facts for organ $id, used as a baseline that tests mutate to break one element. */
    private function fullCircuitFacts(string $id): array
    {
        return [
            'input_sources' => [$id => ['real_upstream_signal']],
            'decision_roles' => [$id => 'gates_dispatch'],
            'flow_usage' => [$id => ['decision_loop']],
            'learning_feedback' => [$id => true],
            'failure_handling' => [$id => true],
        ];
    }

    // ── integrated (full circuit) ─────────────────────────────────────────────

    public function test_organ_with_full_circuit_is_integrated(): void
    {
        $r = $this->svc()->verify(array_merge(
            ['organ_inventory' => [$this->organ('A')]],
            $this->fullCircuitFacts('A'),
        ));

        $this->assertSame(AtlasExternalBrainOrganIntegrationVerifier::STATUS_INTEGRATED, $r['results'][0]['status']);
        $this->assertContains('A', $r['integrated_ids']);
        $this->assertSame(['decision_loop'], $r['results'][0]['consumers']);
        $this->assertSame([], $r['results'][0]['circuit_gaps']);
        $this->assertNull($r['results'][0]['orphan_reason'], 'integrated organ must have null orphan_reason');
        $this->assertIsArray($r['results'][0]['required_wiring']);
        $this->assertContains('input_source', $r['results'][0]['required_wiring']);
        $this->assertNotEmpty($r['results'][0]['evidence_refs']);
    }

    public function test_organ_with_control_plane_exposure_satisfies_output_consumer_leg(): void
    {
        $facts = $this->fullCircuitFacts('B');
        unset($facts['flow_usage']);
        $facts['control_plane_exposure'] = ['B' => true];

        $r = $this->svc()->verify(array_merge(['organ_inventory' => [$this->organ('B')]], $facts));

        $this->assertSame(AtlasExternalBrainOrganIntegrationVerifier::STATUS_INTEGRATED, $r['results'][0]['status']);
        $this->assertTrue($r['results'][0]['control_plane_exposed']);
    }

    // ── consumer alone is NOT integration proof (AC1/AC3) ─────────────────────

    public function test_organ_with_only_flow_usage_consumer_is_not_integrated(): void
    {
        $r = $this->svc()->verify([
            'organ_inventory' => [$this->organ('A')],
            'flow_usage' => ['A' => ['decision_loop']],
        ]);

        $this->assertNotSame(AtlasExternalBrainOrganIntegrationVerifier::STATUS_INTEGRATED, $r['results'][0]['status']);
        $this->assertContains('no_input_source', $r['results'][0]['circuit_gaps']);
        $this->assertContains('no_decision_role', $r['results'][0]['circuit_gaps']);
        $this->assertContains('no_learning_feedback', $r['results'][0]['circuit_gaps']);
    }

    public function test_organ_with_only_control_plane_exposure_is_not_integrated(): void
    {
        $r = $this->svc()->verify([
            'organ_inventory' => [$this->organ('B')],
            'control_plane_exposure' => ['B' => true],
        ]);

        $this->assertNotSame(AtlasExternalBrainOrganIntegrationVerifier::STATUS_INTEGRATED, $r['results'][0]['status']);
    }

    public function test_tests_and_implementation_alone_never_certify_integration(): void
    {
        $r = $this->svc()->verify([
            'organ_inventory' => [$this->organ('PROOF', true, true)],
        ]);

        $this->assertNotSame(AtlasExternalBrainOrganIntegrationVerifier::STATUS_INTEGRATED, $r['results'][0]['status']);
    }

    // ── one-way output: has consumer but no learning feedback ────────────────

    public function test_consumer_without_learning_feedback_is_flagged_one_way_output(): void
    {
        $facts = $this->fullCircuitFacts('G');
        unset($facts['learning_feedback']);

        $r = $this->svc()->verify(array_merge(['organ_inventory' => [$this->organ('G')]], $facts));

        $this->assertTrue($r['results'][0]['one_way_output']);
        $this->assertFalse($r['results'][0]['orphan'], 'it has a consumer, so it is not a pure orphan');
        $this->assertNotEmpty($r['results'][0]['remediation']);
    }

    // ── missing failure path ──────────────────────────────────────────────────

    public function test_organ_without_failure_handling_is_flagged_missing_failure_path(): void
    {
        $facts = $this->fullCircuitFacts('H');
        unset($facts['failure_handling']);

        $r = $this->svc()->verify(array_merge(['organ_inventory' => [$this->organ('H')]], $facts));

        $this->assertTrue($r['results'][0]['missing_failure_path']);
        $this->assertNotSame(AtlasExternalBrainOrganIntegrationVerifier::STATUS_INTEGRATED, $r['results'][0]['status']);
    }

    public function test_full_circuit_organ_has_no_missing_failure_path_flag(): void
    {
        $r = $this->svc()->verify(array_merge(
            ['organ_inventory' => [$this->organ('I')]],
            $this->fullCircuitFacts('I'),
        ));

        $this->assertFalse($r['results'][0]['missing_failure_path']);
    }

    // ── intentionally standalone ──────────────────────────────────────────────

    public function test_organ_with_standalone_justification_is_intentionally_standalone(): void
    {
        $r = $this->svc()->verify([
            'organ_inventory' => [$this->organ('C')],
            'standalone_justifications' => ['C' => 'pure utility, consumed dynamically'],
        ]);

        $this->assertSame(AtlasExternalBrainOrganIntegrationVerifier::STATUS_INTENTIONALLY_STANDALONE, $r['results'][0]['status']);
        $this->assertContains('C', $r['intentionally_standalone_ids']);
        $this->assertSame('pure utility, consumed dynamically', $r['results'][0]['standalone_reason']);
    }

    public function test_full_circuit_takes_priority_over_standalone_justification(): void
    {
        $facts = $this->fullCircuitFacts('C2');
        $facts['standalone_justifications'] = ['C2' => 'irrelevant now, the circuit is complete'];

        $r = $this->svc()->verify(array_merge(['organ_inventory' => [$this->organ('C2')]], $facts));

        $this->assertSame(AtlasExternalBrainOrganIntegrationVerifier::STATUS_INTEGRATED, $r['results'][0]['status']);
    }

    // ── orphaned (true orphan: no output consumer at all) ─────────────────────

    public function test_organ_with_tests_and_impl_but_no_wiring_is_orphaned(): void
    {
        $r = $this->svc()->verify([
            'organ_inventory' => [$this->organ('D', true, true)],
        ]);

        $this->assertSame(AtlasExternalBrainOrganIntegrationVerifier::STATUS_ORPHANED, $r['results'][0]['status']);
        $this->assertContains('D', $r['orphaned_ids']);
        $this->assertTrue($r['results'][0]['capability_island'], 'has_tests+impl but no wiring → capability_island=true');
        $this->assertTrue($r['results'][0]['orphan']);
        $this->assertIsString($r['results'][0]['orphan_reason']);
        $this->assertStringContainsString('not wired', $r['results'][0]['orphan_reason']);
        $this->assertStringContainsString('no_input_source', $r['results'][0]['orphan_reason']);
        $this->assertSame([], $r['results'][0]['evidence_refs'], 'orphan with no wiring has no evidence refs');
        $this->assertIsArray($r['results'][0]['required_wiring']);
    }

    public function test_orphaned_organ_sets_has_orphans_true(): void
    {
        $r = $this->svc()->verify([
            'organ_inventory' => [$this->organ('E')],
        ]);

        $this->assertTrue($r['has_orphans']);
    }

    public function test_capability_island_false_when_organ_lacks_impl(): void
    {
        $r = $this->svc()->verify([
            'organ_inventory' => [$this->organ('F', true, false)],
        ]);

        $this->assertSame(AtlasExternalBrainOrganIntegrationVerifier::STATUS_ORPHANED, $r['results'][0]['status']);
        $this->assertFalse($r['results'][0]['capability_island']);
    }

    // ── multi-organ classification ────────────────────────────────────────────

    public function test_multiple_organs_classified_independently(): void
    {
        $r = $this->svc()->verify(array_merge_recursive(
            [
                'organ_inventory' => [
                    $this->organ('integrated'),
                    $this->organ('standalone'),
                    $this->organ('orphan'),
                ],
                'standalone_justifications' => ['standalone' => 'utility only'],
            ],
            $this->fullCircuitFacts('integrated'),
        ));

        $this->assertSame(3, $r['total_organs']);
        $this->assertContains('integrated', $r['integrated_ids']);
        $this->assertContains('standalone', $r['intentionally_standalone_ids']);
        $this->assertContains('orphan', $r['orphaned_ids']);
        $this->assertTrue($r['has_orphans']);
    }

    public function test_no_orphans_when_all_organs_fully_wired(): void
    {
        $r = $this->svc()->verify(array_merge_recursive(
            ['organ_inventory' => [$this->organ('X'), $this->organ('Y')]],
            $this->fullCircuitFacts('X'),
            $this->fullCircuitFacts('Y'),
        ));

        $this->assertFalse($r['has_orphans']);
        $this->assertSame([], $r['orphaned_ids']);
    }

    // ── edge cases ────────────────────────────────────────────────────────────

    public function test_empty_inventory_returns_no_results(): void
    {
        $r = $this->svc()->verify([]);

        $this->assertSame(0, $r['total_organs']);
        $this->assertSame([], $r['results']);
        $this->assertFalse($r['has_orphans']);
        $this->assertSame(AtlasExternalBrainOrganIntegrationVerifier::SCHEMA, $r['schema_version']);
    }

    public function test_standalone_reason_null_when_not_standalone(): void
    {
        $r = $this->svc()->verify(array_merge(
            ['organ_inventory' => [$this->organ('Z')]],
            $this->fullCircuitFacts('Z'),
        ));

        $this->assertNull($r['results'][0]['standalone_reason']);
    }

    // ── AC3: proof path + knowledge sync required (opt-in, gated) ─────────────

    public function test_full_circuit_without_proof_and_knowledge_sync_stays_integrated_when_not_required(): void
    {
        $r = $this->svc()->verify(array_merge(
            ['organ_inventory' => [$this->organ('J')]],
            $this->fullCircuitFacts('J'),
        ));

        $this->assertSame(AtlasExternalBrainOrganIntegrationVerifier::STATUS_INTEGRATED, $r['results'][0]['status']);
    }

    public function test_missing_proof_path_blocks_integration_when_required(): void
    {
        $facts = $this->fullCircuitFacts('K');
        $facts['require_proof_and_knowledge_sync'] = true;
        $facts['knowledge_sync'] = ['K' => true];

        $r = $this->svc()->verify(array_merge(['organ_inventory' => [$this->organ('K')]], $facts));

        $this->assertNotSame(AtlasExternalBrainOrganIntegrationVerifier::STATUS_INTEGRATED, $r['results'][0]['status']);
        $this->assertContains('no_proof_path', $r['results'][0]['circuit_gaps']);
        $this->assertFalse($r['results'][0]['has_proof_path']);
    }

    public function test_missing_knowledge_sync_blocks_integration_when_required(): void
    {
        $facts = $this->fullCircuitFacts('L');
        $facts['require_proof_and_knowledge_sync'] = true;
        $facts['proof_paths'] = ['L' => true];

        $r = $this->svc()->verify(array_merge(['organ_inventory' => [$this->organ('L')]], $facts));

        $this->assertNotSame(AtlasExternalBrainOrganIntegrationVerifier::STATUS_INTEGRATED, $r['results'][0]['status']);
        $this->assertContains('no_knowledge_sync', $r['results'][0]['circuit_gaps']);
        $this->assertFalse($r['results'][0]['has_knowledge_sync']);
    }

    public function test_proof_path_and_knowledge_sync_present_yields_integrated_when_required(): void
    {
        $facts = $this->fullCircuitFacts('M');
        $facts['require_proof_and_knowledge_sync'] = true;
        $facts['proof_paths'] = ['M' => true];
        $facts['knowledge_sync'] = ['M' => true];

        $r = $this->svc()->verify(array_merge(['organ_inventory' => [$this->organ('M')]], $facts));

        $this->assertSame(AtlasExternalBrainOrganIntegrationVerifier::STATUS_INTEGRATED, $r['results'][0]['status']);
        $this->assertSame([], $r['results'][0]['circuit_gaps']);
    }

    // ── AC2: partially_integrated / proxy_only / stale statuses ────────────────

    public function test_organ_with_some_legs_wired_is_partially_integrated(): void
    {
        $r = $this->svc()->verify([
            'organ_inventory' => [$this->organ('N')],
            'input_sources' => ['N' => ['real_source']],
            'decision_roles' => ['N' => 'gates_something'],
        ]);

        $this->assertSame(AtlasExternalBrainOrganIntegrationVerifier::STATUS_PARTIALLY_INTEGRATED, $r['results'][0]['status']);
        $this->assertContains('N', $r['partially_integrated_ids']);
    }

    public function test_organ_with_output_only_and_no_input_or_decision_is_proxy_only(): void
    {
        $r = $this->svc()->verify([
            'organ_inventory' => [$this->organ('O')],
            'flow_usage' => ['O' => ['some_consumer']],
        ]);

        $this->assertSame(AtlasExternalBrainOrganIntegrationVerifier::STATUS_PROXY_ONLY, $r['results'][0]['status']);
        $this->assertContains('O', $r['proxy_only_ids']);
    }

    public function test_organ_with_zero_legs_is_orphaned_not_proxy_only(): void
    {
        $r = $this->svc()->verify([
            'organ_inventory' => [$this->organ('P')],
        ]);

        $this->assertSame(AtlasExternalBrainOrganIntegrationVerifier::STATUS_ORPHANED, $r['results'][0]['status']);
    }

    public function test_stale_evidence_flag_yields_stale_status(): void
    {
        $facts = $this->fullCircuitFacts('Q');
        $facts['stale_evidence'] = ['Q' => true];

        $r = $this->svc()->verify(array_merge(['organ_inventory' => [$this->organ('Q')]], $facts));

        $this->assertSame(AtlasExternalBrainOrganIntegrationVerifier::STATUS_STALE, $r['results'][0]['status']);
        $this->assertContains('Q', $r['stale_ids']);
    }

    public function test_stale_flag_does_not_override_a_truly_integrated_organ_without_require_flag(): void
    {
        // stale_evidence is checked AFTER the fullyIntegrated short-circuit, so a genuinely
        // complete circuit (with no proof/knowledge-sync requirement engaged) is unaffected
        // unless the organ actually fails fullyIntegrated.
        $facts = $this->fullCircuitFacts('R');

        $r = $this->svc()->verify(array_merge(['organ_inventory' => [$this->organ('R')]], $facts));

        $this->assertSame(AtlasExternalBrainOrganIntegrationVerifier::STATUS_INTEGRATED, $r['results'][0]['status']);
    }

    // ── AC4: retirement recommendation for orphan/proxy_only organs with nothing built ──

    public function test_orphan_with_no_tests_and_no_impl_is_a_retirement_candidate(): void
    {
        $r = $this->svc()->verify([
            'organ_inventory' => [$this->organ('S', false, false)],
        ]);

        $this->assertTrue($r['results'][0]['retirement_candidate']);
        $this->assertNotEmpty($r['results'][0]['retirement_reason']);
        $this->assertStringContainsString('retire', strtolower($r['results'][0]['remediation'][0]));
    }

    public function test_orphan_with_tests_and_impl_is_not_a_retirement_candidate(): void
    {
        $r = $this->svc()->verify([
            'organ_inventory' => [$this->organ('T', true, true)],
        ]);

        $this->assertFalse($r['results'][0]['retirement_candidate']);
        $this->assertNull($r['results'][0]['retirement_reason']);
    }

    public function test_proxy_only_with_no_tests_and_no_impl_is_a_retirement_candidate(): void
    {
        $r = $this->svc()->verify([
            'organ_inventory' => [$this->organ('U', false, false)],
            'flow_usage' => ['U' => ['some_consumer']],
        ]);

        $this->assertSame(AtlasExternalBrainOrganIntegrationVerifier::STATUS_PROXY_ONLY, $r['results'][0]['status']);
        $this->assertTrue($r['results'][0]['retirement_candidate']);
    }

    public function test_integrated_organ_is_never_a_retirement_candidate(): void
    {
        $r = $this->svc()->verify(array_merge(
            ['organ_inventory' => [$this->organ('V', false, false)]],
            $this->fullCircuitFacts('V'),
        ));

        $this->assertFalse($r['results'][0]['retirement_candidate']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // AC4: orphan_reason / required_wiring / evidence_refs
    // ═══════════════════════════════════════════════════════════════════════

    public function test_orphan_reason_null_for_fully_integrated_organ(): void
    {
        $r = $this->svc()->verify(array_merge(
            ['organ_inventory' => [$this->organ('W')]],
            $this->fullCircuitFacts('W'),
        ));

        $this->assertNull($r['results'][0]['orphan_reason']);
    }

    public function test_orphan_reason_mentions_all_circuit_gaps_for_completely_unwired_organ(): void
    {
        $r = $this->svc()->verify([
            'organ_inventory' => [$this->organ('X', false, false)],
        ]);

        $reason = $r['results'][0]['orphan_reason'];
        $this->assertStringContainsString('no_input_source', $reason);
        $this->assertStringContainsString('no_decision_role', $reason);
        $this->assertStringContainsString('no_output_consumer', $reason);
        $this->assertStringContainsString('no_learning_feedback', $reason);
        $this->assertStringContainsString('no_failure_handling', $reason);
    }

    public function test_orphan_reason_present_for_standalone_organ(): void
    {
        $r = $this->svc()->verify([
            'organ_inventory' => [$this->organ('Y', false, false)],
            'standalone_justifications' => ['Y' => 'utility only'],
        ]);

        $this->assertSame(AtlasExternalBrainOrganIntegrationVerifier::STATUS_INTENTIONALLY_STANDALONE, $r['results'][0]['status']);
        $this->assertIsString($r['results'][0]['orphan_reason']);
        $this->assertStringContainsString('Y', $r['results'][0]['orphan_reason']);
    }

    public function test_orphan_reason_present_for_partially_integrated_organ(): void
    {
        $r = $this->svc()->verify([
            'organ_inventory' => [$this->organ('Z')],
            'input_sources' => ['Z' => ['source_a']],
            'decision_roles' => ['Z' => 'decides_x'],
        ]);

        $this->assertSame(AtlasExternalBrainOrganIntegrationVerifier::STATUS_PARTIALLY_INTEGRATED, $r['results'][0]['status']);
        $this->assertIsString($r['results'][0]['orphan_reason']);
        $this->assertStringContainsString('no_output_consumer', $r['results'][0]['orphan_reason']);
    }

    public function test_required_wiring_contains_expected_paths(): void
    {
        $r = $this->svc()->verify([
            'organ_inventory' => [$this->organ('W')],
        ]);

        $wiring = $r['results'][0]['required_wiring'];
        $this->assertContains('input_source', $wiring);
        $this->assertContains('decision_role', $wiring);
        $this->assertContains('output_consumer', $wiring);
        $this->assertContains('learning_feedback', $wiring);
        $this->assertContains('failure_handling', $wiring);
        $this->assertCount(5, $wiring);
    }

    public function test_required_wiring_includes_proof_and_knowledge_sync_when_required(): void
    {
        $r = $this->svc()->verify([
            'organ_inventory' => [$this->organ('W')],
            'require_proof_and_knowledge_sync' => true,
        ]);

        $wiring = $r['results'][0]['required_wiring'];
        $this->assertContains('proof_path', $wiring);
        $this->assertContains('knowledge_sync', $wiring);
        $this->assertCount(7, $wiring);
    }

    public function test_evidence_refs_includes_all_legs_for_full_circuit(): void
    {
        $r = $this->svc()->verify(array_merge(
            ['organ_inventory' => [$this->organ('W')]],
            $this->fullCircuitFacts('W'),
        ));

        $refs = $r['results'][0]['evidence_refs'];
        $this->assertContains('input_source:real_upstream_signal', $refs);
        $this->assertContains('decision_role:gates_dispatch', $refs);
        $this->assertContains('flow_usage:decision_loop', $refs);
        $this->assertContains('learning_feedback:present', $refs);
        $this->assertContains('failure_handling:present', $refs);
    }

    public function test_evidence_refs_includes_control_plane_exposure(): void
    {
        $facts = $this->fullCircuitFacts('W');
        unset($facts['flow_usage']);
        $facts['control_plane_exposure'] = ['W' => true];

        $r = $this->svc()->verify(array_merge(
            ['organ_inventory' => [$this->organ('W')]],
            $facts,
        ));

        $refs = $r['results'][0]['evidence_refs'];
        $this->assertContains('control_plane_exposure:true', $refs);
        $this->assertNotContains('flow_usage:decision_loop', $refs,
            'flow_usage evidence should not appear when only control_plane_exposure is set');
    }

    public function test_evidence_refs_includes_proof_and_knowledge_sync_when_present(): void
    {
        $facts = $this->fullCircuitFacts('W');
        $facts['require_proof_and_knowledge_sync'] = true;
        $facts['proof_paths'] = ['W' => 'evidence/audit-1'];
        $facts['knowledge_sync'] = ['W' => 'yes'];

        $r = $this->svc()->verify(array_merge(
            ['organ_inventory' => [$this->organ('W')]],
            $facts,
        ));

        $refs = $r['results'][0]['evidence_refs'];
        $this->assertContains('proof_path:evidence/audit-1', $refs);
        $this->assertContains('knowledge_sync:yes', $refs);
    }

    public function test_evidence_refs_empty_when_no_wiring_and_require_proof_sync(): void
    {
        // Even with require_proof_and_knowledge_sync=true, if no wiring exists, evidence_refs is empty.
        $r = $this->svc()->verify([
            'organ_inventory' => [$this->organ('X', false, false)],
            'require_proof_and_knowledge_sync' => true,
        ]);

        $this->assertSame([], $r['results'][0]['evidence_refs']);
    }

    public function test_evidence_refs_partial_for_partially_wired_organ(): void
    {
        $r = $this->svc()->verify([
            'organ_inventory' => [$this->organ('Z')],
            'input_sources' => ['Z' => ['source_a']],
            'decision_roles' => ['Z' => 'decides_x'],
        ]);

        $refs = $r['results'][0]['evidence_refs'];
        $this->assertContains('input_source:source_a', $refs);
        $this->assertContains('decision_role:decides_x', $refs);
        $this->assertCount(2, $refs);
    }

    public function test_orphan_reason_proxy_only_organ(): void
    {
        $r = $this->svc()->verify([
            'organ_inventory' => [$this->organ('U', false, false)],
            'flow_usage' => ['U' => ['some_consumer']],
        ]);

        $this->assertSame(AtlasExternalBrainOrganIntegrationVerifier::STATUS_PROXY_ONLY, $r['results'][0]['status']);
        $this->assertIsString($r['results'][0]['orphan_reason']);
        $this->assertStringContainsString('not wired', $r['results'][0]['orphan_reason']);
        // proxy_only has output_consumer but no input_source or decision_role
        $this->assertStringContainsString('no_input_source', $r['results'][0]['orphan_reason']);
        $this->assertStringContainsString('no_decision_role', $r['results'][0]['orphan_reason']);
        $this->assertStringNotContainsString('no_output_consumer', $r['results'][0]['orphan_reason']);
    }

    public function test_evidence_refs_present_in_batch_top_level_contract(): void
    {
        $r = $this->svc()->verify(array_merge(
            ['organ_inventory' => [$this->organ('A')]],
            $this->fullCircuitFacts('A'),
        ));

        // All results in the batch have the new fields.
        foreach ($r['results'] as $result) {
            $this->assertArrayHasKey('orphan_reason', $result);
            $this->assertArrayHasKey('required_wiring', $result);
            $this->assertArrayHasKey('evidence_refs', $result);
        }
    }
}
