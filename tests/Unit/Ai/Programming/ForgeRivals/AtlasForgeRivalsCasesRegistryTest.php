<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\AtlasForgeNativeRivalsCaseManifestService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsCasesRegistry;
use App\Services\Ai\Programming\ForgeRivals\EmptyPresetIsFatalHarnessBug;
use RuntimeException;
use Tests\TestCase;

/**
 * Focused contract for the Forge Rivals cases registry (factory-critical).
 */
final class AtlasForgeRivalsCasesRegistryTest extends TestCase
{
    private AtlasForgeRivalsCasesRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new AtlasForgeRivalsCasesRegistry(
            app(AtlasForgeNativeRivalsCaseManifestService::class),
        );
    }

    public function test_schema_version_exposes_factory_contract(): void
    {
        $this->assertSame(
            AtlasForgeRivalsCasesRegistry::SCHEMA_VERSION,
            $this->registry->schemaVersion(),
        );
    }

    public function test_v2_case_fields_constant_lists_nine_canonical_keys(): void
    {
        $this->assertCount(9, AtlasForgeRivalsCasesRegistry::V2_CASE_FIELDS);
        $this->assertSame(
            [
                'id',
                'objective',
                'allowed_files',
                'acceptance_criteria',
                'quick_test_command',
                'full_test_command',
                'expected_artifacts',
                'timeout_policy',
                'tags',
            ],
            AtlasForgeRivalsCasesRegistry::V2_CASE_FIELDS,
        );
    }

    public function test_smoke_preset_adapts_legacy_case_with_exact_v2_shape(): void
    {
        $cases = $this->registry->casesForPreset('smoke');

        $this->assertCount(1, $cases);
        $case = $cases[0];
        $this->assertSame(
            AtlasForgeRivalsCasesRegistry::V2_CASE_FIELDS,
            array_keys($case),
        );
        $this->assertSame('atlas-fair-claude-baseline-case-01', $case['id']);
        $this->assertContains('preset:smoke', $case['tags']);
        $this->assertContains('rivals:v2', $case['tags']);
    }

    public function test_preset_name_is_normalized_before_lookup(): void
    {
        $cases = $this->registry->casesForPreset('  SMOKE  ');

        $this->assertCount(1, $cases);
        $this->assertContains('preset:smoke', $cases[0]['tags']);
    }

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

    public function test_unknown_preset_throws_invalid_argument(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->registry->casesForPreset('weekly_extravaganza');
    }

    public function test_unadaptable_legacy_case_throws_runtime_exception(): void
    {
        $legacy = $this->createMock(AtlasForgeNativeRivalsCaseManifestService::class);
        $legacy->method('manifest')->willReturn(['case' => null]);

        $registry = new AtlasForgeRivalsCasesRegistry(
            $legacy,
            ['smoke' => ['missing-case-id']],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('could not adapt legacy case');

        $registry->casesForPreset('smoke');
    }

    public function test_default_preset_cases_cover_all_presets(): void
    {
        foreach (AtlasForgeRivalsCasesRegistry::PRESETS as $preset) {
            $this->assertArrayHasKey($preset, AtlasForgeRivalsCasesRegistry::DEFAULT_PRESET_CASES);
            $this->assertNotEmpty(AtlasForgeRivalsCasesRegistry::DEFAULT_PRESET_CASES[$preset]);
        }
    }
}
