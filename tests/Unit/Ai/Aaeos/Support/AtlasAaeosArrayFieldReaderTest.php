<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Support;

use App\Services\Ai\Aaeos\Support\AtlasAaeosArrayFieldReader;
use PHPUnit\Framework\TestCase;

final class AtlasAaeosArrayFieldReaderTest extends TestCase
{
    public function test_string_field_preserves_core_ranker_contract(): void
    {
        $this->assertSame('alpha', AtlasAaeosArrayFieldReader::stringField(['id' => 'alpha'], 'id'));
        $this->assertSame('42', AtlasAaeosArrayFieldReader::stringField(['id' => 42], 'id'));
        $this->assertSame('4.2', AtlasAaeosArrayFieldReader::stringField(['id' => 4.2], 'id'));
        $this->assertSame('', AtlasAaeosArrayFieldReader::stringField(['id' => true], 'id'));
        $this->assertSame('', AtlasAaeosArrayFieldReader::stringField([], 'id'));
    }
}
