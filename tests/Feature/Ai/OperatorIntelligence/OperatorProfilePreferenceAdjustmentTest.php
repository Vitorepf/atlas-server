<?php

namespace Tests\Feature\Ai\OperatorIntelligence;

use App\Models\OperatorProfileItem;
use App\Services\Ai\OperatorIntelligence\OperatorProfileDigestService;
use App\Services\Ai\OperatorIntelligence\OperatorProfileFeedbackService;
use Tests\Concerns\CreatesOperatorIntelligenceTables;
use Tests\TestCase;

final class OperatorProfilePreferenceAdjustmentTest extends TestCase
{
    use CreatesOperatorIntelligenceTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createOperatorIntelligenceTables();
    }

    protected function tearDown(): void
    {
        $this->dropOperatorIntelligenceTables();
        parent::tearDown();
    }

    public function test_preference_adjustment_requires_minimum_behavior_samples(): void
    {
        $item = $this->profileItem(confidence: 0.55);
        $feedback = app(OperatorProfileFeedbackService::class);

        for ($i = 0; $i < 9; $i++) {
            $feedback->record($item, 'accepted');
        }

        $preview = $feedback->previewBehaviorAdjustment($item);

        $this->assertSame('insufficient_sample', $preview['status']);
        $this->assertSame(9, $preview['samples']);
        $this->assertSame(10, $preview['denominator_min']);
        $this->assertSame(0.55, $item->fresh()->confidence);
    }

    public function test_behavior_confidence_formula_is_pure_and_directional(): void
    {
        $feedback = app(OperatorProfileFeedbackService::class);

        $accepted = $feedback->confidenceFromBehavior(0.50, accepted: 12, overrides: 0, ignored: 0);
        $acceptedAgain = $feedback->confidenceFromBehavior(0.50, accepted: 12, overrides: 0, ignored: 0);
        $rejected = $feedback->confidenceFromBehavior(0.50, accepted: 0, overrides: 6, ignored: 6);

        $this->assertSame($accepted, $acceptedAgain);
        $this->assertGreaterThan(0.50, $accepted);
        $this->assertLessThan(0.50, $rejected);
    }

    public function test_apply_and_revert_restores_confidence_byte_comparable(): void
    {
        $item = $this->profileItem(confidence: 0.50);
        $feedback = app(OperatorProfileFeedbackService::class);
        for ($i = 0; $i < 12; $i++) {
            $feedback->record($item, 'accepted');
        }

        $applied = $feedback->applyBehaviorAdjustment($item);

        $this->assertSame('applied', $applied['status']);
        $this->assertSame(0.50, $applied['previous_confidence']);
        $this->assertGreaterThan(0.50, $item->fresh()->confidence);
        $this->assertIsString($applied['reverse_handle']);

        $reverted = $feedback->revertBehaviorAdjustment($applied['reverse_handle']);

        $this->assertSame('reverted', $reverted['status']);
        $this->assertSame(0.50, $item->fresh()->confidence);
    }

    public function test_digest_reports_declared_vs_behavior_curve_without_persisting_snapshot(): void
    {
        $item = $this->profileItem(confidence: 0.40);
        $feedback = app(OperatorProfileFeedbackService::class);
        for ($i = 0; $i < 10; $i++) {
            $feedback->record($item, $i < 8 ? 'accepted' : 'ignored');
        }

        $digest = app(OperatorProfileDigestService::class)->digest('operator-1', persistSnapshot: false);

        $curve = $digest['behavior_confidence_curve'][0] ?? null;
        $this->assertIsArray($curve);
        $this->assertSame($item->id, $curve['item_id']);
        $this->assertSame('ready', $curve['status']);
        $this->assertSame(0.40, $curve['declared_confidence']);
        $this->assertGreaterThan(0.40, $curve['behavior_confidence']);
        $this->assertArrayNotHasKey('snapshot_id', $digest);
    }

    private function profileItem(float $confidence): OperatorProfileItem
    {
        return OperatorProfileItem::query()->create([
            'operator_id' => 'operator-1',
            'taxonomy_item_id' => 'style.concise',
            'profile_key' => 'style.concise',
            'value' => ['preference' => 'concise'],
            'summary' => 'Prefers concise responses.',
            'scope_type' => 'global',
            'validity_kind' => 'permanent',
            'confidence' => $confidence,
            'privacy_class' => 'normal',
            'automation_level' => 'observe',
            'status' => OperatorProfileItem::STATUS_ACTIVE,
        ]);
    }
}
