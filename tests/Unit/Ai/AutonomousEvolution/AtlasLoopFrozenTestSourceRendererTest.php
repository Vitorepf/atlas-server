<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopFrozenTestSourceRenderer;
use App\Services\Ai\AutonomousEvolution\AtlasLoopIntentVerifierFactory;
use Tests\TestCase;

final class AtlasLoopFrozenTestSourceRendererTest extends TestCase
{
    /**
     * Golden capturado do output byte-idêntico ao legacy AtlasLoopFrozenTestContentBuilder
     * (aposentado na Obra #8; equivalência provada antes da remoção).
     */
    private const GOLDEN_ALL_ATOMS_SHA256 = 'a005a77489599f36465388c4b1b02cf0514764a51518bfd4ff865769128e2c29';

    public function test_renderer_output_is_frozen_across_atom_types(): void
    {
        $renderer = new AtlasLoopFrozenTestSourceRenderer;

        $testPath = 'tests/Feature/Loop/IntentVerifier/frozen-renderer-smoke.php';
        $target = 'app/Services/Ai/AutonomousEvolution/AtlasLoopWorkspaceMaterializer.php';
        $class = 'App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopWorkspaceMaterializer';
        $atoms = [
            [
                'type' => 'method_return',
                'method' => 'intentVerifierProbe',
                'expected' => 'ok',
            ],
            [
                'type' => 'command_output',
                'command' => 'php -r '.escapeshellarg("echo 'hello';"),
                'output_contains' => 'hello',
                'exit_code' => 0,
            ],
            [
                'type' => 'http_response',
                'method' => 'GET',
                'path' => '/__atlas_intent_verifier_probe',
                'status' => 200,
                'body_contains' => 'ok',
            ],
            [
                'type' => 'event_dispatched',
                'event_class' => 'atlas.intent.verifier.probe',
                'trigger' => [
                    'type' => 'method_call',
                    'method' => 'dispatchIntentVerifierProbe',
                ],
            ],
            [
                'type' => 'job_dispatched',
                'job_class' => 'App\\Jobs\\FlushBatchedMobilePushes',
                'trigger' => [
                    'type' => 'method_call',
                    'method' => 'dispatchIntentVerifierJobProbe',
                ],
            ],
            [
                'type' => 'db_state',
                'table' => 'intent_verifier_records',
                'setup_sql' => ['CREATE TABLE intent_verifier_records (id INTEGER PRIMARY KEY AUTOINCREMENT, marker TEXT NOT NULL)'],
                'where' => ['marker' => 'ok'],
                'expected_count' => 1,
                'count_operator' => '>=',
                'trigger' => [
                    'type' => 'method_call',
                    'method' => 'recordIntentVerifierDbProbe',
                ],
            ],
        ];

        $content = $renderer->frozenTestContent($testPath, $target, $class, $atoms);

        $this->assertSame(self::GOLDEN_ALL_ATOMS_SHA256, hash('sha256', $content));
    }

    public function test_renderer_preserves_count_operator_byte_identically(): void
    {
        // label => [input operator, normalized operator, golden sha256 do source renderizado]
        $cases = [
            'equals' => ['=', '=', 'dc2173138470cea59c32d679f078650eb790aa35a599e0d146243d2a1ce025a8'],
            'greater-or-equal' => ['>=', '>=', '508d868b2bacf502988f6a0b45199951765058d53a0b70beae982fd7101ae353'],
            'less-or-equal' => ['<=', '<=', '02ea3dab6985df645dcd0255fa43405a8df7cddd2b9f7bc1fa657ea948a4d705'],
            'greater-than' => ['>', '>', 'a6615bab8bc08fe7b42da893e7a02a1d0d8cde41f1c9f41cff76a743d5cd802b'],
            'less-than' => ['<', '<', 'b4882dd97a8fbedad1703cff61b245ac227f413ae319674a5395545b6dfcda52'],
            'fallback' => ['bogus', '>=', '508d868b2bacf502988f6a0b45199951765058d53a0b70beae982fd7101ae353'],
        ];

        $renderer = new AtlasLoopFrozenTestSourceRenderer;

        foreach ($cases as $label => [$input, $normalized, $golden]) {
            $content = $renderer->frozenTestContent(
                'tests/Feature/Loop/IntentVerifier/frozen-renderer-count.php',
                'app/Services/Ai/AutonomousEvolution/AtlasLoopWorkspaceMaterializer.php',
                'App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopWorkspaceMaterializer',
                [[
                    'type' => 'db_state',
                    'table' => 'intent_verifier_records',
                    'setup_sql' => ['CREATE TABLE intent_verifier_records (id INTEGER PRIMARY KEY AUTOINCREMENT, marker TEXT NOT NULL)'],
                    'where' => ['marker' => 'ok'],
                    'expected_count' => 1,
                    'count_operator' => $input,
                    'trigger' => [
                        'type' => 'method_call',
                        'method' => 'recordIntentVerifierDbProbe',
                    ],
                ]],
            );

            $this->assertSame($golden, hash('sha256', $content), "case [{$label}]");
            $this->assertStringContainsString("\$dbCountOperator0 = '".$normalized."';", $content, "case [{$label}]");
        }
    }

    public function test_factory_public_output_uses_renderer_and_stays_red(): void
    {
        $factory = app(AtlasLoopIntentVerifierFactory::class);
        $packet = $factory->compileFrameworkPacket(
            base_path(),
            'DB state verifier for a future tiny method.',
            [
                'target_relative_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopWorkspaceMaterializer.php',
                'verification_atoms' => [[
                    'type' => 'db_state',
                    'table' => 'intent_verifier_records',
                    'setup_sql' => ['CREATE TABLE intent_verifier_records (id INTEGER PRIMARY KEY AUTOINCREMENT, marker TEXT NOT NULL)'],
                    'where' => ['marker' => 'ok'],
                    'expected_count' => 1,
                    'count_operator' => '>',
                    'trigger' => [
                        'type' => 'method_call',
                        'method' => 'recordIntentVerifierDbProbe',
                    ],
                ]],
            ],
        );

        $this->assertTrue($packet['ready'], json_encode($packet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertSame('red', data_get($packet, 'red_preflight.status'));

        $payload = $factory->taskPayloadOrFail($packet);
        $renderer = new AtlasLoopFrozenTestSourceRenderer;
        $rendered = $renderer->frozenTestContent(
            (string) data_get($payload, 'frozen_tests.0.path'),
            (string) ($packet['target_relative_path'] ?? ''),
            (string) ($packet['target_class'] ?? ''),
            (array) ($packet['verification_atoms'] ?? []),
        );

        $this->assertSame($rendered, data_get($packet, 'frozen_tests.0.content'));
        $this->assertSame($rendered, data_get($payload, 'frozen_tests.0.content'));
    }
}
