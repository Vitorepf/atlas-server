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
}
