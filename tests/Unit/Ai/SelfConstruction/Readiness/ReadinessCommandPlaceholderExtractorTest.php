<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Readiness;

use App\Services\Ai\SelfConstruction\Readiness\ReadinessCommandPlaceholderExtractor;
use Tests\TestCase;

class ReadinessCommandPlaceholderExtractorTest extends TestCase
{
    public function test_extracts_single_placeholder(): void
    {
        $fields = ReadinessCommandPlaceholderExtractor::fieldsFromCommand('atlas:run --task=<task>');

        self::assertSame(['<task>'], $fields);
    }

    public function test_extracts_multiple_placeholders(): void
    {
        $fields = ReadinessCommandPlaceholderExtractor::fieldsFromCommand('atlas:run --task=<task> --lease=<lease> --client=<client>');

        self::assertSame(['<task>', '<lease>', '<client>'], $fields);
    }

    public function test_returns_empty_for_no_placeholders(): void
    {
        $fields = ReadinessCommandPlaceholderExtractor::fieldsFromCommand('atlas:status');

        self::assertSame([], $fields);
    }

    public function test_returns_empty_for_empty_string(): void
    {
        $fields = ReadinessCommandPlaceholderExtractor::fieldsFromCommand('');

        self::assertSame([], $fields);
    }

    public function test_deduplicates_repeated_placeholders(): void
    {
        $fields = ReadinessCommandPlaceholderExtractor::fieldsFromCommand('cmd --a=<x> --b=<x> --c=<x>');

        self::assertSame(['<x>'], $fields);
    }

    public function test_extracts_complex_placeholders_with_nested_content(): void
    {
        $fields = ReadinessCommandPlaceholderExtractor::fieldsFromCommand('cmd --opt=<some-thing_with.special+chars>');

        self::assertSame(['<some-thing_with.special+chars>'], $fields);
    }

    public function test_preserves_order_of_first_occurrence(): void
    {
        $fields = ReadinessCommandPlaceholderExtractor::fieldsFromCommand('cmd <b> <a> <b> <c> <a>');

        self::assertSame(['<b>', '<a>', '<c>'], $fields);
    }

    public function test_does_not_extract_empty_angle_brackets(): void
    {
        $fields = ReadinessCommandPlaceholderExtractor::fieldsFromCommand('cmd --opt=<>');

        self::assertSame([], $fields);
    }

    public function test_is_deterministic(): void
    {
        $cmd = 'atlas:run --task=<task> --lease=<lease>';
        $a = ReadinessCommandPlaceholderExtractor::fieldsFromCommand($cmd);
        $b = ReadinessCommandPlaceholderExtractor::fieldsFromCommand($cmd);

        self::assertSame($a, $b);
    }
}
