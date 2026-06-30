<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOriginatorThemeSaturationMeter;
use Tests\TestCase;

final class AtlasExternalBrainOriginatorThemeSaturationMeterTest extends TestCase
{
    private AtlasExternalBrainOriginatorThemeSaturationMeter $meter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->meter = new AtlasExternalBrainOriginatorThemeSaturationMeter;
    }

    private function task(string $theme, array $overrides = []): array
    {
        return array_merge([
            'theme' => $theme,
            'target_family' => 'external_brain',
            'capability_type' => 'contract',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/ExternalBrain/Foo.php'],
            'new_prerequisite_unlock' => false,
            'distinct_impact_class' => 'same_class',
        ], $overrides);
    }

    public function test_groups_by_theme_target_family_capability_type_and_directory(): void
    {
        $result = $this->meter->measure([
            $this->task('local_clients'),
            $this->task('local_clients'),
            $this->task('provider_pools', ['target_family' => 'provider_pool', 'capability_type' => 'gate', 'allowed_files' => ['app/Other/Bar.php']]),
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorThemeSaturationMeter::SCHEMA, $result['schema']);
        $this->assertSame(3, $result['total_tasks']);
        $this->assertSame(['local_clients' => 2, 'provider_pools' => 1], $result['theme_counts']);
        $this->assertSame(['external_brain' => 2, 'provider_pool' => 1], $result['target_family_counts']);
        $this->assertSame(['contract' => 2, 'gate' => 1], $result['capability_type_counts']);
        $this->assertArrayHasKey('app/Services/Ai/SelfConstruction/ExternalBrain', $result['allowed_file_directory_counts']);
        $this->assertArrayHasKey('app/Other', $result['allowed_file_directory_counts']);
    }

    public function test_saturation_ratio_reflects_dominant_theme_share(): void
    {
        $result = $this->meter->measure([
            $this->task('local_clients'),
            $this->task('local_clients'),
            $this->task('local_clients'),
            $this->task('provider_pools'),
        ]);

        $this->assertSame('local_clients', $result['dominant_theme']);
        $this->assertSame(0.75, $result['saturation_ratio']);
    }

    public function test_repeated_theme_without_novelty_is_saturation_high(): void
    {
        $result = $this->meter->measure([
            $this->task('local_clients'),
            $this->task('local_clients'),
            $this->task('local_clients'),
        ], ['saturation_threshold' => 0.6]);

        $this->assertTrue($result['saturation_high']);
        $this->assertContains('local_clients', $result['overrepresented_themes']);
        $this->assertSame($result['overrepresented_themes'], $result['forbidden_next_themes']);
    }

    public function test_repeated_theme_with_new_prerequisite_unlock_is_not_saturation_high(): void
    {
        $result = $this->meter->measure([
            $this->task('local_clients'),
            $this->task('local_clients'),
            $this->task('local_clients', ['new_prerequisite_unlock' => true]),
        ], ['saturation_threshold' => 0.6]);

        $this->assertFalse($result['saturation_high']);
    }

    public function test_repeated_theme_with_distinct_impact_classes_is_not_saturation_high(): void
    {
        $result = $this->meter->measure([
            $this->task('local_clients', ['distinct_impact_class' => 'class_a']),
            $this->task('local_clients', ['distinct_impact_class' => 'class_b']),
            $this->task('local_clients', ['distinct_impact_class' => 'class_c']),
        ], ['saturation_threshold' => 0.6]);

        $this->assertFalse($result['saturation_high']);
    }

    public function test_balanced_themes_are_not_saturated(): void
    {
        $result = $this->meter->measure([
            $this->task('local_clients'),
            $this->task('provider_pools'),
            $this->task('queue_integrity'),
        ], ['saturation_threshold' => 0.6]);

        $this->assertFalse($result['saturation_high']);
        $this->assertSame([], $result['overrepresented_themes']);
    }

    public function test_recommends_first_unsaturated_candidate_theme(): void
    {
        $result = $this->meter->measure([
            $this->task('local_clients'),
            $this->task('local_clients'),
            $this->task('local_clients'),
        ], [
            'saturation_threshold' => 0.6,
            'candidate_next_themes' => ['local_clients', 'queue_integrity', 'provider_pools'],
        ]);

        $this->assertSame('queue_integrity', $result['recommended_next_theme']);
    }

    public function test_no_candidate_themes_yields_null_recommendation(): void
    {
        $result = $this->meter->measure([$this->task('local_clients')]);

        $this->assertNull($result['recommended_next_theme']);
    }

    public function test_empty_input_is_safe_and_not_saturated(): void
    {
        $result = $this->meter->measure([]);

        $this->assertSame(0, $result['total_tasks']);
        $this->assertNull($result['dominant_theme']);
        $this->assertSame(0.0, $result['saturation_ratio']);
        $this->assertFalse($result['saturation_high']);
        $this->assertSame([], $result['overrepresented_themes']);
    }
}
