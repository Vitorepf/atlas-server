<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopIntentVerifierFactory;
use Tests\TestCase;

final class AtlasLoopIntentVerifierFactoryTest extends TestCase
{
    public function test_compiles_framework_intent_into_red_frozen_verifier_packet(): void
    {
        $packet = app(AtlasLoopIntentVerifierFactory::class)->compileFrameworkPacket(
            base_path(),
            'Add method intentVerifierProbe() returns "ok".',
            [
                'target_relative_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopWorkspaceMaterializer.php',
                'method' => 'intentVerifierProbe',
                'returns' => 'ok',
                'verifier_refuter_commands' => [$this->cleanVerifierRefuterCommand()],
                'verifier_refuters_required' => 1,
            ],
        );

        $this->assertTrue($packet['ready'], json_encode($packet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertSame('ready', $packet['status']);
        $this->assertSame('red', data_get($packet, 'red_preflight.status'));
        $this->assertTrue(data_get($packet, 'red_preflight.baseline_red'));
        $this->assertSame(1, data_get($packet, 'verifier_refuters.executed'));
        $this->assertSame(0, data_get($packet, 'verifier_refuters.refuted'));
        $this->assertSame(['php '.data_get($packet, 'frozen_tests.0.path')], data_get($packet, 'acceptance.commands'));
        $this->assertTrue(data_get($packet, 'acceptance.revert_recheck'));
        $this->assertSame(
            'app/Services/Ai/AutonomousEvolution/AtlasLoopWorkspaceMaterializer.php',
            data_get($packet, 'task_payload.allowed_files.0'),
        );
    }

    public function test_blocks_ambiguous_intent_instead_of_inventing_a_verifier(): void
    {
        $packet = app(AtlasLoopIntentVerifierFactory::class)->compileFrameworkPacket(
            base_path(),
            'Make the materializer better somehow.',
            [
                'target_relative_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopWorkspaceMaterializer.php',
            ],
        );

        $this->assertFalse($packet['ready']);
        $this->assertSame('blocked', $packet['status']);
        $this->assertContains('no_executable_verification_atom', $packet['blockers']);
    }

    public function test_compiles_command_output_atom_into_red_verifier_packet(): void
    {
        $command = 'php -r '.escapeshellarg("require 'vendor/autoload.php'; echo method_exists('App\\\\Services\\\\Ai\\\\AutonomousEvolution\\\\AtlasLoopWorkspaceMaterializer', 'commandOutputProbe') ? 'yes' : 'no';");

        $packet = app(AtlasLoopIntentVerifierFactory::class)->compileFrameworkPacket(
            base_path(),
            'Command output verifier for commandOutputProbe.',
            [
                'target_relative_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopWorkspaceMaterializer.php',
                'verification_atoms' => [[
                    'type' => 'command_output',
                    'command' => $command,
                    'output_contains' => 'yes',
                    'exit_code' => 0,
                ]],
            ],
        );

        $this->assertTrue($packet['ready'], json_encode($packet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertSame('command_output', data_get($packet, 'verification_atoms.0.type'));
        $this->assertSame('red', data_get($packet, 'red_preflight.status'));
        $this->assertStringContainsString('command stdout missing', (string) data_get($packet, 'red_preflight.command_results.0.stderr'));
    }

    public function test_compiles_http_response_atom_into_red_verifier_packet(): void
    {
        $packet = app(AtlasLoopIntentVerifierFactory::class)->compileFrameworkPacket(
            base_path(),
            'HTTP verifier for a future tiny endpoint.',
            [
                'target_relative_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopWorkspaceMaterializer.php',
                'verification_atoms' => [[
                    'type' => 'http_response',
                    'method' => 'GET',
                    'path' => '/__atlas_intent_verifier_probe',
                    'status' => 200,
                    'body_contains' => 'ok',
                ]],
            ],
        );

        $this->assertTrue($packet['ready'], json_encode($packet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertSame('http_response', data_get($packet, 'verification_atoms.0.type'));
        $this->assertSame('red', data_get($packet, 'red_preflight.status'));
        $this->assertStringContainsString('http status mismatch', (string) data_get($packet, 'red_preflight.command_results.0.stderr'));
    }

    public function test_compiles_event_dispatched_atom_into_red_verifier_packet(): void
    {
        $packet = app(AtlasLoopIntentVerifierFactory::class)->compileFrameworkPacket(
            base_path(),
            'Event verifier for a future tiny method.',
            [
                'target_relative_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopWorkspaceMaterializer.php',
                'verification_atoms' => [[
                    'type' => 'event_dispatched',
                    'event_class' => 'atlas.intent.verifier.probe',
                    'trigger' => [
                        'type' => 'method_call',
                        'method' => 'dispatchIntentVerifierProbe',
                    ],
                ]],
            ],
        );

        $this->assertTrue($packet['ready'], json_encode($packet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertSame('event_dispatched', data_get($packet, 'verification_atoms.0.type'));
        $this->assertSame('red', data_get($packet, 'red_preflight.status'));
        $this->assertStringContainsString('dispatchIntentVerifierProbe missing', (string) data_get($packet, 'red_preflight.command_results.0.stderr'));
    }

    public function test_compiles_job_dispatched_atom_into_red_verifier_packet(): void
    {
        $packet = app(AtlasLoopIntentVerifierFactory::class)->compileFrameworkPacket(
            base_path(),
            'Job verifier for a future tiny method.',
            [
                'target_relative_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopWorkspaceMaterializer.php',
                'verification_atoms' => [[
                    'type' => 'job_dispatched',
                    'job_class' => 'App\\Jobs\\FlushBatchedMobilePushes',
                    'trigger' => [
                        'type' => 'method_call',
                        'method' => 'dispatchIntentVerifierJobProbe',
                    ],
                ]],
            ],
        );

        $this->assertTrue($packet['ready'], json_encode($packet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertSame('job_dispatched', data_get($packet, 'verification_atoms.0.type'));
        $this->assertSame('red', data_get($packet, 'red_preflight.status'));
        $this->assertStringContainsString('dispatchIntentVerifierJobProbe missing', (string) data_get($packet, 'red_preflight.command_results.0.stderr'));
    }

    private function cleanVerifierRefuterCommand(): string
    {
        return <<<'CMD'
php -r '$p=getenv("ATLAS_INTENT_VERIFIER_PACKET"); $j=json_decode(file_get_contents($p), true); $ok=(($j["red_preflight"]["status"] ?? null) === "red") && (($j["acceptance"]["revert_recheck"] ?? false) === true) && (($j["frozen_tests"][0]["content"] ?? "") !== ""); echo json_encode(["refuted"=>!$ok, "reason"=>$ok ? "verifier_packet_clean" : "verifier_packet_not_clean"]); exit(0);'
CMD;
    }
}
