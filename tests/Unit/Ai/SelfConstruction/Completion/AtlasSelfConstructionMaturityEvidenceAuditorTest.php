<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Completion;

use App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionMaturityEvidenceAuditor;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionMaturityEvidenceAuditorTest extends TestCase
{
    private function auditor(): AtlasSelfConstructionMaturityEvidenceAuditor
    {
        return new AtlasSelfConstructionMaturityEvidenceAuditor;
    }

    private function claim(array $overrides = []): array
    {
        return array_merge([
            'dimension'     => 'test_dim',
            'claimed_score' => 0.90,
            'evidence_refs' => ['runtime:telemetry'],
            'critical'      => false,
        ], $overrides);
    }

    // ── AC1: output shape ─────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->auditor()->audit([]);
        $this->assertSame(AtlasSelfConstructionMaturityEvidenceAuditor::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('overall_claimed_maturity', $r);
        $this->assertArrayHasKey('overall_audited_maturity', $r);
        $this->assertArrayHasKey('dimension_verdicts', $r);
        $this->assertArrayHasKey('high_score_refused', $r);
        $this->assertArrayHasKey('refusal_reasons', $r);
    }

    // ── Verdict classification ────────────────────────────────────────────────

    public function test_runtime_evidence_yields_proven_verdict(): void
    {
        $r = $this->auditor()->audit([
            'overall_claimed_maturity' => 0.90,
            'maturity_claims' => [$this->claim(['evidence_refs' => ['runtime:live_telemetry']])],
        ]);
        $this->assertSame('proven', $r['dimension_verdicts'][0]['verdict']);
        $this->assertSame(0.90, $r['dimension_verdicts'][0]['audited_score']);
    }

    public function test_live_proof_evidence_yields_proven(): void
    {
        $r = $this->auditor()->audit([
            'maturity_claims' => [$this->claim(['evidence_refs' => ['live_proof:24h_soak']])],
        ]);
        $this->assertSame('proven', $r['dimension_verdicts'][0]['verdict']);
    }

    public function test_integration_evidence_only_yields_weak(): void
    {
        $r = $this->auditor()->audit([
            'maturity_claims' => [$this->claim([
                'claimed_score' => 1.0,
                'evidence_refs' => ['integration:e2e_suite'],
            ])],
        ]);
        $this->assertSame('weak', $r['dimension_verdicts'][0]['verdict']);
        $this->assertEqualsWithDelta(0.70, $r['dimension_verdicts'][0]['audited_score'], 0.001);
    }

    public function test_test_evidence_only_yields_weak_with_lower_multiplier(): void
    {
        $r = $this->auditor()->audit([
            'maturity_claims' => [$this->claim([
                'claimed_score' => 1.0,
                'evidence_refs' => ['phpunit:suite_green'],
            ])],
        ]);
        $this->assertSame('weak', $r['dimension_verdicts'][0]['verdict']);
        $this->assertEqualsWithDelta(0.50, $r['dimension_verdicts'][0]['audited_score'], 0.001);
    }

    public function test_intent_only_evidence_yields_intent_only_verdict(): void
    {
        $r = $this->auditor()->audit([
            'maturity_claims' => [$this->claim([
                'claimed_score' => 1.0,
                'evidence_refs' => ['intent:planned_next_sprint'],
            ])],
        ]);
        $this->assertSame('intent_only', $r['dimension_verdicts'][0]['verdict']);
        $this->assertEqualsWithDelta(0.15, $r['dimension_verdicts'][0]['audited_score'], 0.001);
    }

    public function test_no_evidence_yields_missing_verdict_with_zero_score(): void
    {
        $r = $this->auditor()->audit([
            'maturity_claims' => [$this->claim(['evidence_refs' => []])],
        ]);
        $this->assertSame('missing', $r['dimension_verdicts'][0]['verdict']);
        $this->assertSame(0.0, $r['dimension_verdicts'][0]['audited_score']);
    }

    public function test_contradicts_evidence_yields_contradicted_verdict(): void
    {
        $r = $this->auditor()->audit([
            'maturity_claims' => [$this->claim([
                'evidence_refs' => ['contradicts:test_results_show_failure'],
            ])],
        ]);
        $this->assertSame('contradicted', $r['dimension_verdicts'][0]['verdict']);
        $this->assertSame(0.0, $r['dimension_verdicts'][0]['audited_score']);
    }

    // ── AC2: high-score refusal ───────────────────────────────────────────────

    public function test_critical_intent_only_dimension_refuses_high_score(): void
    {
        $r = $this->auditor()->audit([
            'overall_claimed_maturity' => 0.95,
            'maturity_claims' => [$this->claim([
                'dimension'     => 'merge_autonomy',
                'evidence_refs' => ['intent:planned'],
                'critical'      => true,
            ])],
        ]);
        $this->assertTrue($r['high_score_refused']);
        $this->assertNotEmpty($r['refusal_reasons']);
        $this->assertStringContainsString('merge_autonomy', $r['refusal_reasons'][0]);
    }

    public function test_critical_missing_dimension_refuses_high_score(): void
    {
        $r = $this->auditor()->audit([
            'overall_claimed_maturity' => 0.95,
            'maturity_claims' => [$this->claim([
                'dimension'     => 'task_success_rate',
                'evidence_refs' => [],
                'critical'      => true,
            ])],
        ]);
        $this->assertTrue($r['high_score_refused']);
    }

    public function test_non_critical_intent_dimension_does_not_refuse_high_score(): void
    {
        $r = $this->auditor()->audit([
            'overall_claimed_maturity' => 0.95,
            'maturity_claims' => [$this->claim([
                'evidence_refs' => ['intent:future'],
                'critical'      => false,
            ])],
        ]);
        $this->assertFalse($r['high_score_refused']);
    }

    public function test_high_score_not_refused_when_below_threshold(): void
    {
        // claimed = 0.65 (≤ 0.70 threshold) → no refusal even with critical intent dim.
        $r = $this->auditor()->audit([
            'overall_claimed_maturity' => 0.65,
            'maturity_claims' => [$this->claim([
                'evidence_refs' => ['intent:plan'],
                'critical'      => true,
            ])],
        ]);
        $this->assertFalse($r['high_score_refused']);
    }

    // ── Overall audited maturity ──────────────────────────────────────────────

    public function test_overall_audited_maturity_is_average_of_dimension_scores(): void
    {
        $r = $this->auditor()->audit([
            'overall_claimed_maturity' => 0.90,
            'maturity_claims' => [
                $this->claim(['claimed_score' => 1.0, 'evidence_refs' => ['runtime:x']]), // proven: 1.0
                $this->claim(['claimed_score' => 1.0, 'evidence_refs' => []]),             // missing: 0.0
            ],
        ]);
        $this->assertEqualsWithDelta(0.50, $r['overall_audited_maturity'], 0.001);
    }

    public function test_empty_claims_yields_zero_audited_maturity(): void
    {
        $r = $this->auditor()->audit(['overall_claimed_maturity' => 0.95]);
        $this->assertSame(0.0, $r['overall_audited_maturity']);
        $this->assertFalse($r['high_score_refused']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $facts = [
            'overall_claimed_maturity' => 0.95,
            'maturity_claims' => [
                $this->claim(['dimension' => 'a', 'evidence_refs' => ['runtime:x'], 'critical' => true]),
                $this->claim(['dimension' => 'b', 'evidence_refs' => ['intent:plan'], 'critical' => true]),
            ],
        ];
        $a = $this->auditor()->audit($facts);
        $b = $this->auditor()->audit($facts);
        $this->assertSame(json_encode($a), json_encode($b));
    }

    // AC: proof_gap_index groups missing, weak, intent_only and contradicted separately
    public function test_proof_gap_index_groups_dimensions_by_category(): void
    {
        $result = $this->auditor()->audit([
            'overall_claimed_maturity' => 0.8,
            'maturity_claims' => [
                $this->claim(['dimension' => 'exec', 'evidence_refs' => []]),
                $this->claim(['dimension' => 'plan', 'evidence_refs' => ['intent:plan']]),
                $this->claim(['dimension' => 'integ', 'evidence_refs' => ['integration:deploy']]),
                $this->claim(['dimension' => 'refute', 'evidence_refs' => ['contradicts:runtime']]),
                $this->claim(['dimension' => 'proven', 'evidence_refs' => ['runtime:ok']]),
            ],
        ]);

        $tiers = array_column($result['proof_gap_index'], 'evidence_tier');
        $this->assertContains('missing', $tiers);
        $this->assertContains('intent_only', $tiers);
        $this->assertContains('weak', $tiers);
        $this->assertContains('contradicted', $tiers);
        // proven dimension should NOT be in gap index
        $dims = array_column($result['proof_gap_index'], 'dimension');
        $this->assertNotContains('proven', $dims);
    }

    // AC: each proof_gap_index row has dimension, severity, evidence_tier, next_atlas_action
    public function test_proof_gap_index_row_has_required_fields(): void
    {
        $result = $this->auditor()->audit([
            'overall_claimed_maturity' => 0.5,
            'maturity_claims' => [
                $this->claim(['dimension' => 'gap', 'evidence_refs' => ['intent:todo']]),
            ],
        ]);

        foreach ($result['proof_gap_index'] as $row) {
            $this->assertArrayHasKey('dimension', $row);
            $this->assertArrayHasKey('severity', $row);
            $this->assertArrayHasKey('evidence_tier', $row);
            $this->assertArrayHasKey('next_atlas_action', $row);
            $this->assertNotEmpty($row['next_atlas_action']);
        }
    }

    // AC: proven runtime dimensions do not appear in proof_gap_index
    public function test_proven_dimensions_not_in_proof_gap_index(): void
    {
        $result = $this->auditor()->audit([
            'overall_claimed_maturity' => 0.9,
            'maturity_claims' => [
                $this->claim(['dimension' => 'a', 'evidence_refs' => ['runtime:ok']]),
                $this->claim(['dimension' => 'b', 'evidence_refs' => ['live_proof:ok']]),
            ],
        ]);

        $this->assertSame([], $result['proof_gap_index']);
    }

    // AC: proven dimensions do not lower audited maturity
    public function test_proven_dimensions_do_not_lower_maturity(): void
    {
        $result = $this->auditor()->audit([
            'overall_claimed_maturity' => 1.0,
            'maturity_claims' => [
                $this->claim(['dimension' => 'a', 'claimed_score' => 1.0, 'evidence_refs' => ['runtime:ok']]),
            ],
        ]);

        $this->assertSame(1.0, $result['overall_audited_maturity']);
        $this->assertSame([], $result['proof_gap_index']);
    }

    // ── AC: intent, doc, plan, future or proposed evidence remains unproven for maturity ──

    public function test_intent_evidence_remains_unproven(): void
    {
        $r = $this->auditor()->audit([
            'maturity_claims' => [$this->claim(['evidence_refs' => ['intent:planned']])],
        ]);

        $this->assertNotSame('proven', $r['dimension_verdicts'][0]['verdict']);
        $this->assertSame('intent_only', $r['dimension_verdicts'][0]['verdict']);
    }

    public function test_doc_evidence_remains_unproven(): void
    {
        $r = $this->auditor()->audit([
            'maturity_claims' => [$this->claim(['evidence_refs' => ['doc:design_doc']])],
        ]);

        $this->assertSame('intent_only', $r['dimension_verdicts'][0]['verdict']);
    }

    public function test_plan_evidence_remains_unproven(): void
    {
        $r = $this->auditor()->audit([
            'maturity_claims' => [$this->claim(['evidence_refs' => ['plan:roadmap']])],
        ]);

        $this->assertSame('intent_only', $r['dimension_verdicts'][0]['verdict']);
    }

    public function test_future_evidence_remains_unproven(): void
    {
        $r = $this->auditor()->audit([
            'maturity_claims' => [$this->claim(['evidence_refs' => ['future:next_quarter']])],
        ]);

        $this->assertSame('intent_only', $r['dimension_verdicts'][0]['verdict']);
    }

    public function test_proposed_evidence_remains_unproven(): void
    {
        $r = $this->auditor()->audit([
            'maturity_claims' => [$this->claim(['evidence_refs' => ['proposed:draft']])],
        ]);

        $this->assertSame('intent_only', $r['dimension_verdicts'][0]['verdict']);
    }

    // ── AC: refutes or contradicts evidence marks contradicted even with weak positive evidence ──

    public function test_refutes_evidence_marks_contradicted_even_with_weak_positive(): void
    {
        $r = $this->auditor()->audit([
            'maturity_claims' => [$this->claim([
                'evidence_refs' => ['runtime:telemetry', 'refutes:failure_report'],
            ])],
        ]);

        $this->assertSame('contradicted', $r['dimension_verdicts'][0]['verdict']);
        $this->assertSame(0.0, $r['dimension_verdicts'][0]['audited_score']);
    }

    public function test_contradicts_evidence_marks_contradicted_even_with_runtime(): void
    {
        $r = $this->auditor()->audit([
            'maturity_claims' => [$this->claim([
                'evidence_refs' => ['runtime:pass', 'contradicts:regression_detected'],
            ])],
        ]);

        $this->assertSame('contradicted', $r['dimension_verdicts'][0]['verdict']);
    }

    public function test_disproves_evidence_marks_contradicted(): void
    {
        $r = $this->auditor()->audit([
            'maturity_claims' => [$this->claim([
                'evidence_refs' => ['disproves:counterexample'],
            ])],
        ]);

        $this->assertSame('contradicted', $r['dimension_verdicts'][0]['verdict']);
    }

    // ── AC: runtime, live_proof or live_run evidence can mark proven when not contradicted ──

    public function test_runtime_evidence_marks_proven_when_not_contradicted(): void
    {
        $r = $this->auditor()->audit([
            'maturity_claims' => [$this->claim(['evidence_refs' => ['runtime:telemetry']])],
        ]);

        $this->assertSame('proven', $r['dimension_verdicts'][0]['verdict']);
    }

    public function test_live_proof_evidence_marks_proven_when_not_contradicted(): void
    {
        $r = $this->auditor()->audit([
            'maturity_claims' => [$this->claim(['evidence_refs' => ['live_proof:soak_test']])],
        ]);

        $this->assertSame('proven', $r['dimension_verdicts'][0]['verdict']);
    }

    public function test_live_run_evidence_marks_proven_when_not_contradicted(): void
    {
        $r = $this->auditor()->audit([
            'maturity_claims' => [$this->claim(['evidence_refs' => ['live_run:production_deploy']])],
        ]);

        $this->assertSame('proven', $r['dimension_verdicts'][0]['verdict']);
    }

    public function test_runtime_evidence_not_proven_when_contradicted(): void
    {
        $r = $this->auditor()->audit([
            'maturity_claims' => [$this->claim([
                'evidence_refs' => ['runtime:pass', 'refutes:failure'],
            ])],
        ]);

        $this->assertNotSame('proven', $r['dimension_verdicts'][0]['verdict']);
        $this->assertSame('contradicted', $r['dimension_verdicts'][0]['verdict']);
    }
}
