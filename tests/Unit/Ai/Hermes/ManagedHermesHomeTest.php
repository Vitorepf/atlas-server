<?php

namespace Tests\Unit\Ai\Hermes;

use App\Services\Ai\Hermes\ManagedHermesHome;
use Illuminate\Filesystem\Filesystem;
use Tests\TestCase;

/**
 * Direct coverage of the shared managed-home builder's pure, safety-relevant
 * surface (path traversal guard, deterministic slug, config encode). The full
 * write/forget lifecycle is exercised end-to-end by the MCP + profile
 * provisioner tests that now delegate here.
 */
class ManagedHermesHomeTest extends TestCase
{
    private function home(): ManagedHermesHome
    {
        return new ManagedHermesHome(new Filesystem());
    }

    public function test_path_is_deterministic_and_under_storage(): void
    {
        $a = $this->home()->path('home', 'trace-123');
        $b = $this->home()->path('home', 'trace-123');

        $this->assertSame($a, $b);
        $this->assertStringContainsString('/app/hermes/home/', $a);
        $this->assertStringEndsWith('trace-123', $a);
    }

    public function test_path_can_never_traverse_outside_the_hermes_dir(): void
    {
        $base = storage_path('app/hermes');
        foreach ([
            ['../../../etc', 'x'],
            ['home', '../../../../etc/passwd'],
            ['..', '..'],
            ['', ''],
        ] as [$kind, $seed]) {
            $path = $this->home()->path($kind, $seed);
            $this->assertStringStartsWith($base.'/', $path, "path escaped: $kind / $seed -> $path");
            $this->assertStringNotContainsString('..', substr($path, strlen($base)), 'no .. segment may survive');
        }
    }

    public function test_safe_slug_limits_length_and_falls_back_on_empty(): void
    {
        $long = str_repeat('a', 400);
        $this->assertLessThanOrEqual(120, strlen($this->home()->safeSlug($long)));

        // a seed that slugifies to empty (only punctuation) gets a hashed fallback
        $fallback = $this->home()->safeSlug('***');
        $this->assertNotSame('', $fallback);
        $this->assertStringStartsWith('hermes-home-', $fallback);
    }

    public function test_encode_round_trips_the_config_body(): void
    {
        $body = ['mcp_servers' => ['atlas' => ['command' => 'x', 'enabled' => true]], 'memory' => ['memory_enabled' => true]];
        $encoded = $this->home()->encode($body);

        $this->assertIsString($encoded);
        $this->assertStringContainsString('mcp_servers', $encoded);
        $this->assertStringContainsString('memory_enabled', $encoded);
    }

    public function test_merge_preserves_existing_block_and_adds_the_patch_into_one_home(): void
    {
        $home = $this->home();
        $seed = 'merge-trace-'.bin2hex(random_bytes(4));

        try {
            // The MCP boundary writes mcp_servers first...
            $mcpHome = $home->write('home', $seed, ['mcp_servers' => ['atlas' => ['command' => 'x', 'enabled' => true]]]);

            // ...then delegation merges its caps into the SAME home (not a second).
            $mergedHome = $home->merge('home', $seed, ['delegation' => ['max_spawn_depth' => 1, 'max_concurrent_children' => 3]]);

            $this->assertSame($mcpHome, $mergedHome, 'merge must target the same HERMES_HOME the first write created');

            $config = $home->read('home', $seed);
            // Both blocks coexist in one config.yaml.
            $this->assertSame(true, data_get($config, 'mcp_servers.atlas.enabled'));
            $this->assertSame(1, data_get($config, 'delegation.max_spawn_depth'));
            $this->assertSame(3, data_get($config, 'delegation.max_concurrent_children'));
        } finally {
            $home->forget('home', $seed);
        }
    }

    public function test_merge_into_empty_home_writes_only_the_patch(): void
    {
        $home = $this->home();
        $seed = 'merge-fresh-'.bin2hex(random_bytes(4));

        try {
            $this->assertSame([], $home->read('home', $seed));

            $home->merge('home', $seed, ['delegation' => ['max_spawn_depth' => 1]]);

            $config = $home->read('home', $seed);
            $this->assertSame(1, data_get($config, 'delegation.max_spawn_depth'));
            $this->assertArrayNotHasKey('mcp_servers', $config);
        } finally {
            $home->forget('home', $seed);
        }
    }
}
