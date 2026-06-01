<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasResearchAutomationRunbookService;
use Tests\TestCase;

/**
 * Pins the documented Research Automation Runbook contracts: activation order,
 * required scheduler guards, forbidden first versions, and read-only-first mode.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/automation-runbook.md
 */
class AtlasResearchAutomationRunbookTest extends TestCase
{
    private function service(): AtlasResearchAutomationRunbookService
    {
        return new AtlasResearchAutomationRunbookService();
    }

    /**
     * Activation Order — stage 1 must be first, and a higher stage is blocked
     * while any lower-numbered stage is not yet active (no skipping ahead).
     */
    public function test_activation_order_blocks_skipping_ahead(): void
    {
        $svc = $this->service();

        // Nothing active yet: stage 1 is allowed (it has no prerequisites).
        $stage1 = $svc->evaluateActivation('read_only_schema_packet_renderer', []);
        $this->assertTrue($stage1['activation_allowed']);
        $this->assertSame(1, $stage1['step']);

        // Jumping straight to scheduled review (step 7) with nothing active is
        // blocked, and every lower stage is reported as a missing prerequisite.
        $jump = $svc->evaluateActivation('scheduled_read_only_review', []);
        $this->assertFalse($jump['activation_allowed']);
        $this->assertSame('prior_stages_not_active_no_skipping', $jump['reason']);
        $this->assertCount(6, $jump['missing_prerequisites']);
        $this->assertContains('read_only_schema_packet_renderer', $jump['missing_prerequisites']);

        // With steps 1..6 active, step 7 opens.
        $active = [
            'read_only_schema_packet_renderer',
            'source_quality_scorer_no_network',
            'manual_research_packet_import',
            'docs_promotion_preview',
            'ap_plan_generator',
            'self_improvement_proposal_emission',
        ];
        $ok = $svc->evaluateActivation('scheduled_read_only_review', $active);
        $this->assertTrue($ok['activation_allowed']);
        $this->assertSame('all_prior_stages_active', $ok['reason']);
        $this->assertSame([], $ok['missing_prerequisites']);
    }

    /**
     * An unknown stage is fail-closed (never activatable).
     */
    public function test_unknown_stage_is_rejected(): void
    {
        $svc = $this->service();

        $verdict = $svc->evaluateActivation('autonomous_apply_everything', ['read_only_schema_packet_renderer']);
        $this->assertFalse($verdict['activation_allowed']);
        $this->assertNull($verdict['step']);
        $this->assertSame('unknown_stage_not_in_activation_order', $verdict['reason']);
    }

    /**
     * Required Guards Before Scheduler — ALL ten guards must be present; a single
     * missing guard closes the scheduler gate.
     */
    public function test_scheduler_blocked_until_all_ten_guards_present(): void
    {
        $svc = $this->service();

        // No guards: closed, all ten reported missing.
        $none = $svc->evaluateSchedulerGate([]);
        $this->assertFalse($none['scheduler_allowed']);
        $this->assertSame('missing_required_guards_scheduler_blocked', $none['reason']);
        $this->assertCount(10, $none['missing_guards']);
        $this->assertSame(10, $none['required_guard_count']);

        $all = [
            'source_registry',
            'allowed_source_list',
            'rate_limits',
            'canonical_url_hash',
            'duplicate_suppression',
            'evidence_ledger_event',
            'proposal_inbox_integration',
            'docs_health_validation',
            'architecture_validation_for_structural_proposals',
            'human_review_for_medium_high_risk',
        ];

        // Drop exactly one guard -> still closed.
        $missingOne = array_slice($all, 0, 9);
        $nine = $svc->evaluateSchedulerGate($missingOne);
        $this->assertFalse($nine['scheduler_allowed']);
        $this->assertSame(['human_review_for_medium_high_risk'], $nine['missing_guards']);
        $this->assertSame(9, $nine['present_guard_count']);

        // All ten -> open.
        $full = $svc->evaluateSchedulerGate($all);
        $this->assertTrue($full['scheduler_allowed']);
        $this->assertSame('all_required_guards_present', $full['reason']);
        $this->assertSame([], $full['missing_guards']);
    }

    /**
     * Forbidden First Versions — any match fails closed; a clean proposal passes.
     */
    public function test_forbidden_first_versions_fail_closed(): void
    {
        $svc = $this->service();

        $bad = $svc->evaluateFirstVersion([
            'read_only_schema_packet_renderer',
            'scheduler_that_changes_code',
        ]);
        $this->assertFalse($bad['first_version_allowed']);
        $this->assertSame('matches_forbidden_first_version_fail_closed', $bad['reason']);
        $this->assertSame(['scheduler_that_changes_code'], $bad['violations']);

        $good = $svc->evaluateFirstVersion([
            'read_only_schema_packet_renderer',
            'source_quality_scorer_no_network',
        ]);
        $this->assertTrue($good['first_version_allowed']);
        $this->assertSame([], $good['violations']);
    }

    /**
     * First-implementation mode — only plan-only / classify-only /
     * promotion-preview are allowed; an apply/write mode is refused.
     */
    public function test_first_implementation_must_be_read_only(): void
    {
        $svc = $this->service();

        $this->assertTrue($svc->evaluateFirstImplementationMode('plan_only')['mode_allowed']);
        $this->assertTrue($svc->evaluateFirstImplementationMode('classify_only')['mode_allowed']);
        $this->assertTrue($svc->evaluateFirstImplementationMode('promotion_preview')['mode_allowed']);

        $apply = $svc->evaluateFirstImplementationMode('apply');
        $this->assertFalse($apply['mode_allowed']);
        $this->assertSame('write_mode_blocked_first_implementation_must_be_read_only', $apply['reason']);
    }

    /**
     * The default snapshot mirrors the doc's read-only posture: 9 stages, 10
     * guards, scheduler gate closed, and only stage 1 activatable.
     */
    public function test_default_snapshot_is_read_only_posture(): void
    {
        $svc = $this->service();
        $snap = $svc->snapshot();

        $this->assertTrue($snap['ok']);
        $this->assertSame(9, $snap['stage_count']);
        $this->assertSame(10, $snap['required_guard_count']);
        $this->assertSame(7, $snap['first_scheduled_step']);
        $this->assertSame(1, $snap['next_activatable_step']);
        $this->assertFalse($snap['scheduler_gate']);
        $this->assertCount(6, $snap['forbidden_first_versions']);
    }
}
