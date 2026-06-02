<?php

declare(strict_types=1);

use App\Services\Engineering\AtlasSystemStructureService;
use App\Services\Engineering\AtlasUniversalRealityCartographyService;
use Tests\TestCase;

/**
 * Regression sentinel: the AURC complete-derived-structure layer must never be
 * absent from the ON-DISK source while its derivation source is available.
 *
 * Background (the incident this guards): while an autonomous-evolution loop was
 * active, the MAIN working tree's copy of
 * app/Services/Engineering/AtlasUniversalRealityCartographyService.php was
 * observed flapping to an OLDER revision (the parent of the commit that added the
 * layer) in which completeDerivedStructure() was ABSENT — 1372 lines instead of
 * the full version. Root cause: the loop's merge/rebase path checks out feature
 * branches in the MAIN working tree (git checkout inside base_path(), e.g.
 * LoopMergeRetryQueueService::tryRebase), transiently exposing a method-less class
 * to any php process booted in that window. A fresh
 * `php artisan atlas:universal-reality-cartography map --json` booted then emits
 * ONLY the 23-node curated_macro_projection with summary.complete_node_count=null:
 * the C1 complete-structure deliverable silently disappears.
 *
 * This test FAILS if the on-disk AURC source ever lacks the derived-layer method
 * or its wiring while AtlasSystemStructureService (the derivation source) is
 * present — pinning the invariant "if we CAN derive the complete structure, the
 * cartography source MUST carry the layer that emits it." It must NOT be satisfied
 * by weakening the layer to a hand-authored fallback: the assertions require the
 * method to delegate to AtlasSystemStructureService::deriveStructure('auto'), and
 * the runtime check proves the layer actually emits the derived structure.
 *
 * Harness contract: extends Tests\TestCase, sqlite :memory:, NEVER RefreshDatabase
 * (mirrors AtlasCartographyRealStructureTest). The source-invariant test is pure
 * filesystem; the runtime test resolves against the live index/filesystem.
 */
final class AtlasCartographyDerivedLayerSourceGuardTest extends TestCase
{
    private function aurcSourcePath(): string
    {
        return base_path('app/Services/Engineering/AtlasUniversalRealityCartographyService.php');
    }

    private function structureServiceSourcePath(): string
    {
        return base_path('app/Services/Engineering/AtlasSystemStructureService.php');
    }

    /**
     * THE GUARD the incident demands: on-disk source must carry the derived layer
     * (method + wiring + real delegation) whenever the derivation source exists.
     */
    public function test_on_disk_aurc_source_carries_the_derived_layer_when_structure_service_is_available(): void
    {
        $structurePath = $this->structureServiceSourcePath();

        // Precondition for the invariant: the derivation source must exist. In this
        // repo it always does, so assert it rather than skip silently — if it were
        // genuinely gone, the layer is allowed to honestly degrade (see the runtime
        // test's degrade branch) and this guard would not apply.
        $this->assertFileExists(
            $structurePath,
            'AtlasSystemStructureService source must exist; the complete-derived-structure layer depends on it.'
        );

        $aurcPath = $this->aurcSourcePath();
        $this->assertFileExists($aurcPath, 'AURC source must exist.');

        $source = (string) file_get_contents($aurcPath);

        // 1) The derived-layer METHOD must be DEFINED in the on-disk source.
        $this->assertMatchesRegularExpression(
            '/private\s+function\s+completeDerivedStructure\s*\(/',
            $source,
            'On-disk AURC source is missing completeDerivedStructure(): the complete-derived-structure layer has '
            .'regressed OUT of the file (e.g. a loop main-tree `git checkout` left an older revision on disk). A '
            .'fresh `php artisan atlas:universal-reality-cartography map --json` booted now would emit only the '
            .'curated 23-node projection with complete_node_count=null. Do NOT "fix" this by removing the guard — '
            .'restore the layer / make the writer atomic.'
        );

        // 2) It must be WIRED into map(): called, and emitted under both payload
        //    keys — so the method can never be present-but-orphaned.
        $this->assertStringContainsString(
            '$this->completeDerivedStructure()',
            $source,
            'completeDerivedStructure() is defined but no longer called from map() — the derived layer is orphaned.'
        );
        $this->assertStringContainsString(
            "'complete_derived_structure' => \$completeStructure",
            $source,
            "map() payload no longer carries the 'complete_derived_structure' layer key."
        );
        $this->assertStringContainsString(
            "'system_structure' => \$completeStructure",
            $source,
            "map() payload no longer carries the 'system_structure' verbatim-reconstruction key."
        );

        // 3) It must DELEGATE to the real derivation, not a hand-authored fallback —
        //    a baked structure would silently de-couple the layer from the live index.
        $this->assertMatchesRegularExpression(
            '/deriveStructure\(\s*[\'"]auto[\'"]\s*\)/',
            $source,
            "completeDerivedStructure() must delegate to AtlasSystemStructureService::deriveStructure('auto'); "
            .'a hand-authored structure would de-couple the layer from the live code index.'
        );
    }

