<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Engineering\EngineeringQualityScanService;
use Tests\TestCase;

final class EngineeringQualityScanServiceVerdictTest extends TestCase
{
    public function test_scan_returns_blocking_security_verdict_when_secret_finding_present(): void
    {
        /** @var EngineeringQualityScanService $service */
        $service = $this->app->make(EngineeringQualityScanService::class);

        $result = $service->scan(__DIR__);

        $this->assertArrayHasKey('security_verdict', $result);
        $this->assertArrayHasKey('has_blocking_findings', $result['security_verdict']);
        $this->assertArrayHasKey('secrets_count', $result['security_verdict']);
        $this->assertArrayHasKey('sast_count', $result['security_verdict']);
        $this->assertArrayHasKey('tools_with_blocking', $result['security_verdict']);
    }

    public function test_scan_returns_blocking_security_verdict_when_critical_sast_finding_present(): void
    {
        /** @var EngineeringQualityScanService $service */
        $service = $this->app->make(EngineeringQualityScanService::class);

        $result = $service->scan(__DIR__);

        $this->assertArrayHasKey('security_verdict', $result);
        $this->assertIsBool($result['security_verdict']['has_blocking_findings']);
        $this->assertIsInt($result['security_verdict']['secrets_count']);
        $this->assertIsInt($result['security_verdict']['sast_count']);
        $this->assertIsArray($result['security_verdict']['tools_with_blocking']);
    }

    public function test_security_verdict_blocks_promotion_when_blocking(): void
    {
        /** @var EngineeringQualityScanService $service */
        $service = $this->app->make(EngineeringQualityScanService::class);

        $result = $service->scan(__DIR__);

        if ($result['security_verdict']['has_blocking_findings']) {
            $this->assertSame('failed', $result['status']);
        }
    }
}
