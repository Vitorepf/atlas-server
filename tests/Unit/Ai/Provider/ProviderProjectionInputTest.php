<?php

namespace Tests\Unit\Ai\Provider;

use App\Services\Ai\Provider\ProviderProjectionInput;
use Tests\TestCase;

class ProviderProjectionInputTest extends TestCase
{
    public function test_normalizes_provider_projection_limits_with_canonical_caps(): void
    {
        $input = new ProviderProjectionInput;

        config([
            'atlas.ai.provider_projection_max_lines' => 9999,
            'atlas.ai.provider_projection_memory_limit' => 9999,
            'atlas.ai.provider_projection_memory_chars' => 9999,
        ]);

        $this->assertSame(ProviderProjectionInput::MAX_MAX_LINES, $input->maxLines());
        $this->assertSame(ProviderProjectionInput::MAX_MEMORY_LIMIT, $input->memoryLimit());
        $this->assertSame(ProviderProjectionInput::MAX_MEMORY_CHARS, $input->memoryChars());
        $this->assertSame(ProviderProjectionInput::MIN_MAX_LINES, $input->maxLines(-10));
        $this->assertSame(1, $input->memoryLimit(-10));
        $this->assertSame(ProviderProjectionInput::MIN_MEMORY_CHARS, $input->memoryChars(-10));
        $this->assertSame(ProviderProjectionInput::DEFAULT_MAX_LINES, $input->maxLines('bad'));
    }
}
