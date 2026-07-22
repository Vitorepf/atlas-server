<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\PauseResume\AtlasLoopCyclePauseFlag;
use Tests\TestCase;

class AtlasLoopCyclePauseFlagTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/atlas-pause-'.bin2hex(random_bytes(6)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    public function test_raise_writes_sentinel_with_all_required_fields(): void
    {
        $flag = new AtlasLoopCyclePauseFlag($this->path);
        $row = $flag->raise('cyc-1', 'implement', 'human-stop', '2026-06-25T00:00:00Z');

        foreach (['cycle_id', 'phase', 'reason', 'raised_at', 'sentinel_hash', 'schema_version'] as $k) {
            self::assertArrayHasKey($k, $row);
        }
        self::assertTrue($flag->isRaised());
        self::assertSame('cyc-1', $row['cycle_id']);
    }

    public function test_inspect_returns_raised_payload_then_lower_clears_it(): void
    {
        $flag = new AtlasLoopCyclePauseFlag($this->path);
        $flag->raise('cyc-2', 'verify', 'operator-pause', '2026-06-25T00:00:01Z');

        $inspected = $flag->inspect();
        self::assertNotNull($inspected);
        self::assertSame('cyc-2', $inspected['cycle_id']);

        self::assertTrue($flag->lower());
        self::assertFalse($flag->isRaised());
        self::assertNull($flag->inspect());
    }

    public function test_corrupted_sentinel_is_rejected_by_inspect(): void
    {
        $flag = new AtlasLoopCyclePauseFlag($this->path);
        $flag->raise('cyc-3', 'implement', 'r', '2026-06-25T00:00:02Z');

        // Tamper with the file: change reason but keep the stored sentinel_hash.
        $raw = json_decode((string) file_get_contents($this->path), true);
        $raw['reason'] = 'malicious';
        file_put_contents($this->path, json_encode($raw, JSON_UNESCAPED_SLASHES));

        self::assertNull($flag->inspect());
    }

    public function test_hash_is_byte_stable_for_identical_inputs(): void
    {
        $a = new AtlasLoopCyclePauseFlag($this->path);
        $rowA = $a->raise('cyc-X', 'comprehend', 'same', '2026-06-25T00:00:03Z');
        $a->lower();
        $rowB = $a->raise('cyc-X', 'comprehend', 'same', '2026-06-25T00:00:03Z');

        self::assertSame($rowA['sentinel_hash'], $rowB['sentinel_hash']);
    }

    public function test_lower_on_absent_sentinel_returns_false_and_does_not_throw(): void
    {
        $flag = new AtlasLoopCyclePauseFlag($this->path);
        self::assertFalse($flag->lower());
        self::assertFalse($flag->isRaised());
    }

    public function test_returned_payload_has_no_aggregate_health_score_keys(): void
    {
        $flag = new AtlasLoopCyclePauseFlag($this->path);
        $row = $flag->raise('cyc-4', 'verify', 'r', '2026-06-25T00:00:04Z');
        foreach (['score', 'health', 'rating', 'grade', 'quality'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $row);
        }
    }
}
