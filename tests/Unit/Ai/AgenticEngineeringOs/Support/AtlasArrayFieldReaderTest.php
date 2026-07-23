<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AgenticEngineeringOs\Support;

use App\Services\Ai\AgenticEngineeringOs\Support\AtlasArrayFieldReader;
use PHPUnit\Framework\TestCase;

final class AtlasArrayFieldReaderTest extends TestCase
{
    public function test_string_field_preserves_core_ranker_contract(): void
    {
        $this->assertSame('alpha', AtlasArrayFieldReader::stringField(['id' => 'alpha'], 'id'));
        $this->assertSame('42', AtlasArrayFieldReader::stringField(['id' => 42], 'id'));
        $this->assertSame('4.2', AtlasArrayFieldReader::stringField(['id' => 4.2], 'id'));
        $this->assertSame('', AtlasArrayFieldReader::stringField(['id' => true], 'id'));
        $this->assertSame('', AtlasArrayFieldReader::stringField([], 'id'));
    }
}
