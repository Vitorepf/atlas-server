<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the cortex universal config loader is live at the operator surface and emits deterministic facts: a
 * valid raw config normalizes to its facts; a config with the wrong schema_id is a config error.
 */
final class AtlasLoopCortexConfigLoadCommandTest extends TestCase
{
    public function test_valid_raw_config_normalizes(): void
    {
        $exit = Artisan::call('atlas:loop:cortex-config-load', [
            '--raw' => json_encode([
                'schema_id' => 'atlas.cortex.facts.v1',
                'scope_roots' => ['app/'],
                'doc_roots' => ['docs/'],
                'forbidden_globs' => ['vendor/*'],
                'clone_min_lines' => 40,
            ]),
            '--json' => true,
        ]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.cortex_config_load.v1', $decoded['schema']);
        $config = $decoded['config'];
        $this->assertSame('atlas.cortex.facts.v1', $config['schema_id']);
        $this->assertSame(['app/'], $config['scope_roots']);
        $this->assertSame(['docs/'], $config['doc_roots']);
        $this->assertSame(['vendor/*'], $config['forbidden_globs']);
        $this->assertSame(40, $config['clone_min_lines']);
    }

    public function test_wrong_schema_id_is_a_config_error(): void
    {
        $exit = Artisan::call('atlas:loop:cortex-config-load', [
            '--raw' => json_encode(['schema_id' => 'wrong.schema', 'scope_roots' => ['app/']]),
            '--json' => true,
        ]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('config_error', $decoded['status']);
        $this->assertStringContainsString('schema_id', $decoded['reason']);
    }
}