    /**
     * Runtime proof the layer is not merely TEXTUALLY present but actually EMITS the
     * derived complete structure when the structure service is available — i.e. the
     * source method is genuinely wired through map(), and the derived layer is never
     * silently downgraded to the 23-node curated entrypoint.
     */
    public function test_live_cartography_emits_the_derived_layer_not_the_curated_projection(): void
    {
        $structure = app(AtlasSystemStructureService::class)->deriveStructure('auto');
        $payload = app(AtlasUniversalRealityCartographyService::class)->map();

        // Both layer keys must always be present and point at the same payload.
        $this->assertArrayHasKey('complete_derived_structure', $payload);
        $this->assertArrayHasKey('system_structure', $payload);
        $this->assertSame(
            $payload['complete_derived_structure'],
            $payload['system_structure'],
            'complete_derived_structure and system_structure must be the SAME derived payload (they can never disagree).'
        );

        if (($structure['available'] ?? false) === true) {
            // Derivation available (live index OR filesystem fallback): the cartography
            // MUST surface the complete structure, and it MUST be the full structure —
            // strictly larger than the curated 23-node human entrypoint.
            $this->assertTrue(
                (bool) ($payload['summary']['complete_structure_available'] ?? false),
                'structure is derivable but the cartography summary reports complete_structure_available=false — '
                .'the derived layer is not wired through map() (the regression symptom).'
            );
            $this->assertIsInt(
                $payload['summary']['complete_node_count'] ?? null,
                'complete_node_count must be an int when the structure is derivable, not null.'
            );
            $this->assertGreaterThan(
                count($payload['nodes'] ?? []),
                (int) ($payload['summary']['complete_node_count'] ?? 0),
                'the complete derived node count must EXCEED the curated macro node count — it is the FULL structure, '
                .'not the 23-node entrypoint. Equality/null here is exactly the regressed state.'
            );
            $this->assertTrue(
                (bool) ($payload['complete_derived_structure']['available'] ?? false),
                'the emitted complete_derived_structure layer must itself report available=true.'
            );
            $this->assertSame(
                'complete_derived_structure',
                $payload['complete_derived_structure']['layer'] ?? null,
                'the emitted layer must be honestly labelled the complete structure, not the curated projection.'
            );
        } else {
            // Honest degrade is ALLOWED (index and filesystem both gone) but must be
            // MIRRORED, never silently dropped — the layer key still exists and is
            // labelled, just unavailable. We never fabricate a structure to pass.
            $this->assertFalse(
                (bool) ($payload['summary']['complete_structure_available'] ?? true),
                'on an honest degrade the summary must report complete_structure_available=false.'
            );
            $this->assertSame(
                'complete_derived_structure',
                $payload['complete_derived_structure']['layer'] ?? null,
                'even degraded, the layer must remain present and labelled (mirrored honest degrade).'
            );
        }
    }
}
