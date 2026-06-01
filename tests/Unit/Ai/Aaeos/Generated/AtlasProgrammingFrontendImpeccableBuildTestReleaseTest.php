<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasProgrammingFrontendImpeccableBuildTestReleaseService;
use Tests\TestCase;

final class AtlasProgrammingFrontendImpeccableBuildTestReleaseTest extends TestCase
{
    private AtlasProgrammingFrontendImpeccableBuildTestReleaseService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasProgrammingFrontendImpeccableBuildTestReleaseService();
    }

    /**
     * Regra 4 — "Release recusa dirty tree, HEAD nao enviado, changelog ausente
     * e build stale." Each of the four conditions, in isolation, must refuse the
     * release and name exactly itself as the blocker.
     */
    public function testEachReleaseConditionInIsolationRefusesAndNamesItself(): void
    {
        $clean = [
            'dirty_tree' => false,
            'head_pushed' => true,
            'changelog_present' => true,
            'build_fresh' => true,
        ];

        // Clean state ships with zero blockers.
        $ok = $this->service->releaseGate($clean);
        $this->assertTrue($ok['release_allowed']);
        $this->assertSame([], $ok['blockers']);

        $cases = [
            'dirty_tree' => ['dirty_tree' => true] + $clean,
            'head_not_pushed' => ['head_pushed' => false] + $clean,
            'changelog_missing' => ['changelog_present' => false] + $clean,
            'build_stale' => ['build_fresh' => false] + $clean,
        ];

        foreach ($cases as $expectedBlocker => $state) {
            $verdict = $this->service->releaseGate($state);
            $this->assertFalse($verdict['release_allowed'], "release must be refused for {$expectedBlocker}");
            $this->assertSame([$expectedBlocker], $verdict['blockers']);
        }
    }

    /**
     * Regra 4 — when every condition is bad at once, all four blockers fire in
     * the documented order.
     */
    public function testAllFourBlockersFireTogetherInDocumentedOrder(): void
    {
        $verdict = $this->service->releaseGate([
            'dirty_tree' => true,
            'head_pushed' => false,
            'changelog_present' => false,
            'build_fresh' => false,
        ]);

        $this->assertFalse($verdict['release_allowed']);
        $this->assertSame(
            ['dirty_tree', 'head_not_pushed', 'changelog_missing', 'build_stale'],
            $verdict['blockers']
        );
        $this->assertSame([], $verdict['cleared']);
    }

    /**
     * Regra 2 — "Depois de regra detector, rebuild browser/extension/site
     * counts." A detector-rule change marks exactly the three dependents stale;
     * the rebuild is incomplete until all three are rebuilt.
     */
    public function testDetectorRuleChangeForcesBrowserExtensionAndSiteRebuild(): void
    {
        $fresh = $this->service->rebuildPropagation(['detector_rules_changed' => false]);
        $this->assertSame([], $fresh['must_rebuild']);
        $this->assertTrue($fresh['rebuild_complete']);

        $changed = $this->service->rebuildPropagation([
            'detector_rules_changed' => true,
            'rebuilt' => ['browser_detector_bundle'],
        ]);

        $this->assertSame(
            ['browser_detector_bundle', 'extension_bundle', 'site_counts'],
            $changed['must_rebuild']
        );
        // Two of three still pending -> not complete.
        $this->assertSame(['extension_bundle', 'site_counts'], $changed['pending_rebuild']);
        $this->assertFalse($changed['rebuild_complete']);

        $done = $this->service->rebuildPropagation([
            'detector_rules_changed' => true,
            'rebuilt' => ['browser_detector_bundle', 'extension_bundle', 'site_counts'],
        ]);
        $this->assertTrue($done['rebuild_complete']);
    }

    /**
     * Regra 2 feeds Regra 4 — an unpropagated detector-rule change keeps the
     * build stale at the release gate even when the caller claims build_fresh.
     */
    public function testUnpropagatedDetectorChangeStaleBuildAtReleaseGate(): void
    {
        $verdict = $this->service->releaseGate([
            'dirty_tree' => false,
            'head_pushed' => true,
            'changelog_present' => true,
            'build_fresh' => true, // caller claims fresh ...
            'detector_rules_changed' => true,
            'rebuilt' => ['browser_detector_bundle'], // ... but only 1 of 3 rebuilt
        ]);

        $this->assertFalse($verdict['release_allowed']);
        $this->assertContains('build_stale', $verdict['blockers']);
    }

    /**
     * Regra 3 — "Live scripts exigem live E2E focado." A touched live script is
     * unsatisfied until a focused live E2E runs; an untouched one needs nothing.
     */
    public function testLiveScriptTouchRequiresFocusedLiveE2e(): void
    {
        $untouched = $this->service->liveScriptRequirement(['live_script_touched' => false]);
        $this->assertFalse($untouched['requires_live_e2e']);
        $this->assertTrue($untouched['satisfied']);

        $touchedNoRun = $this->service->liveScriptRequirement(['live_script_touched' => true, 'live_e2e_ran' => false]);
        $this->assertTrue($touchedNoRun['requires_live_e2e']);
        $this->assertFalse($touchedNoRun['satisfied']);

        $touchedRan = $this->service->liveScriptRequirement(['live_script_touched' => true, 'live_e2e_ran' => true]);
        $this->assertTrue($touchedRan['satisfied']);
    }

    /**
     * Regra 1 — "Alterar source, nao provider output gerado." Generated/dist
     * output is rejected; skill source is allowed.
     */
    public function testSourceOfTruthGuardRejectsGeneratedOutputAllowsSource(): void
    {
        $generated = $this->service->sourceOfTruthGuard('dist/universal.zip');
        $this->assertSame('generated', $generated['classification']);
        $this->assertFalse($generated['allowed']);

        $factoryOut = $this->service->sourceOfTruthGuard('build/generated/cursor.mdc');
        $this->assertFalse($factoryOut['allowed']);

        $src = $this->service->sourceOfTruthGuard('src/reference/design.md');
        $this->assertSame('source', $src['classification']);
        $this->assertTrue($src['allowed']);
    }

    /**
     * Build pipeline contract — a stale upstream stage taints every downstream
     * stage, so release is never ready while an earlier stage is stale.
     */
    public function testStaleUpstreamStageTaintsDownstreamAndBlocksRelease(): void
    {
        // Everything fresh EXCEPT the provider transforms stage.
        $report = $this->service->buildPipeline([
            'skill_source',
            'dist_universal_zip',
            'site_assets',
            'release',
        ]);

        $byStage = array_column($report['stages'], 'fresh', 'stage');
        $this->assertTrue($byStage['skill_source']);
        $this->assertFalse($byStage['provider_transforms']);
        // Downstream of the stale transforms stage must all be stale.
        $this->assertFalse($byStage['dist_universal_zip']);
        $this->assertFalse($byStage['site_assets']);
        $this->assertFalse($byStage['release']);
        $this->assertFalse($report['release_ready']);

        // Full fresh chain -> release ready.
        $full = $this->service->buildPipeline([
            'skill_source',
            'provider_transforms',
            'dist_universal_zip',
            'site_assets',
            'release',
        ]);
        $this->assertTrue($full['release_ready']);
    }

    /**
     * Aggregate evaluate() folds all four rules into one ship/hold decision and
     * holds when any single rule fails.
     */
    public function testEvaluateFoldsRulesIntoShipOrHold(): void
    {
        $ship = $this->service->evaluate([
            'dirty_tree' => false,
            'head_pushed' => true,
            'changelog_present' => true,
            'build_fresh' => true,
            'detector_rules_changed' => false,
            'live_script_touched' => false,
            'edited_path' => 'src/skill.md',
            'fresh_stages' => ['skill_source', 'provider_transforms', 'dist_universal_zip', 'site_assets', 'release'],
        ]);
        $this->assertSame('ship', $ship['decision']);

        // Flip a single condition (dirty tree) -> hold.
        $hold = $this->service->evaluate([
            'dirty_tree' => true,
            'head_pushed' => true,
            'changelog_present' => true,
            'build_fresh' => true,
        ]);
        $this->assertSame('hold', $hold['decision']);
    }
}
