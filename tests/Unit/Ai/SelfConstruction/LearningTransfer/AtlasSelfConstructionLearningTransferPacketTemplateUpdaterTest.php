<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\LearningTransfer;

use App\Services\Ai\SelfConstruction\LearningTransfer\AtlasSelfConstructionLearningTransferPacketTemplateUpdater;
use Tests\TestCase;

final class AtlasSelfConstructionLearningTransferPacketTemplateUpdaterTest extends TestCase
{
    private function admittedPlan(string $class, array $extra = []): array
    {
        return array_merge([
            'rollout_class' => 'bounded_proposed',
            'lesson_class' => $class,
            'evidence_refs' => ['ev-default'],
        ], $extra);
    }

    private function validTemplate(array $extra = []): array
    {
        return array_merge([
            'allowed_files' => ['app/Foo.php', 'tests/Unit/FooTest.php'],
            'acceptance_criteria' => ['Runnable proof: ./vendor/bin/phpunit tests/Unit/FooTest.php'],
            'required_evidence' => ['tests_or_gates_result'],
        ], $extra);
    }

    public function test_allowed_update_adds_guidance_to_the_matching_field(): void
    {
        $result = (new AtlasSelfConstructionLearningTransferPacketTemplateUpdater)
            ->apply($this->admittedPlan('missing_dependency'), $this->validTemplate(['objective' => 'do thing']));

        $this->assertTrue($result['applied']);
        $this->assertSame('dependency_guidance', $result['target_field']);
        $this->assertContains('lesson:missing_dependency', $result['updated_template']['dependency_guidance']);
        $this->assertSame('do thing', $result['updated_template']['objective'], 'unrelated fields preserved');
    }

    public function test_scope_gap_routes_to_scope_guidance(): void
    {
        $result = (new AtlasSelfConstructionLearningTransferPacketTemplateUpdater)
            ->apply($this->admittedPlan('scope_gap'), $this->validTemplate());

        $this->assertTrue($result['applied']);
        $this->assertSame('scope_guidance', $result['target_field']);
    }

    public function test_disallowed_lesson_class_is_refused(): void
    {
        $result = (new AtlasSelfConstructionLearningTransferPacketTemplateUpdater)
            ->apply($this->admittedPlan('mystery_class'), $this->validTemplate());

        $this->assertFalse($result['applied']);
        $this->assertContains('unknown_lesson_class:mystery_class', $result['blockers']);
        $this->assertNull($result['target_field']);
    }

    public function test_plan_without_admitted_rollout_class_is_refused(): void
    {
        $result = (new AtlasSelfConstructionLearningTransferPacketTemplateUpdater)
            ->apply(['rollout_class' => 'blocked', 'lesson_class' => 'scope_gap'], []);

        $this->assertFalse($result['applied']);
        $this->assertContains('plan_not_admitted:blocked', $result['blockers']);
    }

    public function test_plan_without_lesson_class_is_refused(): void
    {
        $result = (new AtlasSelfConstructionLearningTransferPacketTemplateUpdater)
            ->apply(['rollout_class' => 'bounded_proposed'], []);

        $this->assertFalse($result['applied']);
        $this->assertContains('plan_lesson_class_missing', $result['blockers']);
    }

    public function test_plan_without_evidence_refs_is_refused(): void
    {
        $result = (new AtlasSelfConstructionLearningTransferPacketTemplateUpdater)
            ->apply(['rollout_class' => 'bounded_proposed', 'lesson_class' => 'scope_gap'], []);

        $this->assertFalse($result['applied']);
        $this->assertContains('plan_evidence_refs_missing', $result['blockers']);
    }

    public function test_repeated_application_is_idempotent(): void
    {
        $updater = new AtlasSelfConstructionLearningTransferPacketTemplateUpdater;
        $first = $updater->apply($this->admittedPlan('insufficient_evidence'), $this->validTemplate());
        $this->assertTrue($first['applied']);

        $second = $updater->apply($this->admittedPlan('insufficient_evidence'), $first['updated_template']);
        $this->assertFalse($second['applied']);
        $this->assertContains('already_applied', $second['blockers']);
        $this->assertSame($first['updated_template'], $second['updated_template']);
    }

