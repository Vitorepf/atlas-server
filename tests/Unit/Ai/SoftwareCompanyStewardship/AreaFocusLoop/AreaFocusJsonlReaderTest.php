<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusJsonlReader;
use Tests\TestCase;

final class AreaFocusJsonlReaderTest extends TestCase
{
    public function test_rows_ignores_missing_file_and_malformed_lines(): void
    {
        $missing = sys_get_temp_dir().'/missing-atlas-jsonl-reader-rows.jsonl';
        $this->assertSame([], AreaFocusJsonlReader::rows($missing));

        $path = tempnam(sys_get_temp_dir(), 'atlas_jsonl_reader_rows_');
        $this->assertIsString($path);

        try {
            file_put_contents($path, implode("\n", [
                json_encode(['schema_version' => 'one', 'id' => 'A'], JSON_THROW_ON_ERROR),
                '{not-json',
                json_encode(['schema_version' => 'two', 'id' => 'B'], JSON_THROW_ON_ERROR),
            ]));

            $this->assertSame(['A', 'B'], array_column(AreaFocusJsonlReader::rows($path), 'id'));
        } finally {
            @unlink($path);
        }
    }

    public function test_rows_with_schema_version_filters_valid_rows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'atlas_jsonl_reader_schema_');
        $this->assertIsString($path);

        try {
            file_put_contents($path, implode("\n", [
                json_encode(['schema_version' => 'target', 'id' => 'A'], JSON_THROW_ON_ERROR),
                json_encode(['schema_version' => 'other', 'id' => 'B'], JSON_THROW_ON_ERROR),
                json_encode(['schema_version' => ['target'], 'id' => 'C'], JSON_THROW_ON_ERROR),
                json_encode(['id' => 'D'], JSON_THROW_ON_ERROR),
            ]));

            $this->assertSame(['A'], array_column(AreaFocusJsonlReader::rowsWithSchemaVersion($path, 'target'), 'id'));
        } finally {
            @unlink($path);
        }
    }

    public function test_stream_rows_with_schema_version_filters_valid_rows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'atlas_jsonl_reader_stream_schema_');
        $this->assertIsString($path);

        try {
            file_put_contents($path, implode("\n", [
                json_encode(['schema_version' => 'target', 'id' => 'A'], JSON_THROW_ON_ERROR),
                '',
                '{not-json',
                json_encode(['schema_version' => 'other', 'id' => 'B'], JSON_THROW_ON_ERROR),
                json_encode(['schema_version' => 'target', 'id' => 'C'], JSON_THROW_ON_ERROR),
            ]));

            $rows = iterator_to_array(AreaFocusJsonlReader::streamRowsWithSchemaVersion($path, 'target'));

            $this->assertSame(['A', 'C'], array_column($rows, 'id'));
        } finally {
            @unlink($path);
        }
    }

    public function test_rows_with_schema_version_and_sequence_preserves_physical_line_index(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'atlas_jsonl_reader_schema_sequence_');
        $this->assertIsString($path);

        try {
            file_put_contents($path, implode("\n", [
                json_encode(['schema_version' => 'target', 'id' => 'A'], JSON_THROW_ON_ERROR),
                '{not-json',
                json_encode(['schema_version' => 'other', 'id' => 'B'], JSON_THROW_ON_ERROR),
                json_encode(['schema_version' => 'target', 'id' => 'C'], JSON_THROW_ON_ERROR),
            ]));

            $rows = AreaFocusJsonlReader::rowsWithSchemaVersionAndSequence($path, 'target', 'line_index');

            $this->assertSame(['A', 'C'], array_column($rows, 'id'));
            $this->assertSame([0, 3], array_column($rows, 'line_index'));
        } finally {
            @unlink($path);
        }
    }

    public function test_rows_with_present_key_accepts_any_non_null_value(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'atlas_jsonl_reader_present_');
        $this->assertIsString($path);

        try {
            file_put_contents($path, implode("\n", [
                json_encode(['cycle_id' => 'C1'], JSON_THROW_ON_ERROR),
                json_encode(['cycle_id' => 2], JSON_THROW_ON_ERROR),
                json_encode(['cycle_id' => null], JSON_THROW_ON_ERROR),
                json_encode(['other' => 'C4'], JSON_THROW_ON_ERROR),
                '{not-json',
            ]));

            $this->assertSame(['C1', 2], array_column(AreaFocusJsonlReader::rowsWithPresentKey($path, 'cycle_id'), 'cycle_id'));
        } finally {
            @unlink($path);
        }
    }

    public function test_latest_row_with_schema_value_returns_last_valid_match(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'atlas_jsonl_reader_latest_');
        $this->assertIsString($path);

        try {
            file_put_contents($path, implode("\n", [
                json_encode(['schema_version' => 'target', 'sandbox_id' => 'S1', 'version' => 1], JSON_THROW_ON_ERROR),
                json_encode(['schema_version' => 'other', 'sandbox_id' => 'S1', 'version' => 2], JSON_THROW_ON_ERROR),
                '{not-json',
                json_encode(['schema_version' => 'target', 'sandbox_id' => 'S2', 'version' => 3], JSON_THROW_ON_ERROR),
                json_encode(['schema_version' => 'target', 'sandbox_id' => 'S1', 'version' => 4], JSON_THROW_ON_ERROR),
            ]));

            $row = AreaFocusJsonlReader::latestRowWithSchemaValue($path, 'target', 'sandbox_id', 'S1');

            $this->assertIsArray($row);
            $this->assertSame(4, $row['version']);
            $this->assertNull(AreaFocusJsonlReader::latestRowWithSchemaValue($path, 'target', 'sandbox_id', ''));
            $this->assertNull(AreaFocusJsonlReader::latestRowWithSchemaValue($path, 'target', 'sandbox_id', 'missing'));
        } finally {
            @unlink($path);
        }
    }

    public function test_missing_file_returns_empty_rows_and_zero_corruption(): void
    {
        $this->assertSame([[], 0], AreaFocusJsonlReader::rowsWithStringKey(sys_get_temp_dir().'/missing-atlas-jsonl-reader.jsonl', 'slice_id'));
    }

    public function test_reads_rows_with_required_string_key_and_counts_corruption(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'atlas_jsonl_reader_');
        $this->assertIsString($path);

        try {
            file_put_contents($path, implode("\n", [
                json_encode(['slice_id' => 'S1', 'state' => 'delivered'], JSON_THROW_ON_ERROR),
                json_encode(['slice_id' => 42], JSON_THROW_ON_ERROR),
                '{not-json',
                json_encode(['slice_id' => 'S2'], JSON_THROW_ON_ERROR),
                '',
            ]));

            [$rows, $corrupted] = AreaFocusJsonlReader::rowsWithStringKey($path, 'slice_id');

            $this->assertSame(2, $corrupted);
            $this->assertSame(['S1', 'S2'], array_column($rows, 'slice_id'));
        } finally {
            @unlink($path);
        }
    }
}
