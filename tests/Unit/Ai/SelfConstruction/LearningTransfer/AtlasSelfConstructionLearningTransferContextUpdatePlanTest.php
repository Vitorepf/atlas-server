<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\LearningTransfer;

use App\Services\Ai\SelfConstruction\LearningTransfer\AtlasSelfConstructionLearningTransferContextUpdatePlan;
use Tests\TestCase;

final class AtlasSelfConstructionLearningTransferContextUpdatePlanTest extends TestCase
{
    private function admittedLesson(string $class): array
    {
        return [
            'decision' => 'admit',
            'class' => $class,
            'evidence_refs' => ['evidence:run-42'],
            'ttl_seconds' => 86400,
            'affected_flow' => 'worker packet resolution',
            'observed_outcome' => 'worker gave back a duplicate task twice in one session',
            'proposed_prevention_rule' => 'dedupe against the served-task registry before serving',
        ];
    }

    public function test_packet_template_route_for_duplicate_capability(): void
    {
        $plan = (new AtlasSelfConstructionLearningTransferContextUpdatePlan)->plan(
            $this->admittedLesson('duplicate_capability')
        );

        $this->assertSame(AtlasSelfConstructionLearningTransferContextUpdatePlan::SURFACE_PACKET_TEMPLATE, $plan['target_surface']);
        $this->assertSame(AtlasSelfConstructionLearningTransferContextUpdatePlan::ROLLOUT_BOUNDED, $plan['rollout_class']);
        $this->assertSame('packet_templates', $plan['owner_surface']);
        $this->assertNotEmpty($plan['target_paths']);
    }

    public function test_docs_route_for_missing_dependency(): void
    {
        $plan = (new AtlasSelfConstructionLearningTransferContextUpdatePlan)->plan(
            $this->admittedLesson('missing_dependency')
        );

        $this->assertSame(AtlasSelfConstructionLearningTransferContextUpdatePlan::SURFACE_DOCS, $plan['target_surface']);
        $this->assertSame('engineering_docs', $plan['owner_surface']);
        $this->assertContains('docs_lint_green', $plan['verification_needed']);
    }

    public function test_worker_prompt_route_for_stale_context(): void
    {
        $plan = (new AtlasSelfConstructionLearningTransferContextUpdatePlan)->plan(
            $this->admittedLesson('stale_context')
        );

        $this->assertSame(AtlasSelfConstructionLearningTransferContextUpdatePlan::SURFACE_WORKER_PROMPT, $plan['target_surface']);
        $this->assertSame('worker_prompt_owner', $plan['owner_surface']);
    }

    public function test_unsafe_lesson_without_admit_is_blocked(): void
    {
        $lesson = $this->admittedLesson('scope_gap');
        $lesson['decision'] = 'hold';
        $plan = (new AtlasSelfConstructionLearningTransferContextUpdatePlan)->plan($lesson);

        $this->assertSame(AtlasSelfConstructionLearningTransferContextUpdatePlan::ROLLOUT_BLOCKED, $plan['rollout_class']);
        $this->assertContains('lesson_not_admitted:hold', $plan['blockers']);
    }

    public function test_lesson_without_class_is_blocked(): void
    {
        $lesson = $this->admittedLesson('scope_gap');
        $lesson['class'] = '';
        $plan = (new AtlasSelfConstructionLearningTransferContextUpdatePlan)->plan($lesson);

        $this->assertSame(AtlasSelfConstructionLearningTransferContextUpdatePlan::ROLLOUT_BLOCKED, $plan['rollout_class']);
        $this->assertContains('lesson_class_missing', $plan['blockers']);
    }

    public function test_unknown_class_is_blocked(): void
    {
        $plan = (new AtlasSelfConstructionLearningTransferContextUpdatePlan)->plan(
            $this->admittedLesson('mystery_class')
        );

        $this->assertSame(AtlasSelfConstructionLearningTransferContextUpdatePlan::ROLLOUT_BLOCKED, $plan['rollout_class']);
        $this->assertContains('unknown_lesson_class:mystery_class', $plan['blockers']);
    }

    public function test_plan_hash_is_stable_for_identical_input(): void
    {
        $svc = new AtlasSelfConstructionLearningTransferContextUpdatePlan;
        $a = $svc->plan($this->admittedLesson('scope_gap'));
        $b = $svc->plan($this->admittedLesson('scope_gap'));

        $this->assertSame($a['plan_hash'], $b['plan_hash']);
        $this->assertSame($a['plan_id'], $b['plan_id']);
    }