    public function test_template_missing_required_keys_is_rejected(): void
    {
        $updater = new AtlasSelfConstructionLearningTransferPacketTemplateUpdater;

        $noFiles = $updater->apply($this->admittedPlan('scope_gap'), ['acceptance_criteria' => ['x'], 'required_evidence' => ['y']]);
        $this->assertFalse($noFiles['applied']);
        $this->assertContains('template_missing_allowed_files', $noFiles['blockers']);

        $noAcceptance = $updater->apply($this->admittedPlan('scope_gap'), ['allowed_files' => ['f'], 'required_evidence' => ['y']]);
        $this->assertFalse($noAcceptance['applied']);
        $this->assertContains('template_missing_acceptance_criteria', $noAcceptance['blockers']);

        $noEvidence = $updater->apply($this->admittedPlan('scope_gap'), ['allowed_files' => ['f'], 'acceptance_criteria' => ['x']]);
        $this->assertFalse($noEvidence['applied']);
        $this->assertContains('template_missing_required_evidence', $noEvidence['blockers']);
    }

    public function test_no_capability_delta_when_guidance_field_is_full(): void
    {
        $updater = new AtlasSelfConstructionLearningTransferPacketTemplateUpdater;
        $template = $this->validTemplate([
            'scope_guidance' => ['lesson:a', 'lesson:b', 'lesson:c', 'lesson:d', 'lesson:e'],
        ]);

        $result = $updater->apply($this->admittedPlan('scope_gap'), $template);

        $this->assertFalse($result['applied']);
        $this->assertContains('no_capability_delta', $result['blockers']);
    }

    public function test_accepted_update_emits_deterministic_template_delta_hash_and_evidence_refs(): void
    {
        $updater = new AtlasSelfConstructionLearningTransferPacketTemplateUpdater;
        $plan = $this->admittedPlan('scope_gap', ['evidence_refs' => ['ev-abc', 'ev-def']]);

        $resultA = $updater->apply($plan, $this->validTemplate());
        $resultB = $updater->apply($plan, $this->validTemplate());

        $this->assertTrue($resultA['applied']);
        $this->assertNotNull($resultA['template_delta_hash']);
        $this->assertSame(64, strlen($resultA['template_delta_hash']));
        $this->assertSame($resultA['template_delta_hash'], $resultB['template_delta_hash'], 'hash must be deterministic');
        $this->assertSame(['ev-abc', 'ev-def'], $resultA['evidence_refs']);
    }

    public function test_forbidden_dependencies_block_update(): void
    {
        $updater = new AtlasSelfConstructionLearningTransferPacketTemplateUpdater;

        foreach (['operator', 'human_action', 'claude_code', 'codex', 'cursor', 'network', 'git'] as $dep) {
            $plan = $this->admittedPlan('scope_gap', ['steady_state_dependencies' => [$dep]]);
            $result = $updater->apply($plan, $this->validTemplate());

            $this->assertFalse($result['applied'], "dep=$dep must be blocked");
            $this->assertContains('forbidden_steady_state_dependency:'.$dep, $result['blockers'], "dep=$dep blocker missing");
        }
    }

    public function test_accepted_mutation_records_claimable_contract_preserved_reason(): void
    {
        $result = (new AtlasSelfConstructionLearningTransferPacketTemplateUpdater)
            ->apply($this->admittedPlan('missing_dependency'), $this->validTemplate());

        $this->assertTrue($result['applied']);
        $this->assertSame('claimable_contract_preserved', $result['reason']);
    }

    public function test_template_missing_implementation_file_is_rejected(): void
    {
        $template = $this->validTemplate(['allowed_files' => ['tests/Unit/FooTest.php']]);

        $result = (new AtlasSelfConstructionLearningTransferPacketTemplateUpdater)
            ->apply($this->admittedPlan('missing_dependency'), $template);

        $this->assertFalse($result['applied']);
        $this->assertContains('claimable_contract_violated:missing_implementation_scope', $result['blockers']);
    }

    public function test_template_missing_test_file_is_rejected(): void
    {
        $template = $this->validTemplate(['allowed_files' => ['app/Foo.php']]);

        $result = (new AtlasSelfConstructionLearningTransferPacketTemplateUpdater)
            ->apply($this->admittedPlan('missing_dependency'), $template);

        $this->assertFalse($result['applied']);
        $this->assertContains('claimable_contract_violated:missing_test_scope', $result['blockers']);
    }

