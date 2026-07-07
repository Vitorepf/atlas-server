<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * P2 (Obra #19) — `atlas:test:impacted` maps changed paths to impacted tests via
 * real world-model edges (with a conventional + declared-fallback safety net), and
 * never silently skips code. The analyzer's selection recall is separately proven
 * ≥0.85 by atlas:programming:test-impact-benchmark.
 */
final class AtlasTestImpactedCommandTest extends TestCase
{
    public function test_a_changed_test_file_is_selected_and_a_command_is_recommended(): void
    {
        // A changed test file is impacted by definition — a deterministic anchor that
        // does not depend on the CI read-model tables being populated in the test DB.
        $changed = 'tests/Feature/Ai/AtlasTestImpactedCommandTest.php';

        $code = Artisan::call('atlas:test:impacted', ['paths' => [$changed], '--json' => true]);
        $out = Artisan::output();

        $this->assertSame(0, $code);
        $payload = json_decode($out, true, flags: JSON_THROW_ON_ERROR);

        $this->assertContains($changed, $payload['selected_existing_tests']);
        $this->assertNotSame([], $payload['recommended_commands']);
        $this->assertArrayHasKey('edge_source', $payload);      // real-edge provenance is surfaced
        $this->assertTrue($payload['advisory']);
    }

    public function test_no_coverage_declares_a_broader_suite_fallback_never_a_silent_skip(): void
    {
        // A changed production file with no convention test and no edge => the analyzer
        // reports requires_no_test_reason, and the command DECLARES the broader-suite
        // fallback (the pétrea: never a silent skip).
        $changed = 'app/Support/AtlasCloneDir.php'; // no tests/Unit/AtlasCloneDirTest.php by convention

        $code = Artisan::call('atlas:test:impacted', ['paths' => [$changed], '--risk' => 'high', '--json' => true]);
        $out = Artisan::output();

        $this->assertSame(0, $code);
        $payload = json_decode($out, true, flags: JSON_THROW_ON_ERROR);

        if (($payload['requires_no_test_reason'] ?? false) === true) {
            $this->assertNotNull($payload['fallback_declared']);
            $this->assertStringContainsString('never skip', $payload['fallback_declared']);
        } else {
            // If an edge/convention test WAS found, that is also valid — as long as
            // something is recommended (never an empty, silent selection).
            $this->assertNotSame([], $payload['recommended_commands']);
        }
    }
}
