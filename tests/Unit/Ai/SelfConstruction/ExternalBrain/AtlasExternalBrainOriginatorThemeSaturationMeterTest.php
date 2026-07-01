<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOriginatorThemeSaturationMeter;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainOriginatorThemeSaturationMeterTest extends TestCase
{
    private AtlasExternalBrainOriginatorThemeSaturationMeter $meter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->meter = new AtlasExternalBrainOriginatorThemeSaturationMeter();
    }

    // AC: repeated directory + design_path → saturation_high even when labels differ
    public function test_repeated_directory_and_design_path_is_saturated(): void
    {
        $result = $this->meter->measure([
            ['theme_label' => 'Theme-A', 'allowed_files' => ['app/ExternalBrain/X.php'], 'design_path' => 'budget_allocator'],
            ['theme_label' => 'Theme-B', 'allowed_files' => ['app/ExternalBrain/Y.php'], 'design_path' => 'budget_allocator'],
            ['theme_label' => 'Theme-C', 'allowed_files' => ['app/ExternalBrain/Z.php'], 'design_path' => 'budget_allocator'],
        ]);

        $this->assertSame('saturation_high', $result['saturation']);
    }

    // AC: new prerequisite unlock prevents false saturation
    public function test_prerequisite_unlock_prevents_saturation(): void
    {
        $result = $this->meter->measure([
            ['theme_label' => 'Theme-A', 'allowed_files' => ['app/ExternalBrain/X.php'], 'design_path' => 'budget_allocator'],
            ['theme_label' => 'Theme-B', 'allowed_files' => ['app/ExternalBrain/Y.php'], 'design_path' => 'budget_allocator'],
            ['theme_label' => 'Theme-C', 'allowed_files' => ['app/ExternalBrain/Z.php'], 'design_path' => 'budget_allocator', 'prerequisite_unlock' => true],
        ]);

        $this->assertSame('saturation_low', $result['saturation']);
    }

    // AC: output includes pivot_plan with forbidden_directories, forbidden_design_paths, recommended_next_theme
    public function test_pivot_plan_included_when_saturated(): void
    {
        $result = $this->meter->measure([
            ['theme_label' => 'A', 'allowed_files' => ['app/ExternalBrain/X.php'], 'design_path' => 'path1'],
            ['theme_label' => 'B', 'allowed_files' => ['app/ExternalBrain/Y.php'], 'design_path' => 'path1'],
            ['theme_label' => 'C', 'allowed_files' => ['app/ExternalBrain/Z.php'], 'design_path' => 'path1'],
        ]);

        $this->assertArrayHasKey('pivot_plan', $result);
        $this->assertNotEmpty($result['pivot_plan']['forbidden_directories']);
        $this->assertNotEmpty($result['pivot_plan']['forbidden_design_paths']);
        $this->assertNotNull($result['pivot_plan']['recommended_next_theme']);
    }

    public function test_different_directories_not_saturated(): void
    {
        $result = $this->meter->measure([
            ['allowed_files' => ['app/Brain/A.php'], 'design_path' => 'path1'],
            ['allowed_files' => ['app/Cortex/B.php'], 'design_path' => 'path2'],
        ]);

        $this->assertSame('saturation_low', $result['saturation']);
    }

    public function test_empty_batches_not_saturated(): void
    {
        $result = $this->meter->measure([]);

        $this->assertSame('saturation_low', $result['saturation']);
    }
}
