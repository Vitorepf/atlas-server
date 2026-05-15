<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Programming\ForgeRivals\ForgeRivalsDeprecationNotifier;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Atlas Forge Rivals · Legacy command deprecation contract tests.
 *
 * Slice-0 contract: every legacy rivals command emits a deprecation banner
 * via ForgeRivalsDeprecationNotifier and references the canonical
 * `atlas:forge:rivals` entrypoint in source. Forward execution stays GUARDED
 * by the per-command `FORWARD_TO_CANONICAL_ENABLED` constant until Slice 6;
 * existing legacy behaviour is preserved.
 */
final class AtlasRivalsCommandDeprecationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ForgeRivalsDeprecationNotifier::startCapture();
    }

    protected function tearDown(): void
    {
        ForgeRivalsDeprecationNotifier::stopCapture();
        parent::tearDown();
    }

    public function test_legacy_benchmark_rivals_emits_deprecation_for_canonical(): void
    {
        // The legacy benchmark:rivals command may crash later in its handle()
        // when the in-memory test DB lacks the suite tables, but the contract
        // we care about is: the deprecation notifier fires BEFORE any
        // legacy work runs. Swallow downstream legacy failures.
        try {
            Artisan::call('atlas:engineering:benchmark:rivals', [
                'action' => 'readiness',
                '--json' => true,
            ]);
        } catch (\Throwable $e) {
            // Tolerated: deprecation is emitted before the legacy code path
            // that hits the DB schema.
        }

        $captured = ForgeRivalsDeprecationNotifier::captured();
        $this->assertNotEmpty($captured, 'legacy rivals command must emit a deprecation notification');

        $first = $captured[0];
        $this->assertSame('atlas:engineering:benchmark:rivals', $first['legacy_command']);
        $this->assertStringContainsString('[DEPRECATED]', $first['message']);
        $this->assertStringContainsString('atlas:forge:rivals', $first['message']);
    }

    public function test_legacy_harness_emits_deprecation_for_canonical(): void
    {
        Artisan::call('atlas:engineering:benchmark:rivals-harness', [
            'action' => 'doctor',
            '--json' => true,
        ]);

        $captured = ForgeRivalsDeprecationNotifier::captured();
        $this->assertNotEmpty($captured);
        $this->assertSame('atlas:engineering:benchmark:rivals-harness', $captured[0]['legacy_command']);
        $this->assertStringContainsString('atlas:forge:rivals', $captured[0]['message']);
    }

    public function test_legacy_forge_dry_run_emits_deprecation(): void
    {
        Artisan::call('atlas:programming:rivals-forge-dry-run', [
            '--json' => true,
        ]);

        $captured = ForgeRivalsDeprecationNotifier::captured();
        $this->assertNotEmpty($captured);
        $this->assertSame('atlas:programming:rivals-forge-dry-run', $captured[0]['legacy_command']);
        $this->assertSame('dry-run', $captured[0]['canonical_action']);
    }

    public function test_legacy_forge_preflight_emits_deprecation(): void
    {
        Artisan::call('atlas:programming:rivals-forge-preflight', [
            '--json' => true,
        ]);

        $captured = ForgeRivalsDeprecationNotifier::captured();
        $this->assertNotEmpty($captured);
        $this->assertSame('atlas:programming:rivals-forge-preflight', $captured[0]['legacy_command']);
        $this->assertSame('preflight', $captured[0]['canonical_action']);
    }

    public function test_legacy_evidence_pack_emits_deprecation(): void
    {
        Artisan::call('atlas:programming:rivals-evidence-pack', [
            '--json' => true,
        ]);

        $captured = ForgeRivalsDeprecationNotifier::captured();
        $this->assertNotEmpty($captured);
        $this->assertSame('atlas:programming:rivals-evidence-pack', $captured[0]['legacy_command']);
        $this->assertSame('collect-evidence', $captured[0]['canonical_action']);
    }
}
