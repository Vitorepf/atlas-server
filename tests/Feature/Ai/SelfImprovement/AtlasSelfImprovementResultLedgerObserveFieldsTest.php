<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SelfImprovement;

use App\Services\Ai\SelfImprovement\AtlasSelfImprovementResultLedgerService;
use App\Services\Ai\SelfImprovement\RegressionRecurrenceDetector;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Obra #7 W2 — WIRE-OBSERVE fields on the Self-Improvement Result Ledger.
 *
 * Exercises the REAL path (record() -> registry -> snapshot()) and asserts
 * the four observe-only fields carry coherent values:
 *   - entry.learning_packet_quality      (LearningPacketQualityScorer)
 *   - snapshot.grade_trajectory          (SelfImprovementGradeTrajectoryClassifier)
 *   - snapshot.regression_recurrence     (RegressionRecurrenceDetector)
 *   - snapshot.learning_packet_conflicts (LearningPacketConflictDetector)
 *
 * Observe contract: fields NEVER change grade/blockers/status.
 */
class AtlasSelfImprovementResultLedgerObserveFieldsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_record_emits_learning_packet_quality_coherent_with_grade(): void
    {
        $ledger = app(AtlasSelfImprovementResultLedgerService::class);

        // Improvement with strong evidence -> high score, non-discard band.
        $good = $ledger->record($this->payload(
            before: 7.0,
            after: 9.0,
            evidenceRefs: ['doc:fixture@h1', 'doc:fixture2@h2', 'doc:fixture3@h3', 'doc:fixture4@h4'],
            whatChanged: 'observe wiring rule',
            newRuleCandidate: 'always score learning packets',
        ));

        $this->assertContains($good['delta_grade'], [
            AtlasSelfImprovementResultLedgerService::GRADE_IMPROVED,
            AtlasSelfImprovementResultLedgerService::GRADE_MAJOR_IMPROVEMENT,
        ]);
        $quality = $good['learning_packet_quality'];
        $this->assertIsArray($quality);
        // Property: confidence >= 0.7 + 4-item evidence bonus (+0.20) -> >= 0.75.
        $this->assertGreaterThanOrEqual(0.75, $quality['score']);
        $this->assertLessThanOrEqual(1.0, $quality['score']);
        $this->assertContains($quality['band'], ['publishable', 'provisional']);
        $this->assertContains('evidence_bonus_applied', $quality['reasons']);

        // Regression -> confidence 0.2 + rollback penalty -> discard band.
        $bad = $ledger->record($this->payload(
            before: 9.0,
            after: 4.0,
            evidenceRefs: ['doc:fixture@h1', 'doc:fixture2@h2'],
            whatChanged: 'observe wiring rule',
        ));

        $this->assertSame(AtlasSelfImprovementResultLedgerService::GRADE_REGRESSED, $bad['delta_grade']);
        $badQuality = $bad['learning_packet_quality'];
        $this->assertIsArray($badQuality);
        $this->assertSame('discard', $badQuality['band']);
        $this->assertLessThan(0.45, $badQuality['score']);
        $this->assertContains('rollback_recommendation_penalty', $badQuality['reasons']);

        // Observe contract: quality never mutates the packet it scored.
        $this->assertArrayNotHasKey('learning_packet_quality', $good['learning_packet']);
    }

    public function test_snapshot_emits_trajectory_recurrence_and_conflicts_from_recorded_cycles(): void
    {
        $ledger = app(AtlasSelfImprovementResultLedgerService::class);

        // Cycle 1 (chronological index 0): improvement, positive packet.
        $ledger->record($this->payload(
            before: 7.0,
            after: 9.0,
            evidenceRefs: ['doc:fixture@h1', 'doc:fixture2@h2', 'doc:fixture3@h3', 'doc:fixture4@h4'],
            whatChanged: 'observe wiring rule',
        ));

        // Cycles 2-4: chronic regressions; cycle 2 shares the change key of
        // cycle 1 -> positive-vs-negative conflict on 'observe wiring rule'.
        $ledger->record($this->payload(9.0, 4.0, ['doc:a@1', 'doc:b@2'], 'observe wiring rule'));
        $ledger->record($this->payload(9.0, 4.0, ['doc:a@1', 'doc:b@2'], 'other repair attempt one'));
        $ledger->record($this->payload(9.0, 4.0, ['doc:a@1', 'doc:b@2'], 'other repair attempt two'));

        $snapshot = $ledger->snapshot();

        $this->assertSame(AtlasSelfImprovementResultLedgerService::SCHEMA_VERSION, $snapshot['schema_version']);
        $this->assertSame(4, $snapshot['counters']['total']);

        // Trajectory: one positive then three regressions -> net negative sum.
        $trajectory = $snapshot['grade_trajectory'];
        $this->assertIsArray($trajectory);
        $this->assertSame('regressing', $trajectory['trajectory']);
        $this->assertSame(4, $trajectory['scored_count']);
        $this->assertSame(3, $trajectory['longest_regression_streak']);
        $this->assertLessThan(0.0, $trajectory['running_sum']);

        // Recurrence: 3 cycles regressing every hard metric -> chronic.
        $recurrence = $snapshot['regression_recurrence'];
        $this->assertIsArray($recurrence);
        $this->assertTrue($recurrence['chronic']);
        $this->assertGreaterThanOrEqual(3, $recurrence['top_recurrence']);
        $this->assertContains($recurrence['top_metric'], RegressionRecurrenceDetector::HARD_REGRESSION_METRICS);
        $this->assertSame(4, $recurrence['scanned_cycles']);

        // Conflicts: positive packet (cycle 1) vs negative packet (cycle 2)
        // on the same change key; most recent (negative) wins.
        $conflicts = $snapshot['learning_packet_conflicts'];
        $this->assertIsArray($conflicts);
        $this->assertTrue($conflicts['has_conflict']);
        $this->assertCount(1, $conflicts['conflicts']);
        $conflict = $conflicts['conflicts'][0];
        $this->assertSame('observe wiring rule', $conflict['change_key']);
        $this->assertSame('most_recent_wins', $conflict['verdict']);
        $this->assertSame(0, $conflict['positive_index']);
        $this->assertSame(1, $conflict['negative_index']);
        $this->assertSame(1, $conflict['stale_winner_index']);
    }

    /**
     * @param  list<string>  $evidenceRefs
     * @return array<string,mixed>
     */
    private function payload(
        float $before,
        float $after,
        array $evidenceRefs,
        string $whatChanged,
        ?string $newRuleCandidate = null,
    ): array {
        $context = [
            'evidence_refs' => $evidenceRefs,
            'what_changed' => $whatChanged,
            'why_it_mattered' => 'observe-wire integration fixture',
            // Invariant Lock requires the proposal packet to reference
            // canonical docs; without it every grade collapses to regressed.
            'proposal_packet' => [
                'canonical_docs' => [
                    'docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md',
                ],
            ],
        ];
        if ($newRuleCandidate !== null) {
            $context['new_rule_candidate'] = $newRuleCandidate;
        }

        return [
            'proposal_id' => 'prop_observe_'.uniqid(),
            'obra_id' => 'obra_observe_'.uniqid(),
            'before_snapshot' => $this->canonicalSnapshot($before),
            'after_snapshot' => $this->canonicalSnapshot($after),
            'reviewer' => 'observe-wire-test',
            'reason' => 'obra7 w2 observe wiring',
            'context' => $context,
        ];
    }

    /**
     * Mirrors the canonical snapshot shape used by the Level 7 closed-loop
     * suite: `metrics` for the Delta Scorecard plus the `completion_audit` /
     * `docs_health` blocks expected by Invariant Lock and Regression Sentinel.
     *
     * @return array<string,mixed>
     */
    private function canonicalSnapshot(float $score): array
    {
        $metrics = [
            'functional_correctness',
            'business_rule_alignment',
            'canonical_documentation_adherence',
            'test_and_risk_coverage',
            'enterprise_architecture_quality',
            'governance_integrity',
            'operator_experience',
            'automation_level',
            'human_intervention_load',
            'evidence_and_observability',
            'runtime_safety',
            'provider_cost_token_impact',
            'regressions_and_new_blockers',
        ];

        return [
            'metrics' => array_fill_keys($metrics, $score),
            'completion_audit' => [
                'atlas_forge_continuum_certification' => [
                    'invariants' => [
                        'no_silent_obra_creation' => true,
                    ],
                    'no_silent_fallback' => true,
                    'separated_from_external_rivals' => true,
                    'external_provider_call' => false,
                ],
                'atlas_forge_provider_capacity_certification' => [
                    'invariants' => [
                        'static_policy_does_not_dispatch' => true,
                    ],
                ],
                'atlas_code_forge_review_completion_certification' => [
                    'lifecycle_invariants' => [
                        'no_auto_completion_without_human_review' => true,
                    ],
                ],
                'rules' => [
                    'synthetic_scores_allowed' => false,
                ],
            ],
            'docs_health' => [
                'oversized_count' => 0,
                'violations_count' => 0,
            ],
        ];
    }
}
