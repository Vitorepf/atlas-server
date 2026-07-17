<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AcosMax;

use App\Services\Ai\AcosMax\AcosMeasureSeriesFreshnessReader;
use PHPUnit\Framework\TestCase;

final class AcosMeasureSeriesFreshnessReaderTest extends TestCase
{
    public function test_jsonl_file_returns_latest_recorded_at(): void
    {
        $path = sys_get_temp_dir().'/acos-freshness-'.bin2hex(random_bytes(4)).'.jsonl';
        file_put_contents($path, implode("\n", [
            json_encode(['recorded_at' => '2026-07-01T00:00:00Z']),
            json_encode(['recorded_at' => '2026-07-10T12:00:00Z']),
            json_encode(['recorded_at' => '2026-07-05T00:00:00Z']),
        ])."\n");

        try {
            $latest = (new AcosMeasureSeriesFreshnessReader)->lastAppendAt([
                'source_type' => 'jsonl',
                'path' => $path,
                'timestamp_field' => 'recorded_at',
            ]);

            $this->assertNotNull($latest);
            $this->assertSame('2026-07-10T12:00:00+00:00', $latest->toIso8601String());
        } finally {
            @unlink($path);
        }
    }

    public function test_missing_path_returns_null(): void
    {
        $latest = (new AcosMeasureSeriesFreshnessReader)->lastAppendAt([
            'source_type' => 'jsonl',
            'path' => '/tmp/does-not-exist-'.bin2hex(random_bytes(4)).'.jsonl',
        ]);

        $this->assertNull($latest);
    }
}
