<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\LearningTransfer;

use App\Services\Ai\SelfConstruction\LearningTransfer\AtlasSelfConstructionLearningTransferPacketTemplateUpdater;
use Tests\TestCase;

final class AtlasSelfConstructionLearningTransferPacketTemplateUpdaterTest extends TestCase
{
    private function admittedPlan(string $class): array
    {
        return ['rollout_class' => 'bounded_proposed', 'lesson_class' => $class];
    }

    public function test_allowed_update_adds_guidance_to_the_matching_field(): void
    {
        $result = (new AtlasSelfConstructionLearningTransferPacketTemplateUpdater)
            ->apply($this->admittedPlan('missing_dependency'), ['objective' => 'do thing']);

        $this->assertTrue($result['applied']);
        $this->assertSame('dependency_guidance', $result['target_field']);
        $this->assertContains('lesson:missing_dependency', $result['updated_template']['dependency_guidance']);
        $this->assertSame('do thing', $result['updated_template']['objective'], 'unrelated fields preserved');
    }

    public function test_scope_gap_routes_to_scope_guidance(): void
    {
        $result = (new AtlasSelfConstructionLearningTransferPacketTemplateUpdater)
            ->apply($this->admittedPlan('scope_gap'), []);

        $this->assertTrue($result['applied']);
        $this->assertSame('scope_guidance', $result['target_field']);
    }

    public function test_disallowed_lesson_class_is_refused(): void
    {
        $result = (new AtlasSelfConstructionLearningTransferPacketTemplateUpdater)
            ->apply($this->admittedPlan('mystery_class'), []);

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
        $first = $updater->apply($this->admittedPlan('insufficient_evidence'), []);
        $this->assertTrue($first['applied']);

        $second = $updater->apply($this->admittedPlan('insufficient_evidence'), $first['updated_template']);
        $this->assertFalse($second['applied']);
        $this->assertContains('already_applied', $second['blockers']);
        $this->assertSame($first['updated_template'], $second['updated_template']);
    }
}
