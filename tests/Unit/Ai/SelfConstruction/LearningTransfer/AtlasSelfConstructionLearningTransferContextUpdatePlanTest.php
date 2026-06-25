<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\LearningTransfer;

use App\Services\Ai\SelfConstruction\LearningTransfer\AtlasSelfConstructionLearningTransferContextUpdatePlan;
use Tests\TestCase;

final class AtlasSelfConstructionLearningTransferContextUpdatePlanTest extends TestCase
{
    public function test_packet_template_route_for_duplicate_capability(): void
    {
        $plan = (new AtlasSelfConstructionLearningTransferContextUpdatePlan)->plan([
            'decision' => 'admit', 'class' => 'duplicate_capability',
        ]);

        $this->assertSame(AtlasSelfConstructionLearningTransferContextUpdatePlan::SURFACE_PACKET_TEMPLATE, $plan['target_surface']);
        $this->assertSame(AtlasSelfConstructionLearningTransferContextUpdatePlan::ROLLOUT_BOUNDED, $plan['rollout_class']);
        $this->assertSame('packet_templates', $plan['owner_surface']);
        $this->assertNotEmpty($plan['target_paths']);
    }

    public function test_docs_route_for_missing_dependency(): void
    {
        $plan = (new AtlasSelfConstructionLearningTransferContextUpdatePlan)->plan([
            'decision' => 'admit', 'class' => 'missing_dependency',
        ]);

        $this->assertSame(AtlasSelfConstructionLearningTransferContextUpdatePlan::SURFACE_DOCS, $plan['target_surface']);
        $this->assertSame('engineering_docs', $plan['owner_surface']);
        $this->assertContains('docs_lint_green', $plan['verification_needed']);
    }

    public function test_worker_prompt_route_for_stale_context(): void
    {
        $plan = (new AtlasSelfConstructionLearningTransferContextUpdatePlan)->plan([
            'decision' => 'admit', 'class' => 'stale_context',
        ]);

        $this->assertSame(AtlasSelfConstructionLearningTransferContextUpdatePlan::SURFACE_WORKER_PROMPT, $plan['target_surface']);
        $this->assertSame('worker_prompt_owner', $plan['owner_surface']);
    }

    public function test_unsafe_lesson_without_admit_is_blocked(): void
    {
        $plan = (new AtlasSelfConstructionLearningTransferContextUpdatePlan)->plan([
            'decision' => 'hold', 'class' => 'scope_gap',
        ]);

        $this->assertSame(AtlasSelfConstructionLearningTransferContextUpdatePlan::ROLLOUT_BLOCKED, $plan['rollout_class']);
        $this->assertContains('lesson_not_admitted:hold', $plan['blockers']);
    }

    public function test_lesson_without_class_is_blocked(): void
    {
        $plan = (new AtlasSelfConstructionLearningTransferContextUpdatePlan)->plan([
            'decision' => 'admit', 'class' => '',
        ]);

        $this->assertSame(AtlasSelfConstructionLearningTransferContextUpdatePlan::ROLLOUT_BLOCKED, $plan['rollout_class']);
        $this->assertContains('lesson_class_missing', $plan['blockers']);
    }

    public function test_unknown_class_is_blocked(): void
    {
        $plan = (new AtlasSelfConstructionLearningTransferContextUpdatePlan)->plan([
            'decision' => 'admit', 'class' => 'mystery_class',
        ]);

        $this->assertSame(AtlasSelfConstructionLearningTransferContextUpdatePlan::ROLLOUT_BLOCKED, $plan['rollout_class']);
        $this->assertContains('unknown_lesson_class:mystery_class', $plan['blockers']);
    }

    public function test_plan_hash_is_stable_for_identical_input(): void
    {
        $svc = new AtlasSelfConstructionLearningTransferContextUpdatePlan;
        $a = $svc->plan(['decision' => 'admit', 'class' => 'scope_gap']);
        $b = $svc->plan(['decision' => 'admit', 'class' => 'scope_gap']);

        $this->assertSame($a['plan_hash'], $b['plan_hash']);
        $this->assertSame($a['plan_id'], $b['plan_id']);
    }

    public function test_plan_hash_changes_when_class_changes(): void
    {
        $svc = new AtlasSelfConstructionLearningTransferContextUpdatePlan;
        $a = $svc->plan(['decision' => 'admit', 'class' => 'scope_gap']);
        $b = $svc->plan(['decision' => 'admit', 'class' => 'missing_dependency']);

        $this->assertNotSame($a['plan_hash'], $b['plan_hash']);
    }
}
