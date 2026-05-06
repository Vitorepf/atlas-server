<?php

namespace Tests\Unit\Ai\Runtime;

use App\Services\Ai\Runtime\TestCommandInput;
use Tests\TestCase;

class TestCommandInputTest extends TestCase
{
    public function test_normalizes_test_command_memory_limit_with_canonical_default(): void
    {
        $input = new TestCommandInput;

        config(['atlas.ai.test_memory_limit' => '2048M']);

        $this->assertSame('2048M', $input->memoryLimit());
        $this->assertSame('512M', $input->memoryLimit('512M'));
        $this->assertSame('256k', $input->memoryLimit('256k'));
        $this->assertSame(TestCommandInput::DEFAULT_MEMORY_LIMIT, $input->memoryLimit('1G; rm -rf /'));
        $this->assertSame(TestCommandInput::DEFAULT_MEMORY_LIMIT, $input->memoryLimit('bad'));
    }
}
