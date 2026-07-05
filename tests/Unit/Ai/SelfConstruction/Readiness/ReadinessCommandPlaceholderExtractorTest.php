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

    public function test_inspect_returns_required_keys_for_clean_command(): void
    {
        $result = ReadinessCommandPlaceholderExtractor::inspect('atlas:run --task=<task> --lease=<lease>');

        foreach (['placeholders', 'nested_found', 'unterminated_found', 'warnings'] as $key) {
            self::assertArrayHasKey($key, $result, "inspect must return key: {$key}");
        }
        self::assertSame(['<task>', '<lease>'], $result['placeholders']);
        self::assertFalse($result['nested_found']);
        self::assertFalse($result['unterminated_found']);
        self::assertSame([], $result['warnings']);
    }

    public function test_inspect_detects_nested_placeholder(): void
    {
        $result = ReadinessCommandPlaceholderExtractor::inspect('cmd --opt=<outer <inner>>');

        self::assertTrue($result['nested_found'], 'token whose content contains "<" must be flagged as nested');
        self::assertNotEmpty($result['warnings']);
        self::assertStringContainsString('nested placeholder', $result['warnings'][0]);
    }

    public function test_inspect_detects_unterminated_placeholder(): void
    {
        $result = ReadinessCommandPlaceholderExtractor::inspect('atlas:run --task=<task> --unclosed=<nope');

        self::assertFalse($result['nested_found']);
        self::assertTrue($result['unterminated_found'], '"<nope" without closing ">" must be flagged');
        self::assertNotEmpty($result['warnings']);
        self::assertStringContainsString('unterminated', $result['warnings'][0]);
    }

    public function test_inspect_clean_placeholders_produce_no_warnings(): void
    {
        $result = ReadinessCommandPlaceholderExtractor::inspect('atlas:task:report --client=<client> --task=<task> --client=<client>');

        self::assertFalse($result['nested_found']);
        self::assertFalse($result['unterminated_found']);
        self::assertSame(['<client>', '<task>'], $result['placeholders'], 'dedup still applies');
        self::assertSame([], $result['warnings']);
    }

    public function test_is_deterministic(): void
    {
        $cmd = 'atlas:run --task=<task> --lease=<lease>';
        $a = ReadinessCommandPlaceholderExtractor::fieldsFromCommand($cmd);
        $b = ReadinessCommandPlaceholderExtractor::fieldsFromCommand($cmd);

        self::assertSame($a, $b);
    }

    // --- findPlaceholders ---------------------------------------------

    public function test_find_placeholders_from_commands(): void
    {
        $findings = ReadinessCommandPlaceholderExtractor::findPlaceholders([
            'commands' => [
                'atlas:task report --client=<client> --task=<task>',
            ],
        ]);

        self::assertCount(2, $findings);
        self::assertSame('<client>', $findings[0]['placeholder']);
        self::assertSame('command', $findings[0]['source_kind']);
        self::assertTrue($findings[0]['blocks_readiness']);
    }

    public function test_find_placeholders_from_nested_args(): void
    {
        $findings = ReadinessCommandPlaceholderExtractor::findPlaceholders([
            'args' => [
                'exec' => 'atlas:run --lease=<lease-id>',
                'nested' => ['cmd' => 'report --task=<task>'],
            ],
        ]);

        self::assertCount(2, $findings);
        $paths = array_column($findings, 'path');
        self::assertContains('args.exec', $paths);
        self::assertContains('args.nested.cmd', $paths);
    }

    public function test_find_placeholders_from_json_payload(): void
    {
        $findings = ReadinessCommandPlaceholderExtractor::findPlaceholders([
            'json_payloads' => [
                '{"run_id":"<run-id>","status":"pending"}',
            ],
        ]);

        self::assertCount(1, $findings);
        self::assertSame('<run-id>', $findings[0]['placeholder']);
        self::assertSame('json_payload', $findings[0]['source_kind']);
    }

    public function test_find_placeholders_from_doc_snippets(): void
    {
        $findings = ReadinessCommandPlaceholderExtractor::findPlaceholders([
            'doc_snippets' => [
                'Run: php artisan report --task=<task-id>',
            ],
        ]);

        self::assertCount(1, $findings);
        self::assertSame('<task-id>', $findings[0]['placeholder']);
        self::assertSame('doc_snippet', $findings[0]['source_kind']);
    }

    public function test_find_placeholders_ignores_non_executable_command(): void
    {
        $findings = ReadinessCommandPlaceholderExtractor::findPlaceholders([
            'commands' => [
                '# atlas:run --lease=<lease>',  // commented out
                'atlas:run --client=<client>',   // real command
            ],
        ]);

        self::assertCount(1, $findings);
        self::assertSame('<client>', $findings[0]['placeholder']);
    }

    public function test_find_placeholders_ignores_non_executable_doc(): void
    {
        $findings = ReadinessCommandPlaceholderExtractor::findPlaceholders([
            'doc_snippets' => [
                'This is a non-executable example: atlas:run --lease=<lease>',
            ],
        ]);

        self::assertSame([], $findings);
    }

    public function test_find_placeholders_returns_empty_for_clean_input(): void
    {
        $findings = ReadinessCommandPlaceholderExtractor::findPlaceholders([
            'commands' => ['atlas:status'],
            'args' => ['env' => 'production'],
            'json_payloads' => ['{"status":"ok"}'],
            'doc_snippets' => ['Documentation for the tool.'],
        ]);

        self::assertSame([], $findings);
    }

    public function test_find_placeholders_each_finding_has_required_fields(): void
    {
        $findings = ReadinessCommandPlaceholderExtractor::findPlaceholders([
            'commands' => ['atlas:run --tenant=<tenant>'],
        ]);

        foreach ($findings as $f) {
            self::assertArrayHasKey('placeholder', $f);
            self::assertArrayHasKey('path', $f);
            self::assertArrayHasKey('source_kind', $f);
            self::assertArrayHasKey('blocks_readiness', $f);
            self::assertIsString($f['placeholder']);
            self::assertIsString($f['path']);
            self::assertIsString($f['source_kind']);
            self::assertIsBool($f['blocks_readiness']);
        }
    }

    public function test_find_placeholders_is_deterministic(): void
    {
        $input = [
            'commands' => ['atlas:run --task=<task>'],
            'args' => ['cmd' => '--lease=<le>'],
        ];

        $a = ReadinessCommandPlaceholderExtractor::findPlaceholders($input);
        $b = ReadinessCommandPlaceholderExtractor::findPlaceholders($input);

        self::assertSame($a, $b);
    }
}
