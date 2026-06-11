<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\DurableExecution;

use App\Services\Ai\Programming\DurableExecution\DurableExecutionFieldReader;
use Tests\TestCase;

final class DurableExecutionFieldReaderTest extends TestCase
{
    public function test_string_or_null_preserves_exact_non_empty_string_contract(): void
    {
        $this->assertSame(' value ', DurableExecutionFieldReader::stringOrNull(['field' => ' value '], 'field'));
        $this->assertSame(' ', DurableExecutionFieldReader::stringOrNull(['field' => ' '], 'field'));
        $this->assertNull(DurableExecutionFieldReader::stringOrNull(['field' => ''], 'field'));
        $this->assertNull(DurableExecutionFieldReader::stringOrNull(['field' => 123], 'field'));
        $this->assertNull(DurableExecutionFieldReader::stringOrNull([], 'field'));
    }
}
