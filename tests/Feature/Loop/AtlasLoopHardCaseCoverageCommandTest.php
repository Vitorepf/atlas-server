<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the hard-case coverage reporter is live at the operator surface: the command emits per-capability bench
 * coverage facts (capabilities, per_capability buckets, zero-case gaps) over the dataset registry.
 */
final class AtlasLoopHardCaseCoverageCommandTest extends TestCase
{
    public function test_hard_case_coverage_emits_per_capability_facts(): void
    {
        $exit = Artisan::call('atlas:loop:hard-case-coverage', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.hard_case_coverage.v1', $decoded['schema_version']);
        $this->assertIsArray($decoded['capabilities']);
        $this->assertContains('certify', $decoded['capabilities']);
        $this->assertIsArray($decoded['per_capability']);
        $this->assertArrayHasKey('certify', $decoded['per_capability']);
        $this->assertArrayHasKey('case_ids', $decoded['per_capability']['certify']);
        $this->assertIsArray($decoded['capabilities_with_zero_cases']);
    }
}
