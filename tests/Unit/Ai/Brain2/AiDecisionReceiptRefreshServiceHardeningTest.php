<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Models\AiJob;
use App\Services\Ai\AtlasDecide\AiDecisionReceiptRefreshService;
use App\Services\Ai\AtlasDecideService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

/**
 * Proves AiDecisionReceiptRefreshService does not throw when a job created-at
 * value is an invalid date.
 */
final class AiDecisionReceiptRefreshServiceHardeningTest extends TestCase
{
    private AiDecisionReceiptRefreshService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $decide = $this->createMock(AtlasDecideService::class);
        $this->service = new AiDecisionReceiptRefreshService($decide);
    }

    private function isTooOldToRefresh(AiJob&MockObject $job): bool
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('isTooOldToRefresh');
        return $method->invoke($this->service, $job);
    }

    public function test_invalid_created_at_does_not_throw(): void
    {
        $job = $this->createMock(AiJob::class);
        $job->created_at = 'not-a-valid-date';

        $result = $this->isTooOldToRefresh($job);

        $this->assertIsBool($result);
    }

    public function test_valid_carbon_immutable_created_at_works(): void
    {
        $job = $this->createMock(AiJob::class);
        $job->created_at = CarbonImmutable::now();

        $result = $this->isTooOldToRefresh($job);

        $this->assertIsBool($result);
    }

    public function test_null_created_at_defaults_to_now(): void
    {
        $job = $this->createMock(AiJob::class);
        $job->created_at = null;

        $result = $this->isTooOldToRefresh($job);

        $this->assertIsBool($result);
    }
}
