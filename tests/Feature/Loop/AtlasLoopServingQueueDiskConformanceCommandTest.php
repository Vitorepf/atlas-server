<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the serving-queue disk-conformance sentinel is live at the operator surface and emits deterministic
 * facts: the expected (config) disk and the resolved (live queue repository) disk are surfaced, and when they
 * match the queue is reported conformant with no drift.
 */
final class AtlasLoopServingQueueDiskConformanceCommandTest extends TestCase
{
    public function test_emits_disk_conformance_facts(): void
    {
        $exit = Artisan::call('atlas:loop:serving-queue-disk-conformance', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.serving_queue_disk_conformance.v1', $decoded['schema']);
        $this->assertIsBool($decoded['conformant']);
        $this->assertNotSame('', $decoded['env_disk']);

        // conformance is exactly env_disk === resolved_disk, and drift is present iff not conformant.
        $this->assertSame($decoded['env_disk'] === $decoded['resolved_disk'], $decoded['conformant']);
        $this->assertSame($decoded['conformant'], ! array_key_exists('drift', $decoded));
    }

    public function test_resolved_disk_matches_expected_and_is_conformant(): void
    {
        $exit = Artisan::call('atlas:loop:serving-queue-disk-conformance', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertTrue($decoded['conformant']);
        $this->assertSame($decoded['env_disk'], $decoded['resolved_disk']);
        $this->assertArrayNotHasKey('drift', $decoded);
    }
}
