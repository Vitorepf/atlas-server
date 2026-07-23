<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use Tests\TestCase;

/**
 * GOLDEN / characterization net for
 * {@see \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentControlPlaneSection::agentControlPlane()}.
 *
 * WHY THIS EXISTS (GOD-DEBULK "patamar superior" prep):
 * agentControlPlane() is a ~4k-LOC method that (a) runs ~250 class_exists/
 * method_exists probes, (b) picks the FIRST unmet gap through a ~334-branch
 * if/elseif chain (-> next_build_slices / next_required_slice), and (c) appends
 * ~315 capability slugs gated on those same flags (-> current_capability). It
 * FEEDS capability gating + ChainIntegrityAudit; a $this-vs-$this->mother slip
 * once silently dropped ~170 capabilities. Before rewriting the repetitive
 * probe/gap/append blocks into a declarative data table, this net freezes the
 * EXACT output of the pure-conditional surface so the rewrite can be proven
 * byte-identical.
 *
 * SCOPE — why only a subset of the output is pinned:
 * The full agentControlPlane() payload also embeds NON-reproducible runtime
 * state — provider_sessions read the live on-disk reservation ledger and
 * compute seconds_until_lease_expiry from time(), and counts/source_of_truth
 * hash live queue/workspace state. Those keys (and therefore the top-level
 * control_plane_hash) change every second and with every concurrent lane, so
 * pinning them would make the golden flaky, not safe. The rewrite does NOT
 * touch any of that. It touches ONLY the flag-derived, deterministic keys:
 *   - control_plane.current_capability          (the ~315 appends + base list)
 *   - control_plane.not_yet_runtime_capable
 *   - control_plane.next_build_slices           (the first-gap chain output)
 *   - control_plane.persistent_runtime.next_required_slice
 * plus the constant literals next to them. Those are pure functions of the
 * probe flags (class_exists/method_exists on this tree) and $allRuntimeTables-
 * Ready, independent of clock/disk — hence reproducible and safe to freeze.
 *
 * COVERAGE — two regimes are exercised:
 *   - tables ABSENT (default sqlite :memory:, no runtime tables): first gap =
 *     runtime-schema migration; capabilities = base list only.
 *   - tables READY (control-plane migration applied): gap chain runs deep to
 *     the first genuinely-unmet probe; capabilities = base + merge + appends.
 * Between them the base list, the merge/append block, the first gap-branch and
 * a deep gap-branch are all pinned.
 */
final class ReadinessAgentControlPlaneGoldenCharacterizationTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__.'/fixtures';

    private function migrateRuntimeTables(): void
    {
        $migration = require base_path('database/migrations/2026_05_12_010000_create_atlas_self_construction_agent_control_plane_tables.php');
        $migration->up();
    }

    /**
     * The exact, deterministic, flag-derived slice of the payload that the
     * data-table rewrite must reproduce byte-for-byte.
     *
     * @param  array<string,mixed>  $out
     * @return array<string,mixed>
     */
    private function pureSubset(array $out): array
    {
        $cp = $out['control_plane'] ?? [];

        return [
            'current_capability' => $cp['current_capability'] ?? null,
            'not_yet_runtime_capable' => $cp['not_yet_runtime_capable'] ?? null,
            'next_build_slices' => $cp['next_build_slices'] ?? null,
            'next_required_slice' => data_get($cp, 'persistent_runtime.next_required_slice'),
            'runtime_contracts_available' => $cp['runtime_contracts_available'] ?? null,
            'paperclip_patterns_absorbed' => $cp['paperclip_patterns_absorbed'] ?? null,
            'invariants' => $cp['invariants'] ?? null,
            'maturity' => $cp['maturity'] ?? null,
            'top_level_keys' => array_keys($out),
            'control_plane_keys' => array_keys($cp),
        ];
    }

    /**
     * @param  array<string,mixed>  $pure
     */
    private function assertGolden(string $fixture, array $pure): void
    {
        $path = self::FIXTURE_DIR.'/'.$fixture;
        $this->assertFileExists($path, "Golden fixture {$fixture} is missing.");
        /** @var array<string,mixed> $expected */
        $expected = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        // Field-by-field first so a divergence points at the culprit key
        // (e.g. a dropped capability) instead of a wall of diff.
        foreach ($expected as $key => $value) {
            $this->assertSame($value, $pure[$key] ?? null, "Golden divergence on '{$key}'.");
        }
        // Whole-subset equality (catches any EXTRA key too).
        $this->assertSame($expected, $pure, "Golden subset diverged for {$fixture}.");
    }

    public function test_agent_control_plane_pure_surface_is_byte_identical_tables_absent(): void
    {
        $svc = app(AtlasSelfConstructionReadinessService::class);

        $a = $this->pureSubset($svc->agentControlPlane());
        $b = $this->pureSubset($svc->agentControlPlane());
        $this->assertSame($a, $b, 'Pure surface must be deterministic within a run (no clock/disk leakage).');

        $this->assertGolden('agent_control_plane_pure_tables_absent.json', $a);
    }

    public function test_agent_control_plane_pure_surface_is_byte_identical_tables_ready(): void
    {
        $this->migrateRuntimeTables();
        $svc = app(AtlasSelfConstructionReadinessService::class);

        $a = $this->pureSubset($svc->agentControlPlane());
        $b = $this->pureSubset($svc->agentControlPlane());
        $this->assertSame($a, $b, 'Pure surface must be deterministic within a run (no clock/disk leakage).');

        $this->assertGolden('agent_control_plane_pure_tables_ready.json', $a);

        // Sanity anchors on the deep-regime shape so an accidental regime flip
        // (e.g. the whole capability block getting skipped) is caught loudly.
        $this->assertGreaterThan(500, count($a['current_capability']));
        $this->assertContains('durable_packet_checkout_lock', $a['current_capability']);
    }
}
