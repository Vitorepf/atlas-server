<?php

namespace Tests\Unit;

use App\Services\Engineering\EngineeringDockerHarnessInput;
use Tests\TestCase;

class EngineeringDockerHarnessInputTest extends TestCase
{
    public function test_normalizes_engineering_docker_harness_limits(): void
    {
        $input = new EngineeringDockerHarnessInput;

        config([
            'atlas.engineering.docker.healthcheck_timeout_seconds' => 9999,
            'atlas.engineering.docker.artifact_max_files' => 9999,
            'atlas.engineering.docker.artifact_max_bytes' => 999999999,
            'atlas.engineering.docker.cleanup.cache_retention_days' => 9999,
            'atlas.engineering.docker.cleanup.artifact_retention_days' => 9999,
        ]);

        $this->assertSame(EngineeringDockerHarnessInput::MAX_HEALTHCHECK_TIMEOUT_SECONDS, $input->healthcheckTimeoutSeconds());
        $this->assertSame(EngineeringDockerHarnessInput::MAX_ARTIFACT_MAX_FILES, $input->artifactMaxFiles());
        $this->assertSame(EngineeringDockerHarnessInput::MAX_ARTIFACT_MAX_BYTES, $input->artifactMaxBytes());
        $this->assertSame(EngineeringDockerHarnessInput::MAX_CACHE_RETENTION_DAYS, $input->cacheRetentionDays());
        $this->assertSame(EngineeringDockerHarnessInput::MAX_ARTIFACT_RETENTION_DAYS, $input->artifactRetentionDays());
        $this->assertSame(1, $input->artifactMaxFiles(-10));
        $this->assertSame(EngineeringDockerHarnessInput::DEFAULT_ARTIFACT_MAX_FILES, $input->artifactMaxFiles('bad'));
    }
}
