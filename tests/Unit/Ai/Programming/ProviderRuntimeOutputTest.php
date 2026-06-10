<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming;

use App\Services\Ai\Programming\ProviderRuntimeOutput;
use Tests\TestCase;

final class ProviderRuntimeOutputTest extends TestCase
{
    public function test_redacts_default_secret_shapes(): void
    {
        $output = ProviderRuntimeOutput::redact('sk-123456789 api_key=secret bearer abc.def');

        $this->assertSame('sk-***redacted*** api_key=***redacted*** bearer ***redacted***', $output);
    }

    public function test_redacts_provider_specific_secret_shapes(): void
    {
        $output = ProviderRuntimeOutput::redact(
            'cursor_api_key=cursor-secret',
            ['/(cursor[_-]?(?:api[_-]?)?key["\']?\s*[:=]\s*["\']?)[^"\'\s,]+/i'],
        );

        $this->assertSame('cursor_api_key=***redacted***', $output);
    }

    public function test_excerpt_truncates_only_when_needed(): void
    {
        $this->assertSame('abc', ProviderRuntimeOutput::excerpt('abc', 3));
        $this->assertSame('abc...', ProviderRuntimeOutput::excerpt('abcdef', 3));
    }
}
