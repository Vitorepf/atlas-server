<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming;

use App\Services\Ai\Programming\AtlasForgeGovernedExecutionService;
use PHPUnit\Framework\TestCase;

/**
 * Focused contract tests for AtlasForgeGovernedExecutionService (factory-critical runtime).
 */
final class AtlasForgeGovernedExecutionServiceTest extends TestCase
{
    public function test_normalize_obra_id_input_fail_closed_on_blank_values(): void
    {
        $this->assertNull(AtlasForgeGovernedExecutionService::normalizeObraIdInput(null));
        $this->assertNull(AtlasForgeGovernedExecutionService::normalizeObraIdInput(''));
        $this->assertNull(AtlasForgeGovernedExecutionService::normalizeObraIdInput('   '));
        $this->assertNull(AtlasForgeGovernedExecutionService::normalizeObraIdInput(0));
        $this->assertNull(AtlasForgeGovernedExecutionService::normalizeObraIdInput([]));
    }

    public function test_normalize_obra_id_input_trims_non_empty_strings(): void
    {
        $obra = '33333333-3333-3333-3333-333333333333';

        $this->assertSame($obra, AtlasForgeGovernedExecutionService::normalizeObraIdInput('  '.$obra.'  '));
    }

    public function test_canonical_context_refs_include_focused_unit_test_evidence(): void
    {
        $paths = AtlasForgeGovernedExecutionService::canonicalContextRefPaths();

        $this->assertContains(
            'tests/Unit/Ai/Programming/AtlasForgeGovernedExecutionServiceTest.php',
            $paths,
        );
        $this->assertContains(
            'tests/Feature/Ai/Programming/AtlasForgeGovernedExecutionAwisTest.php',
            $paths,
        );
        $this->assertContains(
            'app/Services/Ai/Programming/AtlasForgeGovernedExecutionService.php',
            $paths,
        );
    }

    public function test_schema_version_and_execution_mode_are_stable(): void
    {
        $this->assertSame(
            'atlas.forge_governed_execution.v1',
            AtlasForgeGovernedExecutionService::SCHEMA_VERSION,
        );
        $this->assertSame(
            'governed_shadow_patch',
            AtlasForgeGovernedExecutionService::EXECUTION_MODE,
        );
    }
}
