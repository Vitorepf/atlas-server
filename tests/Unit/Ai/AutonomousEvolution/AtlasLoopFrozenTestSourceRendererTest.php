<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopFrozenTestContentBuilder;
use App\Services\Ai\AutonomousEvolution\AtlasLoopFrozenTestSourceRenderer;
use App\Services\Ai\AutonomousEvolution\AtlasLoopIntentVerifierFactory;
use Tests\TestCase;

final class AtlasLoopFrozenTestSourceRendererTest extends TestCase
{
    public function test_renderer_is_byte_identical_to_legacy_builder_across_atom_types(): void
    {
        $legacy = new AtlasLoopFrozenTestContentBuilder;
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

        $this->assertSame(
            $legacy->frozenTestContent($testPath, $target, $class, $atoms),
            $renderer->frozenTestContent($testPath, $target, $class, $atoms),
        );
    }

    public function test_renderer_preserves_count_operator_byte_identically(): void
    {
        $cases = [
            'equals' => ['=', '='],
            'greater-or-equal' => ['>=', '>='],
            'less-or-equal' => ['<=', '<='],
            'greater-than' => ['>', '>'],
            'less-than' => ['<', '<'],
            'fallback' => ['bogus', '>='],
        ];

        $legacy = new AtlasLoopFrozenTestContentBuilder;
        $renderer = new AtlasLoopFrozenTestSourceRenderer;

        foreach ($cases as [$input, $normalized]) {
            $contentLegacy = $legacy->frozenTestContent(
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

            $contentRenderer = $renderer->frozenTestContent(
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

            $this->assertSame($contentLegacy, $contentRenderer);
            $this->assertStringContainsString("\$dbCountOperator0 = '".$normalized."';", $contentRenderer);
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
