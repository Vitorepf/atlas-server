<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusJsonlReader;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use PHPUnit\Framework\TestCase;

/**
 * ARBOR-GRAFT W5 (LED1c) — reject-newer schema detection (additive, surfaces instead of silently dropping).
 */
final class AreaFocusJsonlReaderNewerSchemaTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/atlas-areafocus-'.bin2hex(random_bytes(4)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    public function test_flags_strictly_newer_versions_only(): void
    {
        foreach ([
            ['schema_version' => '1', 'x' => 'old'],
            ['schema_version' => '2', 'x' => 'supported'],
            ['schema_version' => '3', 'x' => 'newer'],
            ['schema_version' => '4', 'x' => 'newer2'],
        ] as $row) {
            AppendOnlyJsonlStore::append($this->path, $row);
        }

        $newer = AreaFocusJsonlReader::newerThanSupported($this->path, '2');
        sort($newer);
        $this->assertSame(['3', '4'], $newer); // older(1) and equal(2) are NOT flagged
    }

    public function test_no_newer_records_returns_empty(): void
    {
        AppendOnlyJsonlStore::append($this->path, ['schema_version' => '2', 'x' => 'a']);
        AppendOnlyJsonlStore::append($this->path, ['schema_version' => '1', 'x' => 'b']);
        $this->assertSame([], AreaFocusJsonlReader::newerThanSupported($this->path, '2'));
    }

    public function test_existing_exact_match_read_is_unchanged(): void
    {
        AppendOnlyJsonlStore::append($this->path, ['schema_version' => '2', 'x' => 'keep']);
        AppendOnlyJsonlStore::append($this->path, ['schema_version' => '3', 'x' => 'drop']);
        // byte-identical existing behaviour: exact-match still returns only the supported rows.
        $rows = AreaFocusJsonlReader::rowsWithSchemaVersion($this->path, '2');
        $this->assertCount(1, $rows);
        $this->assertSame('keep', $rows[0]['x']);
    }
}
