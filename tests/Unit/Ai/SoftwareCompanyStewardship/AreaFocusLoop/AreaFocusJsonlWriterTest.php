<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusJsonlReader;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusJsonlWriter;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class AreaFocusJsonlWriterTest extends TestCase
{
    public function test_append_creates_parent_directory_and_adds_one_json_line(): void
    {
        $path = storage_path('framework/testing/area-focus-jsonl-writer/append/nested.jsonl');
        File::delete($path);

        AreaFocusJsonlWriter::append($path, ['schema_version' => 'schema.v1', 'id' => 'one']);

        $this->assertSame(
            [['schema_version' => 'schema.v1', 'id' => 'one']],
            AreaFocusJsonlReader::rows($path),
        );
    }

    public function test_rewrite_replaces_existing_jsonl_with_current_records(): void
    {
        $path = storage_path('framework/testing/area-focus-jsonl-writer/rewrite/queue.jsonl');
        File::delete($path);

        AreaFocusJsonlWriter::append($path, ['schema_version' => 'schema.v1', 'id' => 'old']);
        AreaFocusJsonlWriter::rewrite($path, [
            ['schema_version' => 'schema.v1', 'id' => 'new-1'],
            ['schema_version' => 'schema.v1', 'id' => 'new-2'],
        ]);

        $this->assertSame(
            [
                ['schema_version' => 'schema.v1', 'id' => 'new-1'],
                ['schema_version' => 'schema.v1', 'id' => 'new-2'],
            ],
            AreaFocusJsonlReader::rows($path),
        );
    }
}
