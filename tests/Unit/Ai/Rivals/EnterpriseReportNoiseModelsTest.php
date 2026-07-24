<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\Rivals\Core\EnterpriseReportNoiseModels;
use PHPUnit\Framework\TestCase;

final class EnterpriseReportNoiseModelsTest extends TestCase
{
    public function test_noise_ids(): void
    {
        $this->assertTrue(EnterpriseReportNoiseModels::isNoise('mockllm'));
        $this->assertFalse(EnterpriseReportNoiseModels::isNoise('claude-opus'));
    }
}
