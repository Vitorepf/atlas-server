<?php

namespace Tests\Unit;

use App\Services\Digital\DigitalActivityQuality;
use Tests\TestCase;

class DigitalActivityQualityTest extends TestCase
{
    public function test_rize_snapshot_without_classification_cannot_be_high_confidence(): void
    {
        $payload = app(DigitalActivityQuality::class)->enrichPayload([
            'source' => 'atlas_server',
            'signal_count' => 12,
            'total_screen_time_min' => 620,
            'metadata' => [],
        ]);

        $this->assertLessThanOrEqual(55, data_get($payload, 'metadata.quality.score'));
        $this->assertSame('low', data_get($payload, 'metadata.quality.status'));
        $this->assertContains('no_category_classification', data_get($payload, 'metadata.quality.warnings'));
        $this->assertContains('native_iphone_source_absent', data_get($payload, 'metadata.quality.warnings'));
        $this->assertContains('iphone_interruptions_incomplete', data_get($payload, 'metadata.quality.warnings'));
    }

    public function test_native_screentime_snapshot_with_classification_and_interruptions_can_be_high_confidence(): void
    {
        $payload = app(DigitalActivityQuality::class)->enrichPayload([
            'source' => 'screentime',
            'signal_count' => 22,
            'total_screen_time_min' => 300,
            'pickups_count' => 30,
            'first_offensive_use_min_after_wake' => 92,
            'notifications_received' => 44,
            'notifications_actioned' => 12,
            'curated_input_min' => 120,
            'algorithmic_input_min' => 45,
            'intentional_entertainment_min' => 30,
            'default_entertainment_min' => 20,
            'communication_primary_min' => 50,
            'communication_shallow_min' => 25,
            'market_min' => 10,
            'metadata' => [
                'native' => [
                    'source' => 'ios_screentime',
                    'entitlement' => 'family_controls',
                ],
            ],
        ]);

        $this->assertGreaterThanOrEqual(90, data_get($payload, 'metadata.quality.score'));
        $this->assertSame('high', data_get($payload, 'metadata.quality.status'));
        $this->assertTrue(data_get($payload, 'metadata.quality.has_native_iphone_source'));
        $this->assertSame(1.0, data_get($payload, 'metadata.coverage.classification_ratio'));
    }

    public function test_consistency_errors_reject_impossible_snapshot_math(): void
    {
        $errors = app(DigitalActivityQuality::class)->consistencyErrors([
            'total_screen_time_min' => 60,
            'notifications_received' => 3,
            'notifications_actioned' => 8,
            'curated_input_min' => 80,
            'algorithmic_input_min' => 25,
            'first_offensive_use_min_after_wake' => 1500,
        ]);

        $this->assertArrayHasKey('notifications_actioned', $errors);
        $this->assertArrayHasKey('category_breakdown', $errors);
        $this->assertArrayHasKey('first_offensive_use_min_after_wake', $errors);
    }
}
