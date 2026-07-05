<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\OperatorEvidence;

use App\Services\Ai\SelfConstruction\OperatorEvidence\OperatorCommandSurfaceIntegrityAnalyzer;
use Tests\TestCase;

class OperatorCommandSurfaceIntegrityAnalyzerTest extends TestCase
{
    // ── collectOperatorCommands ────────────────────────────────────────

    public function test_collect_operator_commands_recurses_into_nested_arrays(): void
    {
        $payload = [
            'level_one' => [
                'level_two' => [
                    'cmd' => 'php artisan atlas:ai:self-construction --json',
                ],
            ],
        ];
        $commands = OperatorCommandSurfaceIntegrityAnalyzer::collectOperatorCommands($payload);

        self::assertCount(1, $commands);
        self::assertStringContainsString('level_one.level_two', (string) array_key_first($commands));
    }

    public function test_collect_operator_commands_is_deterministically_key_sorted(): void
    {
        $payload = [
            'z_cmd' => 'php artisan atlas:ai:self-construction --z',
            'a_cmd' => 'php artisan atlas:ai:self-construction --a',
            'm_cmd' => 'php artisan atlas:ai:self-construction --m',
        ];
        $a = OperatorCommandSurfaceIntegrityAnalyzer::collectOperatorCommands($payload);
        $b = OperatorCommandSurfaceIntegrityAnalyzer::collectOperatorCommands($payload);

        self::assertSame(array_keys($a), array_keys($b));
        self::assertSame(['payload.a_cmd', 'payload.m_cmd', 'payload.z_cmd'], array_keys($a));
    }

    // ── extractCommandOptions ──────────────────────────────────────────

    public function test_extract_command_options_parses_and_sorts_all_flags(): void
    {
        $options = OperatorCommandSurfaceIntegrityAnalyzer::extractCommandOptions(
            'php artisan atlas:ai:self-construction --json --workspace=/tmp --dry-run',
        );

        self::assertContains('json', $options);
        self::assertContains('workspace', $options);
        self::assertContains('dry-run', $options);
        self::assertSame($options, array_values(array_unique($options)));
        // Must be sorted
        $sorted = $options;
        sort($sorted);
        self::assertSame($sorted, $options);
    }

    // ── integrity: legacy aliases ──────────────────────────────────────

    public function test_integrity_flags_legacy_alias_option_and_lists_it_in_details(): void
    {
        $surface = ['cmd' => 'php artisan atlas:ai:self-construction --runtime-gap-matrix'];
        $result = OperatorCommandSurfaceIntegrityAnalyzer::integrity($surface);

        self::assertSame('command_surface_attention_required', $result['status']);
        self::assertGreaterThan(0, $result['legacy_alias_count']);
        self::assertNotEmpty($result['legacy_aliases_detected']);
        self::assertStringContainsString('runtime-gap-matrix', (string) json_encode($result['legacy_aliases_detected']));
    }

    // ── integrity: unknown options ─────────────────────────────────────

    public function test_integrity_flags_unknown_option_not_in_command_definition_or_legacy_list(): void
    {
        $surface = ['cmd' => 'php artisan atlas:ai:self-construction --bogus-option-xyz'];
        $result = OperatorCommandSurfaceIntegrityAnalyzer::integrity($surface);

        self::assertSame('command_surface_attention_required', $result['status']);
        self::assertGreaterThan(0, $result['missing_option_count']);
        self::assertStringContainsString('bogus-option-xyz', (string) json_encode($result['missing_options']));
    }

    public function test_integrity_does_not_flag_options_present_in_command_definition(): void
    {
        $surface = ['cmd' => 'php artisan atlas:ai:self-construction --json'];
        $result = OperatorCommandSurfaceIntegrityAnalyzer::integrity($surface);

        // --json is a known option of the atlas:ai:self-construction command
        self::assertSame(0, $result['missing_option_count'], 'known options must not be flagged');
    }

    // ── integrity: steady-state dependency blockers ────────────────────

    public function test_ordinary_runtime_nested_path_creates_steady_state_dependency_blocker(): void
    {
        $payload = [
            'ordinary_runtime' => [
                'cmd' => 'php artisan atlas:ai:self-construction --json',
            ],
        ];
        $result = OperatorCommandSurfaceIntegrityAnalyzer::integrity($payload);

        self::assertSame('command_surface_attention_required', $result['status']);
        self::assertFalse($result['steady_state_dependency_free']);
        self::assertGreaterThan(0, $result['steady_state_dependency_blocker_count']);
        self::assertSame('steady_state_operator_dependency', $result['steady_state_dependency_blockers'][0]['blocker']);
        self::assertStringContainsString('ordinary_runtime', $result['steady_state_dependency_blockers'][0]['payload_path']);
    }

    public function test_non_ordinary_runtime_path_never_creates_steady_state_blocker(): void
    {
        $payload = [
            'bootstrap' => [
                'cmd' => 'php artisan atlas:ai:self-construction --json',
            ],
        ];
        $result = OperatorCommandSurfaceIntegrityAnalyzer::integrity($payload);

        self::assertSame('command_surface_aligned', $result['status']);
        self::assertTrue($result['steady_state_dependency_free']);
        self::assertSame(0, $result['steady_state_dependency_blocker_count']);
    }

    // ── integrity: non-execution guarantees ────────────────────────────

    public function test_integrity_never_executes_or_persists_and_declares_all_non_execution_guarantees(): void
    {
        $result = OperatorCommandSurfaceIntegrityAnalyzer::integrity([]);

        self::assertFalse($result['can_execute_commands_from_integrity_check']);
        self::assertFalse($result['can_persist_from_integrity_check']);
        self::assertArrayHasKey('non_execution_guarantees', $result);
        self::assertNotEmpty($result['non_execution_guarantees']);
        self::assertContains(
            'operator_command_surface_integrity_does_not_run_operator_commands',
            $result['non_execution_guarantees'],
        );
        self::assertContains(
            'operator_command_surface_integrity_does_not_persist_evidence',
            $result['non_execution_guarantees'],
        );
    }

    // ── integrity: hash stability and sensitivity ──────────────────────

    public function test_command_surface_integrity_hash_is_a_deterministic_64_char_hex_string(): void
    {
        $result = OperatorCommandSurfaceIntegrityAnalyzer::integrity(['cmd' => 'php artisan atlas:ai:self-construction --json']);

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['command_surface_integrity_hash']);
    }

    public function test_command_surface_integrity_hash_differs_for_different_payloads(): void
    {
        $a = OperatorCommandSurfaceIntegrityAnalyzer::integrity(['cmd' => 'php artisan atlas:ai:self-construction --json']);
        $b = OperatorCommandSurfaceIntegrityAnalyzer::integrity(['cmd' => 'php artisan atlas:ai:self-construction --help']);

        self::assertNotSame($a['command_surface_integrity_hash'], $b['command_surface_integrity_hash']);
    }
}
