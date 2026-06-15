<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasLoopKeepaliveCommand;
use Tests\TestCase;

/**
 * REGRESSION (drift-recycle bug #1): the keepalive read supervisor boot time via `ps -o lstart=`
 * (locale wall-clock, NO offset token) and strtotime'd it under the artisan-forced UTC runtime,
 * skewing boot epoch by the host's UTC offset (3h on a -0300 host). The git anchor (%ct) is true
 * UTC, so a genuinely-fresh supervisor read as "stale" and would be recycled EVERY cadence the
 * moment ATLAS_LOOP_RESTART_ON_CODE_DRIFT flips on — the opposite of self-healing.
 *
 * The fix anchors on `ps -o etime=` ELAPSED time (now − elapsed), which is timezone-free by
 * construction. These freeze that: the parser is exact across formats AND yields the SAME epoch
 * regardless of the process timezone (the property the lstart path violated).
 */
final class AtlasLoopKeepaliveBootEpochTest extends TestCase
{
    public function test_parses_mm_ss(): void
    {
        // 5m30s ago.
        $this->assertSame(1_000_000 - 330, AtlasLoopKeepaliveCommand::bootEpochFromEtime('05:30', 1_000_000));
    }

    public function test_parses_hh_mm_ss(): void
    {
        // 6h30m55s ago.
        $this->assertSame(1_000_000 - 23455, AtlasLoopKeepaliveCommand::bootEpochFromEtime('06:30:55', 1_000_000));
    }

    public function test_parses_days_hh_mm_ss(): void
    {
        // 2d3h4m5s ago.
        $this->assertSame(1_000_000 - 183845, AtlasLoopKeepaliveCommand::bootEpochFromEtime('2-03:04:05', 1_000_000));
        // multi-digit day field.
        $this->assertSame(1_000_000 - (12 * 86400), AtlasLoopKeepaliveCommand::bootEpochFromEtime('12-00:00:00', 1_000_000));
    }

    public function test_malformed_input_fails_safe_to_null(): void
    {
        foreach (['', '   ', 'garbage', '99', 'a:b', '1:2:3:4', '05:99', '99:00', '-04:00:00', 'x-01:02:03'] as $bad) {
            $this->assertNull(AtlasLoopKeepaliveCommand::bootEpochFromEtime($bad, 1_000_000), "[$bad] must fail safe to null");
        }
    }

    public function test_boot_epoch_is_timezone_independent(): void
    {
        // The crux of bug #1: the SAME elapsed string must yield the SAME boot epoch no matter the
        // process timezone. lstart+strtotime violated this; elapsed-based parsing cannot.
        $original = date_default_timezone_get();
        try {
            $results = [];
            foreach (['UTC', 'America/Sao_Paulo', 'Asia/Tokyo', 'America/Los_Angeles'] as $tz) {
                date_default_timezone_set($tz);
                $results[$tz] = AtlasLoopKeepaliveCommand::bootEpochFromEtime('06:30:55', 1_000_000);
            }
            $this->assertSame(
                [1_000_000 - 23455],
                array_values(array_unique($results)),
                'boot epoch must be identical across timezones (was 3h-skewed under the lstart bug)',
            );
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function test_fresh_supervisor_just_after_a_commit_is_not_stale(): void
    {
        // End-to-end of the bug: a supervisor booted 30s AFTER an engine commit must NOT be flagged.
        // Under the lstart bug its epoch was ~10800s in the past → commit > boot → false recycle.
        $now = 1_781_500_000;
        $commitEpoch = $now - 600;          // engine commit 10 min ago
        $bootEpoch = AtlasLoopKeepaliveCommand::bootEpochFromEtime('09:30', $now); // booted 9m30s ago = 30s AFTER commit
        $this->assertNotNull($bootEpoch);
        $this->assertGreaterThan($commitEpoch, $bootEpoch, 'a supervisor booted after the commit is fresh, not stale');
    }
}
