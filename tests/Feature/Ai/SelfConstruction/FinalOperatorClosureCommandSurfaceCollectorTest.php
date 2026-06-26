<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\FinalOperatorClosureCommandSurfaceCollector;
use Tests\TestCase;

final class FinalOperatorClosureCommandSurfaceCollectorTest extends TestCase
{
    public function test_collect_operator_commands_picks_only_self_construction_strings_and_ksorts_them(): void
    {
        $collector = new FinalOperatorClosureCommandSurfaceCollector;

        $value = [
            'b' => 'php artisan atlas:ai:self-construction --foo',
            'a' => 'php artisan atlas:ai:self-construction --bar',
            'c' => 'echo hello world',
            'd' => ['php artisan atlas:ai:self-construction --baz'],
        ];

        $commands = $collector->collectOperatorCommands($value, 'payload');

        $this->assertSame(
            [
                'payload.a' => 'php artisan atlas:ai:self-construction --bar',
                'payload.b' => 'php artisan atlas:ai:self-construction --foo',
                'payload.d.0' => 'php artisan atlas:ai:self-construction --baz',
            ],
            $commands
        );
        $this->assertSame(array_keys($commands), ['payload.a', 'payload.b', 'payload.d.0']);
    }

    public function test_unique_commands_by_text_dedups_by_command_text_keeping_first_path(): void
    {
        $collector = new FinalOperatorClosureCommandSurfaceCollector;

        $commands = [
            'payload.a' => 'php artisan atlas:ai:self-construction --foo',
            'payload.b' => 'php artisan atlas:ai:self-construction --bar',
            'payload.c' => 'php artisan atlas:ai:self-construction --foo',
        ];

        $unique = $collector->uniqueCommandsByText($commands);

        $this->assertSame(
            [
                'payload.a' => 'php artisan atlas:ai:self-construction --foo',
                'payload.b' => 'php artisan atlas:ai:self-construction --bar',
            ],
            $unique
        );
    }

    public function test_extract_command_options_parses_long_options_and_dedups_sorted(): void
    {
        $collector = new FinalOperatorClosureCommandSurfaceCollector;

        $options = $collector->extractCommandOptions(
            'php artisan atlas:ai:self-construction --zeta --alpha --zeta --mike'
        );

        $this->assertSame(['alpha', 'mike', 'zeta'], $options);
    }

    public function test_extract_command_options_returns_empty_when_no_long_options(): void
    {
        $collector = new FinalOperatorClosureCommandSurfaceCollector;

        $this->assertSame([], $collector->extractCommandOptions('echo hello'));
    }

    public function test_legacy_self_construction_command_aliases_returns_known_aliases(): void
    {
        $collector = new FinalOperatorClosureCommandSurfaceCollector;

        $aliases = $collector->legacySelfConstructionCommandAliases();

        $this->assertContains('runtime-gap-matrix', $aliases);
        $this->assertContains('operator-evidence-submission-readiness', $aliases);
    }

    public function test_operator_command_surface_integrity_emits_byte_identical_rows(): void
    {
        $collector = new FinalOperatorClosureCommandSurfaceCollector;

        $surface = [
            'a' => 'php artisan atlas:ai:self-construction --unknown-flag --foo',
        ];

        $integrity = $collector->operatorCommandSurfaceIntegrity($surface);

        $this->assertSame(
            'atlas.self_construction.final_operator_closure_command_surface_integrity.v1',
            $integrity['schema_version']
        );
        $this->assertSame('atlas:ai:self-construction', $integrity['command_name']);
        $this->assertSame('command_surface_attention_required', $integrity['status']);
        $this->assertCount(1, $integrity['commands']);
        $this->assertSame('payload.a', $integrity['commands'][0]['payload_path']);
        $this->assertFalse($integrity['commands'][0]['surface_ok']);
    }

    public function test_operator_command_surface_integrity_is_clean_when_no_unknown_or_legacy_options(): void
    {
        $collector = new FinalOperatorClosureCommandSurfaceCollector;

        $knownOptions = $collector->selfConstructionCommandOptions();
        $this->assertNotEmpty($knownOptions, 'atlas:ai:self-construction must expose options in this env');

        $knownSubset = array_slice($knownOptions, 0, 1);
        $surface = [
            'a' => 'php artisan atlas:ai:self-construction --'.$knownSubset[0],
        ];

        $integrity = $collector->operatorCommandSurfaceIntegrity($surface);

        $this->assertSame('command_surface_aligned', $integrity['status']);
        $this->assertTrue($integrity['legacy_alias_free']);
    }
}
