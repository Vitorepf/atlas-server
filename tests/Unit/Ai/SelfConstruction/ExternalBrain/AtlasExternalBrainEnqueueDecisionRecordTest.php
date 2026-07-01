<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainEnqueueDecisionRecord;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainEnqueueDecisionRecordTest extends TestCase
{
    private AtlasExternalBrainEnqueueDecisionRecord $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new AtlasExternalBrainEnqueueDecisionRecord();
    }

    // AC: enqueue refused without value_density and expected_compounding_effect
    public function test_enqueue_without_value_density_refused(): void
    {
        $result = $this->gate->record([
            'decision' => 'enqueue',
            'validation_evidence' => [],
        ]);

        $this->assertFalse($result['accepted']);
        $this->assertContains('missing:value_density', $result['blockers']);
    }

    public function test_enqueue_without_compounding_effect_refused(): void
    {
        $result = $this->gate->record([
            'decision' => 'enqueue',
            'validation_evidence' => ['value_density' => 0.8],
        ]);

        $this->assertFalse($result['accepted']);
        $this->assertContains('missing:expected_compounding_effect', $result['blockers']);
    }

    // AC: accepted enqueue includes value_density, expected_compounding_effect, operator_visible_reason
    public function test_accepted_enqueue_includes_required_fields(): void
    {
        $result = $this->gate->record([
            'decision' => 'enqueue',
            'validation_evidence' => [
                'value_density' => 0.9,
                'expected_compounding_effect' => 'reduces_give_back_risk',
            ],
            'operator_visible_reason' => 'High-value Brain family fix',
        ]);

        $this->assertTrue($result['accepted']);
        $this->assertSame(0.9, $result['record']['value_density']);
        $this->assertSame('reduces_give_back_risk', $result['record']['expected_compounding_effect']);
        $this->assertSame('High-value Brain family fix', $result['record']['operator_visible_reason']);
    }

    // AC: defer/consolidate/reject require decline_reason but not enqueue evidence
    public function test_defer_with_decline_reason_accepted(): void
    {
        $result = $this->gate->record([
            'decision' => 'defer',
            'decline_reason' => 'awaiting prerequisite',
        ]);

        $this->assertTrue($result['accepted']);
        $this->assertArrayNotHasKey('value_density', $result['record']);
    }

    public function test_reject_without_decline_reason_refused(): void
    {
        $result = $this->gate->record([
            'decision' => 'reject',
        ]);

        $this->assertFalse($result['accepted']);
        $this->assertContains('missing:decline_reason', $result['blockers']);
    }

    public function test_consolidate_with_reason_accepted(): void
    {
        $result = $this->gate->record([
            'decision' => 'consolidate',
            'decline_reason' => 'duplicate of existing task',
        ]);

        $this->assertTrue($result['accepted']);
    }
}
