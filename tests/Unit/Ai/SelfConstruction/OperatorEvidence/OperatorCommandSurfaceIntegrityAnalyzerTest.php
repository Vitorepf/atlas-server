<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\OperatorEvidence;

use App\Services\Ai\SelfConstruction\OperatorEvidence\OperatorCommandSurfaceIntegrityAnalyzer;
use Tests\TestCase;

class OperatorCommandSurfaceIntegrityAnalyzerTest extends TestCase
{
    public function test_collect_operator_commands_finds_commands(): void
    {
        $payload = ['cmd' => 'php artisan atlas:ai:self-construction --json'];
        $commands = OperatorCommandSurfaceIntegrityAnalyzer::collectOperatorCommands($payload);

        self::assertCount(1, $commands);
    }

    public function test_collect_operator_commands_ignores_non_matching(): void
    {
        $payload = ['cmd' => 'some other command', 'num' => 42];
        self::assertSame([], OperatorCommandSurfaceIntegrityAnalyzer::collectOperatorCommands($payload));
    }

    public function test_extract_command_options_parses_flags(): void
    {
        $options = OperatorCommandSurfaceIntegrityAnalyzer::extractCommandOptions('php artisan atlas:ai:self-construction --json --workspace=/tmp');

        self::assertContains('json', $options);
        self::assertContains('workspace', $options);
    }

    public function test_extract_command_options_sorted(): void
    {
        $options = OperatorCommandSurfaceIntegrityAnalyzer::extractCommandOptions('--zebra --apple --mango');

        self::assertSame(['apple', 'mango', 'zebra'], $options);
    }

    public function test_legacy_aliases_returns_expected_list(): void
    {
        $aliases = OperatorCommandSurfaceIntegrityAnalyzer::legacySelfConstructionCommandAliases();

        self::assertContains('runtime-gap-matrix', $aliases);
        self::assertContains('operator-evidence-submission-readiness', $aliases);
    }

    public function test_integrity_returns_expected_structure(): void
    {
        $result = OperatorCommandSurfaceIntegrityAnalyzer::integrity([]);

        self::assertArrayHasKey('schema_version', $result);
        self::assertArrayHasKey('status', $result);
        self::assertArrayHasKey('command_surface_integrity_hash', $result);
        self::assertFalse($result['can_execute_commands_from_integrity_check']);
    }

    public function test_integrity_detects_legacy_alias(): void
    {
        $surface = ['cmd' => 'php artisan atlas:ai:self-construction --runtime-gap-matrix'];
        $result = OperatorCommandSurfaceIntegrityAnalyzer::integrity($surface);

        self::assertSame('command_surface_attention_required', $result['status']);
        self::assertGreaterThan(0, $result['legacy_alias_count']);
    }

    public function test_integrity_passes_for_clean_surface(): void
    {
        $surface = ['cmd' => 'php artisan atlas:ai:self-construction --json'];
        $result = OperatorCommandSurfaceIntegrityAnalyzer::integrity($surface);

        self::assertSame('command_surface_aligned', $result['status']);
    }

    public function test_integrity_is_deterministic(): void
    {
        $a = OperatorCommandSurfaceIntegrityAnalyzer::integrity(['cmd' => 'php artisan atlas:ai:self-construction --json']);
        $b = OperatorCommandSurfaceIntegrityAnalyzer::integrity(['cmd' => 'php artisan atlas:ai:self-construction --json']);

        self::assertSame($a['command_surface_integrity_hash'], $b['command_surface_integrity_hash']);
    }

    public function test_self_construction_command_options_returns_sorted_list(): void
    {
        $options = OperatorCommandSurfaceIntegrityAnalyzer::selfConstructionCommandOptions();

        self::assertIsArray($options);
        $sorted = $options;
        sort($sorted);
        self::assertSame($sorted, $options);
    }
}
