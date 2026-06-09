<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasLoopCompileVerifierCommandTest extends TestCase
{
    public function test_command_compiles_a_strict_red_verifier_packet(): void
    {
        $exit = Artisan::call('atlas:loop:compile-verifier', [
            '--intent' => 'Add method commandIntentVerifierProbe() returns "ok".',
            '--target' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopWorkspaceMaterializer.php',
            '--method' => 'commandIntentVerifierProbe',
            '--returns' => 'ok',
            '--strict' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertIsArray($payload);
        $this->assertSame('atlas.loop.intent_verifier_factory.v1', $payload['schema_version'] ?? null);
        $this->assertSame('ready', $payload['status'] ?? null);
        $this->assertSame('red', data_get($payload, 'red_preflight.status'));
    }

    public function test_command_compiles_command_output_atom(): void
    {
        $probe = 'php -r '.escapeshellarg("require 'vendor/autoload.php'; echo method_exists('App\\\\Services\\\\Ai\\\\AutonomousEvolution\\\\AtlasLoopWorkspaceMaterializer', 'cliCommandOutputProbe') ? 'yes' : 'no';");

        $exit = Artisan::call('atlas:loop:compile-verifier', [
            '--intent' => 'Command output verifier for cliCommandOutputProbe.',
            '--target' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopWorkspaceMaterializer.php',
            '--command' => $probe,
            '--output-contains' => 'yes',
            '--strict' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame('command_output', data_get($payload, 'verification_atoms.0.type'));
        $this->assertSame('red', data_get($payload, 'red_preflight.status'));
    }

    public function test_command_compiles_http_response_atom(): void
    {
        $exit = Artisan::call('atlas:loop:compile-verifier', [
            '--intent' => 'HTTP verifier for a future endpoint.',
            '--target' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopWorkspaceMaterializer.php',
            '--http-path' => '/__atlas_cli_intent_verifier_probe',
            '--http-status' => '200',
            '--http-body-contains' => 'ok',
            '--strict' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame('http_response', data_get($payload, 'verification_atoms.0.type'));
        $this->assertSame('red', data_get($payload, 'red_preflight.status'));
    }
}
