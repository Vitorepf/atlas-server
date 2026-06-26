<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\FinalOperatorClosureCorridor;

use App\Services\Ai\SelfConstruction\FinalOperatorClosureCorridor\OperatorCommandSurfaceIntegrityInspector;
use Tests\TestCase;

class OperatorCommandSurfaceIntegrityInspectorTest extends TestCase
{
    public function test_collect_operator_commands_finds_self_construction_commands(): void
    {
        $payload = [
            'cmd1' => 'php artisan atlas:ai:self-construction --status',
            'other' => 'some unrelated string',
            'cmd2' => 'php artisan atlas:ai:self-construction --completion-audit',
        ];

        $commands = OperatorCommandSurfaceIntegrityInspector::collectOperatorCommands($payload);

        self::assertCount(2, $commands);
        self::assertStringContainsString('payload.cmd1', array_keys($commands)[0]);
        self::assertStringContainsString('payload.cmd2', array_keys($commands)[1]);
    }

    public function test_collect_operator_commands_is_recursive(): void
    {
        $payload = [
            'nested' => [
                'deep' => 'php artisan atlas:ai:self-construction --preview',
            ],
        ];

        $commands = OperatorCommandSurfaceIntegrityInspector::collectOperatorCommands($payload);

        self::assertCount(1, $commands);
        self::assertStringContainsString('payload.nested.deep', array_keys($commands)[0]);
    }

    public function test_collect_operator_commands_ignores_non_matching(): void
    {
        $payload = ['cmd' => 'php artisan other:command', 'str' => 'random text', 'num' => 42];

        self::assertSame([], OperatorCommandSurfaceIntegrityInspector::collectOperatorCommands($payload));
    }

    public function test_unique_commands_by_text_deduplicates(): void
    {
        $commands = [
            'path.a' => 'php artisan atlas:ai:self-construction --status',
            'path.b' => 'php artisan atlas:ai:self-construction --status',
            'path.c' => 'php artisan atlas:ai:self-construction --preview',
        ];

        $unique = OperatorCommandSurfaceIntegrityInspector::uniqueCommandsByText($commands);

        self::assertCount(2, $unique);
        self::assertArrayHasKey('path.a', $unique);
        self::assertArrayNotHasKey('path.b', $unique);
        self::assertArrayHasKey('path.c', $unique);
    }

    public function test_extract_command_options_parses_flags(): void
    {
        $cmd = 'php artisan atlas:ai:self-construction --status --json --workspace=/tmp';

        $options = OperatorCommandSurfaceIntegrityInspector::extractCommandOptions($cmd);

        self::assertContains('json', $options);
        self::assertContains('status', $options);
        self::assertContains('workspace', $options);
    }

    public function test_extract_command_options_deduplicates(): void
    {
        $cmd = 'php artisan atlas:ai:self-construction --status --status --json';

        $options = OperatorCommandSurfaceIntegrityInspector::extractCommandOptions($cmd);

        $statusCount = array_count_values($options)['status'] ?? 0;
        self::assertSame(1, $statusCount);
    }

    public function test_extract_command_options_returns_sorted(): void
    {
        $cmd = 'php artisan atlas:ai:self-construction --zebra --apple --mango';

        $options = OperatorCommandSurfaceIntegrityInspector::extractCommandOptions($cmd);

        self::assertSame(['apple', 'mango', 'zebra'], $options);
    }

    public function test_extract_command_options_returns_empty_for_no_options(): void
    {
        self::assertSame([], OperatorCommandSurfaceIntegrityInspector::extractCommandOptions('php artisan atlas:ai:self-construction'));
    }

    public function test_self_construction_command_options_returns_list(): void
    {
        $options = OperatorCommandSurfaceIntegrityInspector::selfConstructionCommandOptions();

        self::assertIsArray($options);
        // Should be sorted
        $sorted = $options;
        sort($sorted);
        self::assertSame($sorted, $options);
    }

    public function test_legacy_aliases_returns_expected_list(): void
    {
        $aliases = OperatorCommandSurfaceIntegrityInspector::legacySelfConstructionCommandAliases();

        self::assertContains('runtime-gap-matrix', $aliases);
        self::assertContains('runtime-promotion-receipt-draft', $aliases);
        self::assertContains('operator-evidence-submission-readiness', $aliases);
    }

    public function test_integrity_returns_expected_structure(): void
    {
        $result = OperatorCommandSurfaceIntegrityInspector::integrity([]);

        self::assertArrayHasKey('schema_version', $result);
        self::assertArrayHasKey('status', $result);
        self::assertArrayHasKey('command_count', $result);
        self::assertArrayHasKey('commands', $result);
        self::assertArrayHasKey('command_surface_integrity_hash', $result);
        self::assertArrayHasKey('non_execution_guarantees', $result);
        self::assertFalse($result['can_execute_commands_from_integrity_check']);
    }

    public function test_integrity_detects_legacy_alias(): void
    {
        $surface = [
            'cmd' => 'php artisan atlas:ai:self-construction --runtime-gap-matrix',
        ];

        $result = OperatorCommandSurfaceIntegrityInspector::integrity($surface);

        self::assertSame('command_surface_attention_required', $result['status']);
        self::assertGreaterThan(0, $result['legacy_alias_count']);
        self::assertFalse($result['legacy_alias_free']);
    }

    public function test_integrity_passes_for_clean_surface(): void
    {
        $surface = [
            'cmd' => 'php artisan atlas:ai:self-construction --json',
        ];

        $result = OperatorCommandSurfaceIntegrityInspector::integrity($surface);

        self::assertSame('command_surface_aligned', $result['status']);
    }

    public function test_integrity_is_deterministic(): void
    {
        $surface = ['cmd' => 'php artisan atlas:ai:self-construction --json'];

        $a = OperatorCommandSurfaceIntegrityInspector::integrity($surface);
        $b = OperatorCommandSurfaceIntegrityInspector::integrity($surface);

        self::assertSame($a['command_surface_integrity_hash'], $b['command_surface_integrity_hash']);
    }
}
