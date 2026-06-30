<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainRedundancyCollapseAdvisor;
use Tests\TestCase;

final class AtlasExternalBrainRedundancyCollapseAdvisorTest extends TestCase
{
    private function svc(): AtlasExternalBrainRedundancyCollapseAdvisor
    {
        return new AtlasExternalBrainRedundancyCollapseAdvisor;
    }

    private function organ(string $id, array $decisions, float $evidence = 5.0, array $consumers = [], array $behaviors = []): array
    {
        return [
            'organ_id' => $id,
            'decisions' => $decisions,
            'consumers' => $consumers,
            'behaviors' => $behaviors,
            'evidence_strength' => $evidence,
        ];
    }

    // ── successful collapse candidates ────────────────────────────────────────

    public function test_overlapping_decisions_with_stronger_evidence_produces_candidate(): void
    {
        $maps = [
            $this->organ('A', ['decide_routing', 'decide_dispatch'], 8.0, [], ['route_request']),
            $this->organ('B', ['decide_routing', 'decide_dispatch'], 4.0, [], ['route_request']),
        ];

        $r = $this->svc()->advise($maps);

        $this->assertSame(1, $r['candidate_count']);
        $this->assertSame([], $r['refused_collapses']);
        $this->assertSame(AtlasExternalBrainRedundancyCollapseAdvisor::SCHEMA, $r['schema_version']);
    }

    public function test_canonical_owner_is_the_organ_with_higher_evidence_strength(): void
    {
        $maps = [
            $this->organ('weak', ['decide_routing', 'decide_dispatch'], 3.0, [], ['route_request']),
            $this->organ('strong', ['decide_routing', 'decide_dispatch'], 9.0, [], ['route_request']),
        ];

        $r = $this->svc()->advise($maps);

        $candidate = $r['collapse_candidates'][0];
        $this->assertSame('strong', $candidate['canonical_owner']);
        $this->assertSame(['weak'], $candidate['absorbed_organs']);
    }

    public function test_preserved_behaviors_is_union_of_both_organs(): void
    {
        $maps = [
            $this->organ('A', ['decide_routing', 'decide_dispatch'], 8.0, [], ['route_request', 'log_event']),
            $this->organ('B', ['decide_routing', 'decide_dispatch'], 4.0, [], ['cache_response']),
        ];

        $r = $this->svc()->advise($maps);

        $preserved = $r['collapse_candidates'][0]['preserved_behaviors'];
        sort($preserved); // normalize for assertion
        $this->assertContains('route_request', $preserved);
        $this->assertContains('log_event', $preserved);
        $this->assertContains('cache_response', $preserved);
    }

    public function test_deleted_responsibilities_are_behaviors_unique_to_absorbed_organ(): void
    {
        $maps = [
            $this->organ('A', ['decide_routing', 'decide_dispatch'], 8.0, [], ['route_request']),
            $this->organ('B', ['decide_routing', 'decide_dispatch'], 4.0, [], ['route_request', 'legacy_passthrough']),
        ];

        $r = $this->svc()->advise($maps);

        $this->assertContains('legacy_passthrough', $r['collapse_candidates'][0]['deleted_responsibilities']);
        $this->assertNotContains('route_request', $r['collapse_candidates'][0]['deleted_responsibilities']);
    }

    public function test_required_tests_generated_from_preserved_behaviors(): void
    {
        $maps = [
            $this->organ('A', ['decide_routing', 'decide_dispatch'], 8.0, [], ['route request']),
            $this->organ('B', ['decide_routing', 'decide_dispatch'], 4.0, [], []),
        ];

        $r = $this->svc()->advise($maps);

        $this->assertContains('test_route_request_preserved', $r['collapse_candidates'][0]['required_tests']);
    }

    public function test_migration_notes_reference_absorbed_and_canonical_organs(): void
    {
        $maps = [
            $this->organ('canonical', ['decide_routing', 'decide_dispatch'], 8.0, [], ['route_request']),
            $this->organ('absorbed', ['decide_routing', 'decide_dispatch'], 3.0, [], ['route_request']),
        ];

        $r = $this->svc()->advise($maps);

        $notes = $r['collapse_candidates'][0]['migration_notes'];
        $this->assertStringContainsString('absorbed', $notes);
        $this->assertStringContainsString('canonical', $notes);
    }

    // ── refused collapses ─────────────────────────────────────────────────────

    public function test_single_short_shared_decision_is_refused_as_lexical_only(): void
    {
        $maps = [
            $this->organ('A', ['decide', 'other_decision_A'], 8.0),
            $this->organ('B', ['decide', 'other_decision_B'], 3.0),
        ];

        $r = $this->svc()->advise($maps);

        $this->assertSame(0, $r['candidate_count']);
        $this->assertSame('lexical_overlap_only', $r['refused_collapses'][0]['reason']);
    }

    public function test_no_shared_decisions_is_refused_as_insufficient_overlap(): void
    {
        $maps = [
            $this->organ('A', ['decide_routing'], 8.0),
            $this->organ('B', ['decide_dispatch'], 3.0),
        ];

        $r = $this->svc()->advise($maps);

        $this->assertSame(0, $r['candidate_count']);
        $this->assertSame('insufficient_overlap', $r['refused_collapses'][0]['reason']);
    }

    public function test_disjoint_consumers_is_refused(): void
    {
        $maps = [
            $this->organ('A', ['decide_routing', 'decide_dispatch'], 8.0, ['consumer_X']),
            $this->organ('B', ['decide_routing', 'decide_dispatch'], 3.0, ['consumer_Y']),
        ];

        $r = $this->svc()->advise($maps);

        $this->assertSame(0, $r['candidate_count']);
        $this->assertSame('consumers_differ_materially', $r['refused_collapses'][0]['reason']);
    }

