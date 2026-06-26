<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\OperatorEvidenceSubmissionCommandSurfaceCollector;
use Tests\TestCase;

final class OperatorEvidenceSubmissionCommandSurfaceCollectorTest extends TestCase
{
    public function test_collect_operator_commands_selects_only_self_construction_strings_and_ksorts(): void
    {
        $collector = new OperatorEvidenceSubmissionCommandSurfaceCollector;

        $value = [
            'b' => 'php artisan atlas:ai:self-construction --foo',
            'a' => 'php artisan atlas:ai:self-construction --bar',
            'c' => 'echo hello',
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
    }

    public function test_extract_command_options_returns_sorted_unique_names(): void
    {
        $collector = new OperatorEvidenceSubmissionCommandSurfaceCollector;

        $options = $collector->extractCommandOptions(
            'php artisan atlas:ai:self-construction --zeta --alpha --zeta --mike'
        );

        $this->assertSame(['alpha', 'mike', 'zeta'], $options);
    }

    public function test_extract_command_options_returns_empty_when_no_long_options(): void
    {
        $collector = new OperatorEvidenceSubmissionCommandSurfaceCollector;

        $this->assertSame([], $collector->extractCommandOptions('echo hello'));
    }

    public function test_legacy_self_construction_command_aliases_lists_five_known_aliases(): void
    {
        $collector = new OperatorEvidenceSubmissionCommandSurfaceCollector;

        $aliases = $collector->legacySelfConstructionCommandAliases();

        $this->assertCount(5, $aliases);
        $this->assertContains('runtime-gap-matrix', $aliases);
        $this->assertContains('runtime-promotion-receipt-draft', $aliases);
        $this->assertContains('runtime-promotion-receipt-runbook', $aliases);
        $this->assertContains('human-completion-receipt-closure-execution-pack', $aliases);
        $this->assertContains('operator-evidence-submission-readiness', $aliases);
    }

    public function test_self_construction_command_options_returns_known_options(): void
    {
        $collector = new OperatorEvidenceSubmissionCommandSurfaceCollector;

        $options = $collector->selfConstructionCommandOptions();

        $this->assertNotEmpty($options, 'atlas:ai:self-construction must expose options in this env');
        foreach ($options as $opt) {
            $this->assertIsString($opt);
        }
        $sorted = $options;
        sort($sorted);
        $this->assertSame($sorted, $options, 'options must be returned sorted');
    }

    public function test_operator_command_surface_integrity_reports_attention_required_for_unknown_flag(): void
    {
        $collector = new OperatorEvidenceSubmissionCommandSurfaceCollector;

        $integrity = $collector->operatorCommandSurfaceIntegrity([
            'a' => 'php artisan atlas:ai:self-construction --this-flag-does-not-exist',
        ]);

        $this->assertSame(
            'atlas.self_construction.operator_command_surface_integrity.v1',
            $integrity['schema_version']
        );
        $this->assertSame('command_surface_attention_required', $integrity['status']);
        $this->assertNotEmpty($integrity['missing_options']);
    }
}
