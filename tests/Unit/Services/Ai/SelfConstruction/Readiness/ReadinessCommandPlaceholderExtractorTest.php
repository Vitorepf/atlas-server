<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Readiness;

use App\Services\Ai\SelfConstruction\Readiness\ReadinessCommandPlaceholderExtractor;
use Tests\TestCase;

/**
 * Readiness/operator-handoff commands cannot hide malformed placeholders that later strand
 * muscles: unique placeholder extraction, nested and unterminated placeholder detection,
 * clean commands with no warnings, and deterministic warning strings — pure parser, no I/O.
 */
final class ReadinessCommandPlaceholderExtractorTest extends TestCase
{
    public function test_extracts_unique_placeholders_in_order(): void
    {
        $fields = ReadinessCommandPlaceholderExtractor::fieldsFromCommand(
            'atlas:task report --client=<client> --task=<task> --client=<client>'
        );

        self::assertSame(['<client>', '<task>'], $fields);
    }

    public function test_normal_command_without_placeholders_has_no_warnings(): void
    {
        $result = ReadinessCommandPlaceholderExtractor::inspect('atlas:task:next --json');

        self::assertSame([], $result['placeholders']);
        self::assertFalse($result['nested_found']);
        self::assertFalse($result['unterminated_found']);
        self::assertSame([], $result['warnings']);
    }

    public function test_nested_placeholder_produces_a_warning_without_executing_anything(): void
    {
        $result = ReadinessCommandPlaceholderExtractor::inspect('cmd --opt=<outer <inner>>');

        self::assertTrue($result['nested_found']);
        self::assertStringContainsString('nested placeholder', $result['warnings'][0]);
    }

    public function test_unterminated_placeholder_produces_a_warning_without_executing_anything(): void
    {
        $result = ReadinessCommandPlaceholderExtractor::inspect('atlas:task:report --client=<client> --lease=<nope');

        self::assertTrue($result['unterminated_found']);
        self::assertStringContainsString('unterminated', $result['warnings'][0]);
    }

    public function test_warning_strings_are_deterministic_for_the_same_command(): void
    {
        $cmd = 'cmd --opt=<outer <inner>> --unclosed=<nope';

        $a = ReadinessCommandPlaceholderExtractor::inspect($cmd);
        $b = ReadinessCommandPlaceholderExtractor::inspect($cmd);

        self::assertSame($a['warnings'], $b['warnings']);
    }
}
