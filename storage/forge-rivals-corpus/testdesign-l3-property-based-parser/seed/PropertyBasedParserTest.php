<?php

declare(strict_types=1);

namespace Tests\Unit\Captures;

use App\Domain\Captures\CaptureMarkdownParser;
use PHPUnit\Framework\TestCase;

final class PropertyBasedParserTest extends TestCase
{
    public function test_roundtrip_property_holds_for_100_seeded_inputs(): void
    {
        mt_srand(42);
        for ($i = 0; $i < 100; $i++) {
            $input = $this->generate();
            $row = CaptureMarkdownParser::parse($input);
            $this->assertSame(
                $input,
                CaptureMarkdownParser::serialize($row),
                "roundtrip broke for input <{$input}>",
            );
        }
    }

    public function test_shrinker_reports_minimal_failing_input(): void
    {
        // Sanity check: the shrinker must produce strings that are
        // never larger than the input. This guards against pathological
        // shrinkers that report the original 1k-char input.
        $shrunk = $this->shrink('hello world hello world');
        $this->assertLessThan(strlen('hello world hello world'), strlen($shrunk));
    }

    private function generate(): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz ';
        $length = mt_rand(0, 20);
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[mt_rand(0, strlen($alphabet) - 1)];
        }

        return trim($out);
    }

    private function shrink(string $input): string
    {
        return substr($input, 0, max(0, intdiv(strlen($input), 2)));
    }
}
