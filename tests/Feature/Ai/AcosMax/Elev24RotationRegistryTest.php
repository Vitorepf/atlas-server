<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AcosMax;

use App\Services\Ai\AcosMax\AcosMaxLedgerRotationRegistry;
use App\Services\Ai\AcosMax\AcosMaxMeasureSeriesRegistry;
use App\Services\Ai\Cognition\Watchdog\Checks\DiskFreeWatchdogCheck;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class Elev24RotationRegistryTest extends TestCase
{
    #[Test]
    public function every_elev20s_series_declares_a_rotation_policy(): void
    {
        $series = (new AcosMaxMeasureSeriesRegistry())->seriesIds();
        $rotation = new AcosMaxLedgerRotationRegistry();

        $missing = [];
        foreach ($series as $seriesId) {
            if ($rotation->policyFor($seriesId) === null) {
                $missing[] = $seriesId;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'ELEV-24 architectural guard: series without a rotation policy = '.implode(', ', $missing)
        );
    }

    #[Test]
    public function rotation_policy_shape_is_strict(): void
    {
        $rotation = new AcosMaxLedgerRotationRegistry();

        foreach ($rotation->all() as $seriesId => $policy) {
            $this->assertIsInt($policy['max_size_mb'], "series {$seriesId} missing max_size_mb");
            $this->assertIsInt($policy['max_age_days'], "series {$seriesId} missing max_age_days");
            $this->assertContains($policy['mode'], [
                'append_forever', 'rotate_size', 'rotate_age', 'rotate_hybrid',
            ]);
        }
    }

    #[Test]
    public function hash_chain_ledger_is_append_forever(): void
    {
        $policy = (new AcosMaxLedgerRotationRegistry())
            ->policyFor('atlas.evidence_ledger.hash_chain.v1');

        $this->assertNotNull($policy);
        $this->assertSame('append_forever', $policy['mode']);
    }

    #[Test]
    public function disk_free_watchdog_alerts_and_flags_background_pause_when_below_floor(): void
    {
        $probe = static fn (): array => [
            'path' => '/tmp/atlas-elev24-simulated',
            'free_bytes' => 1 * (1024 ** 3),
            'total_bytes' => 100 * (1024 ** 3),
        ];

        $check = new DiskFreeWatchdogCheck(
            $probe,
            CarbonImmutable::parse('2026-07-12T00:00:00+00:00'),
            5,
        );
        $result = $check->run()->toArray();

        $this->assertSame('alert', $result['status']);
        $this->assertSame('disk_below_floor', $result['alert']['code']);
        $this->assertTrue($result['evidence']['background_should_pause']);
        $this->assertTrue($check->isBelowFloor());
    }

    #[Test]
    public function disk_free_watchdog_ok_when_ample_headroom(): void
    {
        $probe = static fn (): array => [
            'path' => '/tmp/atlas-elev24-simulated',
            'free_bytes' => 80 * (1024 ** 3),
            'total_bytes' => 100 * (1024 ** 3),
        ];

        $check = new DiskFreeWatchdogCheck(
            $probe,
            CarbonImmutable::parse('2026-07-12T00:00:00+00:00'),
            5,
        );
        $result = $check->run()->toArray();

        $this->assertSame('ok', $result['status']);
        $this->assertFalse($result['evidence']['background_should_pause']);
        $this->assertFalse($check->isBelowFloor());
    }
}
