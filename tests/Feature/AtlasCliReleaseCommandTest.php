<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasCliReleaseCommandTest extends TestCase
{
    public function test_release_gate_can_validate_structure_without_running_expensive_gates(): void
    {
        $exitCode = Artisan::call('atlas:cli:release', [
            '--release-version' => 'v2.0.0',
            '--no-final' => true,
            '--skip-dogfood' => true,
            '--allow-dirty' => true,
            '--preflight' => true,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"status": "passed"', $output);
        $this->assertStringContainsString('"ci_workflow"', $output);
        $this->assertStringContainsString('"final_product_docs"', $output);
        $this->assertStringContainsString('"release_checklist"', $output);
    }

    public function test_release_gate_rejects_skipped_final_or_dogfood_outside_preflight(): void
    {
        $exitCode = Artisan::call('atlas:cli:release', [
            '--release-version' => 'v2.0.0',
            '--no-final' => true,
            '--skip-dogfood' => true,
            '--allow-dirty' => true,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"status": "needs_review"', $output);
        $this->assertStringContainsString('"dogfood"', $output);
        $this->assertStringContainsString('"final_readiness"', $output);
    }
}
