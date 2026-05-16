<?php

declare(strict_types=1);

namespace Tests\Unit\Expiration;

use App\Domain\Expiration\ExpirationService;
use App\Support\Clock\FrozenClock;
use PHPUnit\Framework\TestCase;

final class ExpirationServiceTest extends TestCase
{
    public function test_fifty_consecutive_runs_have_no_flake_under_frozen_clock(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $clock = new FrozenClock(1_700_000_000.0);
            $service = new ExpirationService(clock: $clock); // post-patch shape

            $this->assertFalse($service->isExpired(1_700_000_010.0), "iter {$i}: should not be expired yet");
            $clock->advance(11);
            $this->assertTrue($service->isExpired(1_700_000_010.0), "iter {$i}: should be expired after advance");
        }
    }

    public function test_service_does_not_call_real_microtime(): void
    {
        $source = file_get_contents(__DIR__.'/ExpirationService.php');
        $this->assertNotFalse($source);
        $this->assertStringNotContainsString('microtime(', $source, 'ExpirationService must not reference microtime() directly');
    }
}
