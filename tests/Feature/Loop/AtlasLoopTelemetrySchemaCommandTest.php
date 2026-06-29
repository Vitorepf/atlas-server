<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the telemetry fact schema registry is live at the operator surface and emits deterministic facts: a
 * known kind returns its schema (schema_id + required/forbidden keys + key types); an unknown kind is an
 * error. A missing --kind is a usage error.
 */
final class AtlasLoopTelemetrySchemaCommandTest extends TestCase
{
    public function test_requires_kind(): void
    {
        $exit = Artisan::call('atlas:loop:telemetry-schema', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_known_kind_returns_schema(): void
    {
        $exit = Artisan::call('atlas:loop:telemetry-schema', ['--kind' => 'claim', '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.telemetry_schema.v1', $decoded['schema']);
        $this->assertSame('claim', $decoded['kind']);

        $schema = $decoded['telemetry_schema'];
        $this->assertArrayHasKey('schema_id', $schema);
        $this->assertContains('payload', $schema['required_keys']);
        $this->assertArrayHasKey('key_types', $schema);
        $this->assertArrayHasKey('forbidden_keys', $schema);
    }

    public function test_unknown_kind_is_an_error(): void
    {
        $exit = Artisan::call('atlas:loop:telemetry-schema', ['--kind' => 'bogus_kind', '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('unknown_kind', $decoded['status']);
    }
}