    public function test_plan_hash_changes_when_class_changes(): void
    {
        $svc = new AtlasSelfConstructionLearningTransferContextUpdatePlan;
        $a = $svc->plan($this->admittedLesson('scope_gap'));
        $b = $svc->plan($this->admittedLesson('missing_dependency'));

        $this->assertNotSame($a['plan_hash'], $b['plan_hash']);
    }

    // --- new hardening tests ---

    public function test_missing_evidence_refs_blocks_plan(): void
    {
        $lesson = $this->admittedLesson('scope_gap');
        unset($lesson['evidence_refs']);
        $plan = (new AtlasSelfConstructionLearningTransferContextUpdatePlan)->plan($lesson);
        $this->assertSame(AtlasSelfConstructionLearningTransferContextUpdatePlan::ROLLOUT_BLOCKED, $plan['rollout_class']);
        $this->assertContains('evidence_refs_missing', $plan['blockers']);
    }

    public function test_missing_ttl_seconds_blocks_plan(): void
    {
        $lesson = $this->admittedLesson('scope_gap');
        unset($lesson['ttl_seconds']);
        $plan = (new AtlasSelfConstructionLearningTransferContextUpdatePlan)->plan($lesson);
        $this->assertSame(AtlasSelfConstructionLearningTransferContextUpdatePlan::ROLLOUT_BLOCKED, $plan['rollout_class']);
        $this->assertContains('ttl_seconds_missing', $plan['blockers']);
    }

    public function test_broad_memory_rewrite_blocks_plan(): void
    {
        $lesson = $this->admittedLesson('scope_gap');
        $lesson['broad_memory_rewrite'] = true;
        $plan = (new AtlasSelfConstructionLearningTransferContextUpdatePlan)->plan($lesson);
        $this->assertSame(AtlasSelfConstructionLearningTransferContextUpdatePlan::ROLLOUT_BLOCKED, $plan['rollout_class']);
        $this->assertContains('broad_memory_rewrite_rejected', $plan['blockers']);
    }

    public function test_bounded_action_includes_rollback_hint_and_stable_action_hash(): void
    {
        $svc = new AtlasSelfConstructionLearningTransferContextUpdatePlan;
        $plan = $svc->plan($this->admittedLesson('stale_context'));

        $this->assertSame(AtlasSelfConstructionLearningTransferContextUpdatePlan::ROLLOUT_BOUNDED, $plan['rollout_class']);
        $this->assertNotEmpty($plan['rollback_hint']);
        $this->assertSame(64, strlen($plan['action_hash']));
        // Stable: same input ⇒ same action_hash
        $this->assertSame($plan['action_hash'], $svc->plan($this->admittedLesson('stale_context'))['action_hash']);
    }

    public function test_blocked_plan_has_empty_target_surface(): void
    {
        $lesson = $this->admittedLesson('scope_gap');
        $lesson['class'] = '';
        $plan = (new AtlasSelfConstructionLearningTransferContextUpdatePlan)->plan($lesson);
        $this->assertSame('', $plan['target_surface']);
    }

    // --- AC1: memory surface ---

    public function test_memory_route_for_operator_correction(): void
    {
        $plan = (new AtlasSelfConstructionLearningTransferContextUpdatePlan)->plan(
            $this->admittedLesson('operator_correction')
        );

        $this->assertSame(AtlasSelfConstructionLearningTransferContextUpdatePlan::SURFACE_MEMORY, $plan['target_surface']);
        $this->assertSame('atlas_memory_registry', $plan['owner_surface']);
        $this->assertNotEmpty($plan['target_paths']);
        $this->assertNotEmpty($plan['rollback_hint']);
    }

    // --- AC2: vague lessons lacking flow / outcome / prevention rule are rejected ---

    public function test_missing_affected_flow_blocks_plan(): void
    {
        $lesson = $this->admittedLesson('scope_gap');
        unset($lesson['affected_flow']);
        $plan = (new AtlasSelfConstructionLearningTransferContextUpdatePlan)->plan($lesson);

        $this->assertSame(AtlasSelfConstructionLearningTransferContextUpdatePlan::ROLLOUT_BLOCKED, $plan['rollout_class']);
        $this->assertContains('affected_flow_missing', $plan['blockers']);
    }