    public function test_equal_evidence_strength_is_refused(): void
    {
        $maps = [
            $this->organ('A', ['decide_routing', 'decide_dispatch'], 5.0),
            $this->organ('B', ['decide_routing', 'decide_dispatch'], 5.0),
        ];

        $r = $this->svc()->advise($maps);

        $this->assertSame(0, $r['candidate_count']);
        $this->assertSame('no_canonical_owner_stronger_evidence', $r['refused_collapses'][0]['reason']);
    }

    // ── risk level ────────────────────────────────────────────────────────────

    public function test_risk_high_when_many_exclusive_consumers_in_absorbed_organ(): void
    {
        $maps = [
            $this->organ('A', ['decide_routing', 'decide_dispatch'], 9.0, ['shared_consumer'], ['route_request']),
            $this->organ('B', ['decide_routing', 'decide_dispatch'], 3.0, ['shared_consumer', 'c1', 'c2', 'c3'], ['route_request']),
        ];

        $r = $this->svc()->advise($maps);

        $this->assertSame('high', $r['collapse_candidates'][0]['risk_level']);
    }

    public function test_risk_low_when_no_exclusive_consumers_and_few_deletions(): void
    {
        $maps = [
            $this->organ('A', ['decide_routing', 'decide_dispatch'], 9.0, [], ['shared_behavior']),
            $this->organ('B', ['decide_routing', 'decide_dispatch'], 3.0, [], ['shared_behavior']),
        ];

        $r = $this->svc()->advise($maps);

        $this->assertSame('low', $r['collapse_candidates'][0]['risk_level']);
    }

    // ── edge cases ────────────────────────────────────────────────────────────

    public function test_empty_input_returns_empty_result(): void
    {
        $r = $this->svc()->advise([]);

        $this->assertSame(0, $r['candidate_count']);
        $this->assertSame([], $r['collapse_candidates']);
        $this->assertSame([], $r['refused_collapses']);
    }

    public function test_single_organ_produces_no_pairs(): void
    {
        $r = $this->svc()->advise([$this->organ('A', ['decide_routing', 'decide_dispatch'], 8.0)]);

        $this->assertSame(0, $r['candidate_count']);
        $this->assertSame([], $r['refused_collapses']);
    }

    // ── AC1: new canonical fields on candidate ────────────────────────────────

    private function safeCollapseResult(): array
    {
        return $this->svc()->advise([
            $this->organ('A', ['decide_routing', 'decide_dispatch'], 9.0, [], ['route_request']),
            $this->organ('B', ['decide_routing', 'decide_dispatch'], 3.0, [], ['cache_response']),
        ]);
    }

    public function test_candidate_has_collapse_confidence(): void
    {
        $r = $this->safeCollapseResult();

        $c = $r['collapse_candidates'][0];
        $this->assertArrayHasKey('collapse_confidence', $c);
        $this->assertGreaterThan(0.0, $c['collapse_confidence']);
        $this->assertLessThanOrEqual(1.0, $c['collapse_confidence']);
    }

    public function test_candidate_has_canonical_owner_reason(): void
    {
        $r = $this->safeCollapseResult();

        $this->assertArrayHasKey('canonical_owner_reason', $r['collapse_candidates'][0]);
        $this->assertSame('higher_evidence_strength', $r['collapse_candidates'][0]['canonical_owner_reason']);
    }

    public function test_candidate_has_required_behavior_tests(): void
    {
        $r = $this->safeCollapseResult();

        $c = $r['collapse_candidates'][0];
        $this->assertArrayHasKey('required_behavior_tests', $c);
        $this->assertNotEmpty($c['required_behavior_tests']);
    }

    public function test_candidate_has_deletion_blockers(): void
    {
        $r = $this->safeCollapseResult();

        $this->assertArrayHasKey('deletion_blockers', $r['collapse_candidates'][0]);
        $this->assertIsArray($r['collapse_candidates'][0]['deletion_blockers']);
    }

    public function test_deletion_blockers_includes_behavior_gap_when_absorbed_has_unique_behaviors(): void
    {
        $r = $this->svc()->advise([
            $this->organ('A', ['decide_routing', 'decide_dispatch'], 9.0, [], ['route_request']),
            $this->organ('B', ['decide_routing', 'decide_dispatch'], 3.0, [], ['route_request', 'legacy_passthrough']),
        ]);

        $this->assertContains('behavior_gap_in_owner', $r['collapse_candidates'][0]['deletion_blockers']);
    }

    public function test_deletion_blockers_includes_exclusive_consumer_migration_when_absorbed_has_unique_consumers(): void
    {
        $r = $this->svc()->advise([
            $this->organ('A', ['decide_routing', 'decide_dispatch'], 9.0, ['shared'], ['route_request']),
            $this->organ('B', ['decide_routing', 'decide_dispatch'], 3.0, ['shared', 'exclusive_b'], ['route_request']),
        ]);

        $this->assertContains('exclusive_consumer_migration_required', $r['collapse_candidates'][0]['deletion_blockers']);
    }

    // ── AC2: refuse without behavior-preservation tests ───────────────────────

    public function test_collapse_refused_when_no_behaviors_defined(): void
    {
        $r = $this->svc()->advise([
            $this->organ('A', ['decide_routing', 'decide_dispatch'], 9.0),
            $this->organ('B', ['decide_routing', 'decide_dispatch'], 3.0),
        ]);

        $this->assertSame(0, $r['candidate_count']);
        $this->assertSame('no_behavior_preservation_tests', $r['refused_collapses'][0]['reason']);
    }
}
