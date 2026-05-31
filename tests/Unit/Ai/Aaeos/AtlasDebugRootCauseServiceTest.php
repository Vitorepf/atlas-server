<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos;

use App\Services\Ai\Aaeos\AtlasDebugRootCauseService;
use PHPUnit\Framework\TestCase;

final class AtlasDebugRootCauseServiceTest extends TestCase
{
    private AtlasDebugRootCauseService $service;

    protected function setUp(): void
    {
        $this->service = new AtlasDebugRootCauseService();
    }

    public function testGetVersionReturnsCorrectServiceVersion(): void
    {
        $version = $this->service->getVersion();

        $this->assertSame('atlas.aaeos.debug.root_cause.v1', $version);
    }

    public function testAnalyzeRootCauseReturnsExpectedStructure(): void
    {
        $context = ['suspected_cause' => 'test_cause'];

        $result = $this->service->analyzeRootCause($context);

        $this->assertArrayHasKey('version', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('context', $result);
        $this->assertArrayHasKey('root_cause', $result);
        $this->assertSame('atlas.aaeos.debug.root_cause.v1', $result['version']);
        $this->assertSame('analyzed', $result['status']);
        $this->assertSame($context, $result['context']);
    }

    public function testAnalyzeRootCauseWithEmptyContext(): void
    {
        $result = $this->service->analyzeRootCause([]);

        $this->assertSame('no_data', $result['root_cause']);
    }

    public function testAnalyzeRootCauseWithMissingSuspectedCause(): void
    {
        $context = ['other_field' => 'value'];

        $result = $this->service->analyzeRootCause($context);

        $this->assertSame('unknown', $result['root_cause']);
    }
}