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

    public function test_read_and_encode_roundtrip(): void
    {
        $path = sys_get_temp_dir().'/atlas-acos-rt-'.uniqid('', true).'.jsonl';
        $line = AcosDeltaSeriesJsonl::encodeLine(['date' => '2026-07-02', 'n' => 3]);
        file_put_contents($path, $line."\n");
        $series = AcosDeltaSeriesJsonl::readSeries($path);
        $this->assertIsArray($series);
        $this->assertCount(1, $series);
        $this->assertSame(3, $series[0]['n']);
        @unlink($path);
    }
}
