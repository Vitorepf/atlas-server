<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\FinalOperatorClosureCorridor;

use App\Services\Ai\SelfConstruction\FinalOperatorClosureCorridor\OperatorCommandSurfaceIntegrityInspector;
use Tests\TestCase;

/**
 * Final closure corridor: catches stale self-construction commands in operator payloads
 * before they become impossible handoffs — recursive collection, de-duplication, option
 * extraction, unknown-option blocking, legacy-alias blocking, non-execution guarantees,
 * and a deterministic integrity hash.
 */
final class OperatorCommandSurfaceIntegrityInspectorTest extends TestCase
{
    public function test_collect_operator_commands_is_recursive(): void
    {
        $payload = [
            'top' => 'php artisan atlas:ai:self-construction --status',
            'nested' => [
                'deeper' => [
                    'deepest' => 'php artisan atlas:ai:self-construction --preview',
                ],
            ],
            'noise' => 'unrelated string',
        ];

        $commands = OperatorCommandSurfaceIntegrityInspector::collectOperatorCommands($payload);

        self::assertCount(2, $commands);
        self::assertArrayHasKey('payload.top', $commands);
        self::assertArrayHasKey('payload.nested.deeper.deepest', $commands);
    }

    public function test_duplicate_commands_are_counted_once(): void
    {
        $surface = [
            'a' => 'php artisan atlas:ai:self-construction --json',
            'b' => 'php artisan atlas:ai:self-construction --json',
            'c' => 'php artisan atlas:ai:self-construction --json',
        ];

        $result = OperatorCommandSurfaceIntegrityInspector::integrity($surface);

        self::assertSame(1, $result['command_count'], 'repeated identical commands must be counted once');
    }

    public function test_extract_command_options_parses_and_sorts(): void
    {
        $options = OperatorCommandSurfaceIntegrityInspector::extractCommandOptions(
            'php artisan atlas:ai:self-construction --zebra --apple --json'
        );

        self::assertSame(['apple', 'json', 'zebra'], $options);
    }

    public function test_unknown_option_produces_attention_required(): void
    {
        $surface = [
            'cmd' => 'php artisan atlas:ai:self-construction --totally-unknown-option-xyz',
        ];

        $result = OperatorCommandSurfaceIntegrityInspector::integrity($surface);

        self::assertSame('command_surface_attention_required', $result['status']);
        self::assertGreaterThan(0, $result['missing_option_count']);
        self::assertSame('totally-unknown-option-xyz', $result['missing_options'][0]['option']);
    }

    public function test_legacy_alias_option_produces_attention_required(): void
    {
        $surface = [
            'cmd' => 'php artisan atlas:ai:self-construction --runtime-gap-matrix',
        ];

        $result = OperatorCommandSurfaceIntegrityInspector::integrity($surface);

        self::assertSame('command_surface_attention_required', $result['status']);
        self::assertFalse($result['legacy_alias_free']);
        self::assertGreaterThan(0, $result['legacy_alias_count']);
    }

    public function test_clean_known_option_surface_is_aligned(): void
    {
        $surface = ['cmd' => 'php artisan atlas:ai:self-construction --json'];

        $result = OperatorCommandSurfaceIntegrityInspector::integrity($surface);

        self::assertSame('command_surface_aligned', $result['status']);
    }

    public function test_integrity_never_executes_persists_calls_provider_or_signs(): void
    {
        $result = OperatorCommandSurfaceIntegrityInspector::integrity([
            'cmd' => 'php artisan atlas:ai:self-construction --json',
        ]);

        self::assertFalse($result['can_execute_commands_from_integrity_check']);
        self::assertFalse($result['can_persist_from_integrity_check']);
        self::assertFalse($result['can_call_provider_from_integrity_check']);
        self::assertFalse($result['can_sign_for_operator_from_integrity_check']);
        self::assertContains(
            'final_operator_closure_command_surface_integrity_does_not_run_operator_commands',
            $result['non_execution_guarantees']
        );
        self::assertContains(
            'final_operator_closure_command_surface_integrity_does_not_dispatch_work',
            $result['non_execution_guarantees']
        );
        self::assertContains(
            'final_operator_closure_command_surface_integrity_does_not_promote_completion',
            $result['non_execution_guarantees']
        );
    }

    public function test_integrity_hash_is_deterministic_for_the_same_surface(): void
    {
        $surface = ['cmd' => 'php artisan atlas:ai:self-construction --json'];

        $a = OperatorCommandSurfaceIntegrityInspector::integrity($surface);
        $b = OperatorCommandSurfaceIntegrityInspector::integrity($surface);

        self::assertSame($a['command_surface_integrity_hash'], $b['command_surface_integrity_hash']);
    }
}
