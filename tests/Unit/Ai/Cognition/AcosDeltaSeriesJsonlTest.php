<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition;

use App\Services\Ai\Cognition\Acos\AcosDeltaSeriesJsonl;
use PHPUnit\Framework\TestCase;

final class AcosDeltaSeriesJsonlTest extends TestCase
{
    public function test_appends_and_replaces_by_date(): void
    {
        $path = sys_get_temp_dir().'/atlas-acos-'.uniqid('', true).'.jsonl';
        $encode = static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR);
        $store = [];
        $read = function (string $p) use (&$store): array {
            return $store;
        };

        $series = AcosDeltaSeriesJsonl::appendSnapshot(
            $path,
            ['date' => '2026-07-01', 'v' => 1],
            $encode,
            $read,
        );
        $this->assertNotNull($series);
        $this->assertCount(1, $series);
        $store = $series;

        $series2 = AcosDeltaSeriesJsonl::appendSnapshot(
            $path,
            ['date' => '2026-07-01', 'v' => 2],
            $encode,
            fn (string $p): array => $store,
        );
        $this->assertNotNull($series2);
        $this->assertCount(1, $series2);
        $this->assertSame(2, $series2[0]['v']);

        @unlink($path);
    }
}
