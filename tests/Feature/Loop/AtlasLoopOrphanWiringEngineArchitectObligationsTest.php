<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopOrphanWiringAuthoringEngine;
use Tests\TestCase;

final class AtlasLoopOrphanWiringEngineArchitectObligationsTest extends TestCase
{
    public function test_prompt_without_architect_obligations_omits_the_architect_block(): void
    {
        $captured = [];
        $engine = new AtlasLoopOrphanWiringAuthoringEngine(function (string $provider, string $prompt) use (&$captured): string {
            $captured[] = $prompt;

            return $this->response();
        });

        $this->assertIsArray($engine->author($this->payload(), sys_get_temp_dir()));

        $this->assertCount(1, $captured);
        $this->assertStringNotContainsString('ARCHITECT OBLIGATIONS', $captured[0]);
        $this->assertStringContainsString('Output EXACTLY this marker format and nothing else:', $captured[0]);
    }

    public function test_prompt_with_architect_obligations_includes_each_literal_obligation_before_markers(): void
    {
        $captured = [];
        $engine = new AtlasLoopOrphanWiringAuthoringEngine(function (string $provider, string $prompt) use (&$captured): string {
            $captured[] = $prompt;

            return $this->response();
        });
        $payload = $this->payload();
        $payload['_architect_obligations'] = [
            'Bind X in AppServiceProvider',
            'Call X::run() in Y',
        ];

        $this->assertIsArray($engine->author($payload, sys_get_temp_dir()));

        $this->assertCount(1, $captured);
        $prompt = $captured[0];
        $this->assertStringContainsString('ARCHITECT OBLIGATIONS (MUST be satisfied by your wiring):', $prompt);
        $this->assertStringContainsString('Bind X in AppServiceProvider', $prompt);
        $this->assertStringContainsString('Call X::run() in Y', $prompt);
        $this->assertLessThan(
            strpos($prompt, '<<<TEST_REL>>>'),
            strpos($prompt, 'ARCHITECT OBLIGATIONS'),
            'architect obligations must appear before the response markers',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(): array
    {
        return [
            'orphan_fqcn' => 'Orphan',
            'orphan_path' => 'app/Orphan.php',
            'public_methods' => ['contribute'],
            'sibling_test' => 'tests/Unit/OrphanTest.php',
        ];
    }

    private function response(): string
    {
        return implode("\n", [
            '<<<TEST_REL>>>', 'tests/Feature/Loop/Wiring/OrphanWiringTest.php',
            '<<<TEST_COMMAND>>>', './vendor/bin/phpunit tests/Feature/Loop/Wiring/OrphanWiringTest.php',
            '<<<TEST_CONTENT>>>', '<?php // red-green wiring test',
            '<<<WIRING_REL>>>', 'app/Consumer.php',
            '<<<WIRING_CONTENT>>>', '<?php // production wiring',
            '<<<END>>>',
        ]);
    }
}
