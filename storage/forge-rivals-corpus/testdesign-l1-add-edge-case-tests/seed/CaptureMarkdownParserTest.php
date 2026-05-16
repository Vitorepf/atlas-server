<?php

declare(strict_types=1);

namespace Tests\Unit\Captures;

use App\Domain\Captures\CaptureMarkdownParser;
use PHPUnit\Framework\TestCase;

final class CaptureMarkdownParserTest extends TestCase
{
    public function test_happy_path_extracts_body(): void
    {
        $result = CaptureMarkdownParser::parse('hello world');
        $this->assertSame('hello world', $result['body']);
        $this->assertNull($result['json']);
    }

    // ---- ARM MUST ADD: empty, whitespace-only, emoji-only, invalid-json ----
}
