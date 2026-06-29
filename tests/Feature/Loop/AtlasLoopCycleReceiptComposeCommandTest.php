<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the cycle receipt composer is live at the operator surface and emits deterministic facts: the cycle
 * id, base/head commit and the fact bundle are composed from the supplied sources. A missing --cycle-id is a
 * usage error.
 */
final class AtlasLoopCycleReceiptComposeCommandTest extends TestCase
{
    public function test_requires_cycle_id(): void
    {
        $exit = Artisan::call('atlas:loop:cycle-receipt-compose', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_composes_receipt_from_sources(): void
    {
        $exit = Artisan::call('atlas:loop:cycle-receipt-compose', [
            '--cycle-id' => 'cycle-1',
            '--sources' => json_encode([
                'base_commit' => 'abc123',
                'head_commit' => 'def456',
                'frozen_verdicts' => [['id' => 'v1', 'verdict' => 'pass']],
                'telemetry' => ['events' => 2],
            ]),
            '--json' => true,
        ]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.cycle_receipt.body.v1', $decoded['schema_version']);
        $this->assertSame('cycle-1', $decoded['cycle_id']);
        $this->assertSame('abc123', $decoded['base_commit']);
        $this->assertSame('def456', $decoded['head_commit']);
        $this->assertArrayHasKey('facts', $decoded);
        $this->assertCount(1, $decoded['facts']['frozen_verdicts']);
        $this->assertSame(2, $decoded['facts']['telemetry']['events']);
    }
}
