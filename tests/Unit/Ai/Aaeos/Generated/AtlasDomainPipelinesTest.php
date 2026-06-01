<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDomainPipelinesService;
use Tests\TestCase;

/**
 * Pins the documented domain-pipeline contracts from
 * docs/engineering-knowledge-base/operating-system/domain-pipelines.md.
 *
 * Pure, no DB, no RefreshDatabase.
 */
final class AtlasDomainPipelinesTest extends TestCase
{
    private function service(): AtlasDomainPipelinesService
    {
        return new AtlasDomainPipelinesService();
    }

    /**
     * The canonical pipeline must be exactly the doc's ordered stage sequence:
     * input -> domain -> intent -> profile -> flow -> context -> policy -> decide
     * -> executor -> execution -> gates -> repair/escalation -> evidence ->
     * learning -> output (15 stages).
     */
    public function test_canonical_pipeline_is_the_documented_ordered_sequence(): void
    {
        $result = $this->service()->canonicalPipeline();

        $this->assertSame([
            'input', 'domain', 'intent', 'profile', 'flow', 'context', 'policy',
            'decide', 'executor', 'execution', 'gates', 'repair_escalation',
            'evidence', 'learning', 'output',
        ], $result['stages']);
        $this->assertSame(15, $result['count']);
        // The doc invariant scopes exactly these three as un-skippable.
        $this->assertSame(['policy', 'gates', 'evidence'], $result['mandatory_operational_stages']);
    }

    /**
     * Doc invariant: "No mature domain may skip policy, evidence or gates for
     * operational work." A mature+operational plan missing them is rejected and
     * names every skipped mandatory stage.
     */
    public function test_mature_operational_plan_skipping_policy_evidence_gates_is_rejected(): void
    {
        $result = $this->service()->validatePipeline([
            'stages' => ['input', 'domain', 'intent', 'decide', 'output'],
            'mature' => true,
            'operational' => true,
        ]);

        $this->assertFalse($result['valid']);
        $this->assertSame('mature_operational_plan_skips_mandatory_stage', $result['reason']);
        $this->assertSame(['policy', 'gates', 'evidence'], $result['missing_mandatory']);
    }

    /**
     * The same skip is exempt when the work is NOT mature+operational (the doc
     * scopes the rule to mature operational work). Order is still valid here.
     */
    public function test_non_operational_plan_is_exempt_from_mandatory_stage_rule(): void
    {
        $result = $this->service()->validatePipeline([
            'stages' => ['input', 'domain', 'intent', 'decide', 'output'],
            'mature' => true,
            'operational' => false,
        ]);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['missing_mandatory']);
        $this->assertSame('pipeline_plan_valid', $result['reason']);
    }

    /** A stage order that diverges from the canon (policy before context) is invalid. */
    public function test_out_of_order_stages_are_invalid(): void
    {
        $result = $this->service()->validatePipeline([
            // policy placed before context — reversed vs canon.
            'stages' => ['input', 'domain', 'intent', 'profile', 'flow', 'policy', 'context'],
            'mature' => false,
            'operational' => false,
        ]);

        $this->assertFalse($result['valid']);
        $this->assertFalse($result['order_valid']);
        $this->assertSame('stage_order_diverges_from_canon', $result['reason']);
    }

    /**
     * Finance "Limits": review-only — a market order is blocked outright,
     * regardless of any other signal.
     */
    public function test_finance_market_order_is_blocked_by_review_only_limit(): void
    {
        $result = $this->service()->evaluateAction([
            'domain' => 'finance',
            'capability' => 'market_order',
        ]);

        $this->assertFalse($result['allowed']);
        $this->assertSame('finance_domain_forbids_capability', $result['reason']);
        // Other finance prohibitions are equally blocked.
        $this->assertFalse($this->service()->evaluateAction([
            'domain' => 'finance', 'capability' => 'transfer',
        ])['allowed']);
    }

    /**
     * Personal Development "Limits": no automatic mutation of calendar/tasks/
     * external systems and no diagnosis.
     */
    public function test_personal_development_cannot_mutate_calendar_or_diagnose(): void
    {
        $svc = $this->service();

        $this->assertFalse($svc->evaluateAction([
            'domain' => 'personal_development', 'capability' => 'mutate_calendar',
        ])['allowed']);
        $this->assertFalse($svc->evaluateAction([
            'domain' => 'personal_development', 'capability' => 'diagnosis',
        ])['allowed']);
        // A reflection (non-forbidden capability) is allowed.
        $this->assertTrue($svc->evaluateAction([
            'domain' => 'personal_development', 'capability' => 'reflection',
        ])['allowed']);
    }

    /**
     * Self-Improvement/Curator: "High-risk changes require gates and human review
     * before critical behavior changes." High-risk is allowed only when BOTH
     * gates_passed and human_review are true; missing either keeps it gated.
     */
    public function test_self_improvement_high_risk_requires_gates_and_human_review(): void
    {
        $svc = $this->service();

        // Missing both -> gated (not allowed) but flagged as needing review.
        $gated = $svc->evaluateAction([
            'domain' => 'self_improvement',
            'capability' => 'apply_change',
            'high_risk' => true,
        ]);
        $this->assertFalse($gated['allowed']);
        $this->assertTrue($gated['requires_human_review']);
        $this->assertSame('high_risk_change_requires_gates_and_human_review', $gated['reason']);

        // Gates green but no human review -> still gated.
        $this->assertFalse($svc->evaluateAction([
            'domain' => 'self_improvement', 'capability' => 'apply_change',
            'high_risk' => true, 'gates_passed' => true, 'human_review' => false,
        ])['allowed']);

        // Both cleared -> allowed.
        $cleared = $svc->evaluateAction([
            'domain' => 'self_improvement', 'capability' => 'apply_change',
            'high_risk' => true, 'gates_passed' => true, 'human_review' => true,
        ]);
        $this->assertTrue($cleared['allowed']);
        $this->assertSame('high_risk_change_cleared_gates_and_human_review', $cleared['reason']);
    }

    /**
     * Doc: `atlas dev`/`forge`/`fix`/`continue` are intensities/aliases of the
     * same Programming domain, which has full executor range (no doc-forbidden
     * capability).
     */
    public function test_programming_aliases_resolve_and_have_full_executor_range(): void
    {
        $svc = $this->service();

        $this->assertSame('programming', $svc->resolveDomain('forge')['domain']);
        $this->assertSame('programming', $svc->resolveDomain('fix')['domain']);
        $this->assertSame('self_improvement', $svc->resolveDomain('curator')['domain']);

        // A programming action with a capability that finance forbids is fine
        // here — programming has no doc-level capability ban.
        $this->assertTrue($svc->evaluateAction([
            'domain' => 'dev', 'capability' => 'release',
        ])['allowed']);
    }
}