    public function test_missing_observed_outcome_blocks_plan(): void
    {
        $lesson = $this->admittedLesson('scope_gap');
        unset($lesson['observed_outcome']);
        $plan = (new AtlasSelfConstructionLearningTransferContextUpdatePlan)->plan($lesson);

        $this->assertSame(AtlasSelfConstructionLearningTransferContextUpdatePlan::ROLLOUT_BLOCKED, $plan['rollout_class']);
        $this->assertContains('observed_outcome_missing', $plan['blockers']);
    }

    public function test_missing_proposed_prevention_rule_blocks_plan(): void
    {
        $lesson = $this->admittedLesson('scope_gap');
        unset($lesson['proposed_prevention_rule']);
        $plan = (new AtlasSelfConstructionLearningTransferContextUpdatePlan)->plan($lesson);

        $this->assertSame(AtlasSelfConstructionLearningTransferContextUpdatePlan::ROLLOUT_BLOCKED, $plan['rollout_class']);
        $this->assertContains('proposed_prevention_rule_missing', $plan['blockers']);
    }

    public function test_blank_affected_flow_blocks_plan(): void
    {
        $lesson = $this->admittedLesson('scope_gap');
        $lesson['affected_flow'] = '   ';
        $plan = (new AtlasSelfConstructionLearningTransferContextUpdatePlan)->plan($lesson);

        $this->assertContains('affected_flow_missing', $plan['blockers']);
    }

    // --- AC3: raw transcripts and secrets are never carried into context updates ---

    public function test_raw_transcript_present_blocks_plan(): void
    {
        $lesson = $this->admittedLesson('scope_gap');
        $lesson['raw_transcript'] = 'full session transcript dump...';
        $plan = (new AtlasSelfConstructionLearningTransferContextUpdatePlan)->plan($lesson);

        $this->assertSame(AtlasSelfConstructionLearningTransferContextUpdatePlan::ROLLOUT_BLOCKED, $plan['rollout_class']);
        $this->assertContains('raw_transcript_rejected', $plan['blockers']);
    }

    public function test_secret_in_observed_outcome_blocks_plan(): void
    {
        $lesson = $this->admittedLesson('scope_gap');
        $lesson['observed_outcome'] = 'used token sk-abcdefghijklmnop to authenticate';
        $plan = (new AtlasSelfConstructionLearningTransferContextUpdatePlan)->plan($lesson);

        $this->assertSame(AtlasSelfConstructionLearningTransferContextUpdatePlan::ROLLOUT_BLOCKED, $plan['rollout_class']);
        $this->assertContains('secret_detected', $plan['blockers']);
    }

    public function test_secret_in_prevention_rule_blocks_plan(): void
    {
        $lesson = $this->admittedLesson('scope_gap');
        $lesson['proposed_prevention_rule'] = 'rotate password=hunter2 immediately';
        $plan = (new AtlasSelfConstructionLearningTransferContextUpdatePlan)->plan($lesson);

        $this->assertContains('secret_detected', $plan['blockers']);
    }

    public function test_clean_lesson_without_secrets_is_not_blocked(): void
    {
        $plan = (new AtlasSelfConstructionLearningTransferContextUpdatePlan)->plan(
            $this->admittedLesson('scope_gap')
        );

        $this->assertSame(AtlasSelfConstructionLearningTransferContextUpdatePlan::ROLLOUT_BOUNDED, $plan['rollout_class']);
        $this->assertNotContains('secret_detected', $plan['blockers']);
    }

    // --- context_summary is provider-safe and built only from the vetted facts ---

    public function test_bounded_plan_includes_provider_safe_context_summary(): void
    {
        $plan = (new AtlasSelfConstructionLearningTransferContextUpdatePlan)->plan(
            $this->admittedLesson('scope_gap')
        );

        $this->assertNotEmpty($plan['context_summary']);
        $this->assertStringContainsString('worker packet resolution', $plan['context_summary']);
        $this->assertStringContainsString('dedupe against the served-task registry', $plan['context_summary']);
    }

    public function test_blocked_plan_has_empty_context_summary(): void
    {
        $lesson = $this->admittedLesson('scope_gap');
        $lesson['class'] = '';
        $plan = (new AtlasSelfConstructionLearningTransferContextUpdatePlan)->plan($lesson);

        $this->assertSame('', $plan['context_summary']);
    }
}
