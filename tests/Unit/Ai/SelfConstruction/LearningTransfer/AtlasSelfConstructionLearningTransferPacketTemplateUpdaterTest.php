<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\LearningTransfer;

use App\Services\Ai\SelfConstruction\LearningTransfer\AtlasSelfConstructionLearningTransferPacketTemplateUpdater;
use Tests\TestCase;

final class AtlasSelfConstructionLearningTransferPacketTemplateUpdaterTest extends TestCase
{
    private function admittedPlan(string $class, array $extra = []): array
    {
        return array_merge(['rollout_class' => 'bounded_proposed', 'lesson_class' => $class], $extra);
    }

    private function validTemplate(array $extra = []): array
    {
        return array_merge([
            'allowed_files' => ['app/Foo.php'],
            'acceptance_criteria' => ['Foo must work'],
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
}
