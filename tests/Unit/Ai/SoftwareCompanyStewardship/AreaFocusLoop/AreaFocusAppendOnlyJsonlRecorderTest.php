<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusAppendOnlyJsonlRecorder;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class AreaFocusAppendOnlyJsonlRecorderTest extends TestCase
{
    public function test_projected_record_does_not_write_file(): void
    {
        $path = storage_path('framework/testing/area-focus-recorder/projected.jsonl');
        File::delete($path);

        $result = AreaFocusAppendOnlyJsonlRecorder::maybeRecord(
            ['id' => 'x1'],
            false,
            $path,
            'schema.v1',
            '2026-06-10T12:00:00+00:00',
            'queue_storage_status',
        );

        $this->assertSame(['id' => 'x1', 'queue_storage_status' => 'projected'], $result);
        $this->assertFalse(File::exists($path));
    }

    public function test_recorded_payload_appends_jsonl_with_schema_and_timestamp(): void
    {
        $path = storage_path('framework/testing/area-focus-recorder/recorded.jsonl');
        File::delete($path);

        $result = AreaFocusAppendOnlyJsonlRecorder::maybeRecord(
            ['id' => 'x1', 'path' => 'app/Foo.php'],
            true,
            $path,
            'schema.v1',
            '2026-06-10T12:00:00+00:00',
            'queue_storage_status',
        );

        $this->assertSame('recorded', $result['queue_storage_status']);
        $this->assertSame('schema.v1', $result['schema_version']);
        $this->assertSame('2026-06-10T12:00:00+00:00', $result['recorded_at']);
        $this->assertSame('app/Foo.php', $result['path']);

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $this->assertIsArray($lines);
        $this->assertCount(1, $lines);

        $expectedLine = $result;
        unset($expectedLine['queue_storage_status']);

        $this->assertSame($expectedLine, json_decode($lines[0], true));
    }

    public function test_append_writes_payload_without_adding_fields(): void
    {
        $path = storage_path('framework/testing/area-focus-recorder/direct/nested.jsonl');
        File::delete($path);

        $payload = ['schema_version' => 'schema.v1', 'id' => 'x2'];

        AreaFocusAppendOnlyJsonlRecorder::append($path, $payload);

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $this->assertIsArray($lines);
        $this->assertCount(1, $lines);
        $this->assertSame($payload, json_decode($lines[0], true));
    }
}
