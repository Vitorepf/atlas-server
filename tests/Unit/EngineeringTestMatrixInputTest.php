<?php

namespace Tests\Unit;

use App\Services\Engineering\EngineeringTestMatrixInput;
use Tests\TestCase;

class EngineeringTestMatrixInputTest extends TestCase
{
    public function test_normalizes_engineering_test_matrix_limits(): void
    {
        $input = new EngineeringTestMatrixInput;

        config([
            'atlas.engineering.quality_scan.timeout_seconds' => 99999,
            'atlas.engineering.visual_e2e.managed_smoke_timeout_seconds' => 99999,
            'atlas.engineering.visual_e2e.artifact_max_files' => 99999,
            'atlas.engineering.visual_e2e.artifact_max_bytes' => 9999999999,
            'atlas.engineering.quality_scan.artifact_max_files' => 99999,
            'atlas.engineering.quality_scan.artifact_max_bytes' => 9999999999,
        ]);

        $this->assertSame(EngineeringTestMatrixInput::MAX_QUALITY_SCAN_TIMEOUT_SECONDS, $input->qualityScanTimeoutSeconds());
        $this->assertSame(EngineeringTestMatrixInput::MAX_VISUAL_SMOKE_TIMEOUT_SECONDS, $input->visualSmokeTimeoutSeconds());
        $this->assertSame(EngineeringTestMatrixInput::MAX_VISUAL_ARTIFACT_MAX_FILES, $input->visualArtifactMaxFiles());
        $this->assertSame(EngineeringTestMatrixInput::MAX_VISUAL_ARTIFACT_MAX_BYTES, $input->visualArtifactMaxBytes());
        $this->assertSame(EngineeringTestMatrixInput::MAX_QUALITY_ARTIFACT_MAX_FILES, $input->qualityArtifactMaxFiles());
        $this->assertSame(EngineeringTestMatrixInput::MAX_QUALITY_ARTIFACT_MAX_BYTES, $input->qualityArtifactMaxBytes());
        $this->assertSame(10, $input->qualityScanTimeoutSeconds(-10));
        $this->assertSame(EngineeringTestMatrixInput::DEFAULT_QUALITY_SCAN_TIMEOUT_SECONDS, $input->qualityScanTimeoutSeconds('bad'));
    }
}