    public function test_template_missing_runnable_acceptance_is_rejected(): void
    {
        $template = $this->validTemplate(['acceptance_criteria' => ['looks good to me']]);

        $result = (new AtlasSelfConstructionLearningTransferPacketTemplateUpdater)
            ->apply($this->admittedPlan('missing_dependency'), $template);

        $this->assertFalse($result['applied']);
        $this->assertContains('claimable_contract_violated:missing_runnable_acceptance', $result['blockers']);
    }

    public function test_template_missing_required_evidence_is_rejected(): void
    {
        $template = $this->validTemplate(['required_evidence' => []]);

        $result = (new AtlasSelfConstructionLearningTransferPacketTemplateUpdater)
            ->apply($this->admittedPlan('missing_dependency'), $template);

        $this->assertFalse($result['applied']);
        $this->assertContains('claimable_contract_violated:missing_required_evidence', $result['blockers']);
    }

    // ── AC: allowed_files guidance and poison-prevention guidance routing ──────

    public function test_allowed_files_gap_routes_to_allowed_files_guidance(): void
    {
        $result = (new AtlasSelfConstructionLearningTransferPacketTemplateUpdater)
            ->apply($this->admittedPlan('allowed_files_gap'), $this->validTemplate());

        $this->assertTrue($result['applied']);
        $this->assertSame('allowed_files_guidance', $result['target_field']);
        $this->assertContains('lesson:allowed_files_gap', $result['updated_template']['allowed_files_guidance']);
    }

    public function test_poison_pattern_routes_to_poison_prevention_guidance(): void
    {
        $result = (new AtlasSelfConstructionLearningTransferPacketTemplateUpdater)
            ->apply($this->admittedPlan('poison_pattern'), $this->validTemplate());

        $this->assertTrue($result['applied']);
        $this->assertSame('poison_prevention_guidance', $result['target_field']);
        $this->assertContains('lesson:poison_pattern', $result['updated_template']['poison_prevention_guidance']);
    }

    // ── AC: weak lessons are ignored, strong lessons update the template ───────

    public function test_weak_lesson_is_ignored(): void
    {
        $plan = $this->admittedPlan('scope_gap', ['lesson_strength' => 0.2]);

        $result = (new AtlasSelfConstructionLearningTransferPacketTemplateUpdater)
            ->apply($plan, $this->validTemplate());

        $this->assertFalse($result['applied']);
        $this->assertContains('lesson_too_weak:0.2', $result['blockers']);
        $this->assertSame($this->validTemplate(), $result['updated_template']);
    }

    public function test_strong_lesson_updates_the_template(): void
    {
        $plan = $this->admittedPlan('scope_gap', ['lesson_strength' => 0.9]);

        $result = (new AtlasSelfConstructionLearningTransferPacketTemplateUpdater)
            ->apply($plan, $this->validTemplate());

        $this->assertTrue($result['applied']);
        $this->assertSame('scope_guidance', $result['target_field']);
    }

    public function test_omitted_lesson_strength_preserves_legacy_apply_behavior(): void
    {
        $result = (new AtlasSelfConstructionLearningTransferPacketTemplateUpdater)
            ->apply($this->admittedPlan('scope_gap'), $this->validTemplate());

        $this->assertTrue($result['applied'], 'no strength signal => treated as strong, unchanged legacy path');
    }

    // ── AC: existing template intent is preserved ───────────────────────────────

    public function test_existing_template_intent_and_other_guidance_fields_are_preserved(): void
    {
        $template = $this->validTemplate([
            'objective' => 'do the important thing',
            'give_back_guidance' => ['lesson:prior_lesson'],
        ]);

        $result = (new AtlasSelfConstructionLearningTransferPacketTemplateUpdater)
            ->apply($this->admittedPlan('allowed_files_gap'), $template);

        $this->assertTrue($result['applied']);
        $this->assertSame('do the important thing', $result['updated_template']['objective']);
        $this->assertSame(['lesson:prior_lesson'], $result['updated_template']['give_back_guidance']);
    }
}
