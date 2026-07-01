<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCompressionMutationRiskModel;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainCompressionMutationRiskModelTest extends TestCase
{
    private function svc(): AtlasExternalBrainCompressionMutationRiskModel
    {
        return new AtlasExternalBrainCompressionMutationRiskModel;
    }

    private function simplifyAction(array $overrides = []): array
    {
        return array_merge([
            'action_id' => 'a1',
            'action_type' => 'simplify',
            'branch_coverage' => 0.9,
            'capability_criticality' => 'low',
            'consumer_count' => 0,
            'guards_present' => ['mutation_test'],
        ], $overrides);
    }

    // ── base guards by action_type ────────────────────────────────────────────

    public function test_simplify_requires_only_mutation_test_when_clean(): void
    {
        $r = $this->svc()->evaluate(['actions' => [$this->simplifyAction()]]);

        $this->assertSame(['mutation_test'], $r['actions'][0]['required_guards']);
        $this->assertSame(AtlasExternalBrainCompressionMutationRiskModel::DECISION_GO, $r['actions'][0]['decision']);
    }

    public function test_delete_requires_mutation_test_and_regression_suite(): void
    {
        $r = $this->svc()->evaluate(['actions' => [$this->simplifyAction([
            'action_type' => 'delete',
            'guards_present' => ['mutation_test', 'regression_suite'],
        ])]]);

        $required = $r['actions'][0]['required_guards'];
        $this->assertContains('mutation_test', $required);
        $this->assertContains('regression_suite', $required);
    }

    public function test_merge_requires_mutation_test_and_integration_test(): void
    {
        $r = $this->svc()->evaluate(['actions' => [$this->simplifyAction([
            'action_type' => 'merge',
            'guards_present' => ['mutation_test', 'integration_test'],
        ])]]);

        $required = $r['actions'][0]['required_guards'];
        $this->assertContains('mutation_test', $required);
        $this->assertContains('integration_test', $required);
    }

    // ── additional guards from branch coverage / criticality / consumer count ──

    public function test_low_branch_coverage_adds_branch_coverage_uplift_guard(): void
    {
        $r = $this->svc()->evaluate(['actions' => [$this->simplifyAction(['branch_coverage' => 0.4])]]);

        $this->assertContains('branch_coverage_uplift', $r['actions'][0]['required_guards']);
    }

    public function test_high_capability_criticality_adds_critical_capability_review_guard(): void
    {
        $r = $this->svc()->evaluate(['actions' => [$this->simplifyAction(['capability_criticality' => 'high'])]]);

        $this->assertContains('critical_capability_review', $r['actions'][0]['required_guards']);
        $this->assertTrue($r['actions'][0]['high_risk']);
    }

    public function test_high_consumer_count_adds_consumer_impact_assessment_guard(): void
    {
        $r = $this->svc()->evaluate(['actions' => [$this->simplifyAction(['consumer_count' => 5])]]);

        $this->assertContains('consumer_impact_assessment', $r['actions'][0]['required_guards']);
        $this->assertTrue($r['actions'][0]['high_risk']);
    }

    // ── AC: high_risk_guard_case ───────────────────────────────────────────────

    public function test_high_risk_guard_case_delete_of_critical_capability_with_many_consumers(): void
    {
        $r = $this->svc()->evaluate(['actions' => [$this->simplifyAction([
            'action_type' => 'delete',
            'capability_criticality' => 'critical',
            'consumer_count' => 4,
            'branch_coverage' => 0.9,
            'guards_present' => ['mutation_test', 'regression_suite', 'critical_capability_review', 'consumer_impact_assessment'],
        ])]]);

        $entry = $r['actions'][0];
        $this->assertTrue($entry['high_risk']);
        $this->assertSame(
            ['consumer_impact_assessment', 'critical_capability_review', 'mutation_test', 'regression_suite'],
            $entry['required_guards'],
        );
        $this->assertSame(AtlasExternalBrainCompressionMutationRiskModel::DECISION_GO, $entry['decision']);
        $this->assertSame([], $entry['missing_guards']);
    }

    // ── AC: missing_guard_hold_case ────────────────────────────────────────────

    public function test_missing_guard_hold_case_high_risk_action_without_matching_guards_holds(): void
    {
        $r = $this->svc()->evaluate(['actions' => [$this->simplifyAction([
            'action_type' => 'delete',
            'capability_criticality' => 'critical',
            'consumer_count' => 4,
            'guards_present' => ['mutation_test'], // missing regression_suite, critical_capability_review, consumer_impact_assessment
        ])]]);

        $entry = $r['actions'][0];
        $this->assertTrue($entry['high_risk']);
        $this->assertSame(AtlasExternalBrainCompressionMutationRiskModel::DECISION_HOLD, $entry['decision']);
        $this->assertContains('regression_suite', $entry['missing_guards']);
        $this->assertContains('critical_capability_review', $entry['missing_guards']);
        $this->assertContains('consumer_impact_assessment', $entry['missing_guards']);
        $this->assertSame(1, $r['summary']['hold_count']);
    }

    public function test_low_risk_action_missing_a_guard_also_holds(): void
    {
        $r = $this->svc()->evaluate(['actions' => [$this->simplifyAction(['guards_present' => []])]]);

        $this->assertFalse($r['actions'][0]['high_risk']);
        $this->assertSame(AtlasExternalBrainCompressionMutationRiskModel::DECISION_HOLD, $r['actions'][0]['decision']);
        $this->assertSame(['mutation_test'], $r['actions'][0]['missing_guards']);
    }

    // ── unknown action_type defaults safely to simplify ───────────────────────

    public function test_unknown_action_type_defaults_to_simplify_guards(): void
    {
        $r = $this->svc()->evaluate(['actions' => [$this->simplifyAction(['action_type' => 'nonsense'])]]);

        $this->assertSame('simplify', $r['actions'][0]['action_type']);
        $this->assertSame(['mutation_test'], $r['actions'][0]['required_guards']);
    }

    // ── malformed / empty ──────────────────────────────────────────────────────

    public function test_malformed_action_entry_is_skipped(): void
    {
        $r = $this->svc()->evaluate(['actions' => ['not-an-array', $this->simplifyAction()]]);

        $this->assertCount(1, $r['actions']);
    }

    public function test_empty_actions_yields_empty_result(): void
    {
        $r = $this->svc()->evaluate(['actions' => []]);

        $this->assertSame([], $r['actions']);
        $this->assertSame(0, $r['summary']['total']);
    }

    // ── schema / determinism ───────────────────────────────────────────────────

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->evaluate(['actions' => []]);

        $this->assertSame(AtlasExternalBrainCompressionMutationRiskModel::SCHEMA, $r['schema']);
    }

    public function test_evaluate_is_deterministic(): void
    {
        $input = ['actions' => [$this->simplifyAction(), $this->simplifyAction(['action_id' => 'a2', 'action_type' => 'delete'])]];

        $this->assertSame(
            $this->svc()->evaluate($input),
            $this->svc()->evaluate($input),
        );
    }
}
