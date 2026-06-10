<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusJsonFileReader;
use Tests\TestCase;

final class AreaFocusJsonFileReaderTest extends TestCase
{
    public function test_returns_null_for_missing_and_invalid_json_files(): void
    {
        $this->assertNull(AreaFocusJsonFileReader::object(sys_get_temp_dir().'/missing-atlas-json-file-reader.json'));

        $path = tempnam(sys_get_temp_dir(), 'atlas_json_file_reader_invalid_');
        $this->assertIsString($path);

        try {
            file_put_contents($path, '{not-json');

            $this->assertNull(AreaFocusJsonFileReader::object($path));
        } finally {
            @unlink($path);
        }
    }

    public function test_returns_decoded_json_object(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'atlas_json_file_reader_valid_');
        $this->assertIsString($path);

        try {
            file_put_contents($path, json_encode(['run_id' => 'run_1'], JSON_THROW_ON_ERROR));

            $this->assertSame(['run_id' => 'run_1'], AreaFocusJsonFileReader::object($path));
        } finally {
            @unlink($path);
        }
    }
}
