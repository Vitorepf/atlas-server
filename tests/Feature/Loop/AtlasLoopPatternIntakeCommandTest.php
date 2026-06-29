<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the external-pattern source intake is live at the operator surface and emits deterministic facts:
 * material is quarantined into an UN-SELECTABLE spec (status source_material/candidate, an UNPROVEN gate
 * banner). Material with no id is an intake error; a missing --material is a usage error.
 */
final class AtlasLoopPatternIntakeCommandTest extends TestCase
{
    public function test_requires_material(): void
    {
        $exit = Artisan::call('atlas:loop:pattern-intake', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_quarantines_material_into_unselectable_spec(): void
    {
        $exit = Artisan::call('atlas:loop:pattern-intake', [
            '--material' => json_encode([
                'id' => 'ext-1',
                'name' => 'External idea',
                'summary' => 'an external pattern idea worth inspecting',
                'source' => 'Loop Library',
                'rationale' => 'looked useful in another project',
                'captured_at' => '2026-06-29',
            ]),
            '--json' => true,
        ]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.pattern_intake.v1', $decoded['schema']);
        $this->assertFalse($decoded['selectable']); // born un-selectable
        $this->assertSame('ext-1', $decoded['spec']['id']);
        $this->assertContains($decoded['spec']['status'], ['source_material', 'candidate']);
        $this->assertContains(
            'UNPROVEN external pattern: requires a fresh Atlas eval battery to certify before it may run',
            $decoded['spec']['success_gates'],
        );
    }

    public function test_material_without_id_is_an_intake_error(): void
    {
        $exit = Artisan::call('atlas:loop:pattern-intake', ['--material' => json_encode(['name' => 'no id']), '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('intake_error', $decoded['status']);
    }
}
