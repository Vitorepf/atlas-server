<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\OperatorEvidence;

use App\Services\Ai\SelfConstruction\OperatorEvidence\OperatorCommandSurfaceIntegrityAnalyzer;
use Tests\TestCase;

final class OperatorCommandSurfaceIntegrityAnalyzerTest extends TestCase
{
    // ── recursive command collection ────────────────────────────────────────────

    public function test_collect_operator_commands_recurses_into_nested_arrays(): void
    {
        $payload = [
            'outer' => [
                'inner' => [
                    'cmd' => 'php artisan atlas:ai:self-construction --json',
                ],
            ],
        ];

        $commands = OperatorCommandSurfaceIntegrityAnalyzer::collectOperatorCommands($payload);

        self::assertCount(1, $commands);
        self::assertArrayHasKey('payload.outer.inner.cmd', $commands);
    }

    public function test_collect_operator_commands_is_deterministically_key_sorted(): void
    {
        $payload = [
            'zeta' => 'php artisan atlas:ai:self-construction --json',
            'alpha' => 'php artisan atlas:ai:self-construction --json',
        ];

        $commands = OperatorCommandSurfaceIntegrityAnalyzer::collectOperatorCommands($payload);

        self::assertSame(['payload.alpha', 'payload.zeta'], array_keys($commands));
    }

    // ── option extraction ────────────────────────────────────────────────────────

    public function test_extract_command_options_parses_and_sorts_all_flags(): void
    {
        $options = OperatorCommandSurfaceIntegrityAnalyzer::extractCommandOptions(
            'php artisan atlas:ai:self-construction --json --workspace=/tmp --dry-run'
        );

        self::assertSame(['dry-run', 'json', 'workspace'], $options);
    }

    // ── legacy alias detection ───────────────────────────────────────────────────

    public function test_integrity_flags_legacy_alias_option_and_lists_it_in_details(): void
    {
        $payload = ['cmd' => 'php artisan atlas:ai:self-construction --runtime-gap-matrix'];

        $result = OperatorCommandSurfaceIntegrityAnalyzer::integrity($payload);

        self::assertFalse($result['legacy_alias_free']);
        self::assertSame(1, $result['legacy_alias_count']);
        self::assertSame('runtime-gap-matrix', $result['legacy_aliases_detected'][0]['option']);
    }

    // ── unknown option detection ─────────────────────────────────────────────────

    public function test_integrity_flags_unknown_option_not_in_command_definition_or_legacy_list(): void
    {
        $payload = ['cmd' => 'php artisan atlas:ai:self-construction --totally-unknown-flag'];

        $result = OperatorCommandSurfaceIntegrityAnalyzer::integrity($payload);

        self::assertSame('command_surface_attention_required', $result['status']);
        self::assertGreaterThan(0, $result['missing_option_count']);
        self::assertSame('totally-unknown-flag', $result['missing_options'][0]['option']);
    }

    public function test_integrity_does_not_flag_options_present_in_command_definition(): void
    {
        $knownOptions = OperatorCommandSurfaceIntegrityAnalyzer::selfConstructionCommandOptions();
        self::assertNotEmpty($knownOptions, 'atlas:ai:self-construction must be registered for this test to be meaningful');

        $payload = ['cmd' => 'php artisan atlas:ai:self-construction --'.$knownOptions[0]];

        $result = OperatorCommandSurfaceIntegrityAnalyzer::integrity($payload);

        self::assertSame(0, $result['missing_option_count']);
    }

    // ── ordinary-runtime steady-state dependency blocking ────────────────────────

    public function test_ordinary_runtime_nested_path_creates_steady_state_dependency_blocker(): void
    {
        $payload = [
            'ordinary_runtime' => [
                'nested' => [
                    'cmd' => 'php artisan atlas:ai:self-construction --json',
                ],
            ],
        ];

        $result = OperatorCommandSurfaceIntegrityAnalyzer::integrity($payload);

        self::assertFalse($result['steady_state_dependency_free']);
        self::assertSame(1, $result['steady_state_dependency_blocker_count']);
        self::assertSame('steady_state_operator_dependency', $result['steady_state_dependency_blockers'][0]['blocker']);
    }

    public function test_non_ordinary_runtime_path_never_creates_steady_state_blocker(): void
    {
        $payload = ['bootstrap' => ['cmd' => 'php artisan atlas:ai:self-construction --json']];

        $result = OperatorCommandSurfaceIntegrityAnalyzer::integrity($payload);

        self::assertSame([], $result['steady_state_dependency_blockers']);
    }

    // ── non-execution guarantees ──────────────────────────────────────────────────

    public function test_integrity_never_executes_or_persists_and_declares_all_non_execution_guarantees(): void
    {
        $result = OperatorCommandSurfaceIntegrityAnalyzer::integrity([
            'cmd' => 'php artisan atlas:ai:self-construction --json',
        ]);

        self::assertFalse($result['can_execute_commands_from_integrity_check']);
        self::assertFalse($result['can_persist_from_integrity_check']);
        self::assertSame([
            'operator_command_surface_integrity_does_not_run_operator_commands',
            'operator_command_surface_integrity_does_not_persist_evidence',
            'operator_command_surface_integrity_does_not_call_provider',
            'operator_command_surface_integrity_does_not_spend_tokens',
            'operator_command_surface_integrity_does_not_dispatch_work',
            'operator_command_surface_integrity_does_not_promote_completion',
        ], $result['non_execution_guarantees']);
    }

    // ── deterministic hash ────────────────────────────────────────────────────────

    public function test_command_surface_integrity_hash_is_a_deterministic_64_char_hex_string(): void
    {
        $result = OperatorCommandSurfaceIntegrityAnalyzer::integrity([
            'cmd' => 'php artisan atlas:ai:self-construction --json',
        ]);

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['command_surface_integrity_hash']);
    }

    public function test_command_surface_integrity_hash_differs_for_different_payloads(): void
    {
        $a = OperatorCommandSurfaceIntegrityAnalyzer::integrity(['cmd' => 'php artisan atlas:ai:self-construction --json']);
        $b = OperatorCommandSurfaceIntegrityAnalyzer::integrity(['cmd' => 'php artisan atlas:ai:self-construction --runtime-gap-matrix']);

        self::assertNotSame($a['command_surface_integrity_hash'], $b['command_surface_integrity_hash']);
    }
}
