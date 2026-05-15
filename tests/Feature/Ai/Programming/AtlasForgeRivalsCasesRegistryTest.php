<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Programming\AtlasForgeNativeRivalsCaseManifestService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsCasesRegistry;
use App\Services\Ai\Programming\ForgeRivals\EmptyPresetIsFatalHarnessBug;
use Tests\TestCase;

/**
 * Atlas Forge Rivals · Cases Registry contract tests.
 *
 * Two invariants are non-negotiable:
 *   - A preset that resolves to zero cases is a fatal harness bug
 *     (never a silent no-op battery).
 *   - The smoke preset wraps the legacy single case
 *     `atlas-fair-claude-baseline-case-01` and re-shapes it to the v2
 *     nine-field view with a `preset:<name>` tag.
 */
final class AtlasForgeRivalsCasesRegistryTest extends TestCase
{
    public function test_zero_case_preset_throws_fatal_harness_bug(): void
    {
        $registry = new AtlasForgeRivalsCasesRegistry(
            app(AtlasForgeNativeRivalsCaseManifestService::class),
            ['smoke' => [], 'quick' => [], 'release' => [], 'full' => []],
        );

        $this->expectException(EmptyPresetIsFatalHarnessBug::class);
        $this->expectExceptionMessageMatches('/fatal harness bug/i');

        $registry->casesForPreset('smoke');
    }

    public function test_default_smoke_preset_returns_legacy_case_with_v2_shape(): void
    {
        $cases = app(AtlasForgeRivalsCasesRegistry::class)->casesForPreset('smoke');

        $this->assertIsArray($cases);
        $this->assertCount(1, $cases);

        $case = $cases[0];
        $this->assertSame('atlas-fair-claude-baseline-case-01', $case['id']);
        $this->assertNotSame('', $case['objective']);
        $this->assertIsArray($case['allowed_files']);
        $this->assertNotEmpty($case['allowed_files']);
        $this->assertIsArray($case['acceptance_criteria']);
        $this->assertNotEmpty($case['acceptance_criteria']);
        $this->assertNotSame('', $case['quick_test_command']);
        $this->assertNotSame('', $case['full_test_command']);
        $this->assertIsArray($case['expected_artifacts']);
        $this->assertContains('replay_manifest', $case['expected_artifacts']);
        $this->assertIsArray($case['timeout_policy']);
        $this->assertArrayHasKey('hard_kill_after_seconds', $case['timeout_policy']);

        $this->assertIsArray($case['tags']);
        $this->assertContains('preset:smoke', $case['tags']);
        $this->assertContains('rivals:v2', $case['tags']);
        $this->assertContains('forge:atlas-arm', $case['tags']);
    }

    public function test_unknown_preset_throws_invalid_argument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(AtlasForgeRivalsCasesRegistry::class)->casesForPreset('weekly_extravaganza');
    }

    public function test_presets_list_canonical_four(): void
    {
        $this->assertSame(
            ['smoke', 'quick', 'release', 'full'],
            app(AtlasForgeRivalsCasesRegistry::class)->presets()
        );
    }
}
