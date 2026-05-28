<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming;

use App\Services\Ai\Programming\AtlasForgeContinuumCertificationService;
use PHPUnit\Framework\TestCase;

/**
 * Focused contract tests for AtlasForgeContinuumCertificationService (factory-critical runtime).
 */
final class AtlasForgeContinuumCertificationServiceTest extends TestCase
{
    public function test_schema_version_and_status_constants_are_stable(): void
    {
        $this->assertSame(
            'atlas.forge_continuum_certification.v1',
            AtlasForgeContinuumCertificationService::SCHEMA_VERSION,
        );
        $this->assertSame('available', AtlasForgeContinuumCertificationService::STATUS_AVAILABLE);
        $this->assertSame(
            'backend_available_ui_pending',
            AtlasForgeContinuumCertificationService::STATUS_BACKEND_AVAILABLE_UI_PENDING,
        );
        $this->assertSame(
            'available_without_obra_context',
            AtlasForgeContinuumCertificationService::STATUS_AVAILABLE_WITHOUT_OBRA_CONTEXT,
        );
        $this->assertSame(
            'blocked_obra_required_for_runtime_projection',
            AtlasForgeContinuumCertificationService::STATUS_BLOCKED_OBRA_REQUIRED,
        );
        $this->assertSame(
            'missing_artifacts',
            AtlasForgeContinuumCertificationService::STATUS_MISSING_ARTIFACTS,
        );
        $this->assertSame('blocked', AtlasForgeContinuumCertificationService::STATUS_BLOCKED);
    }

    public function test_required_invariants_are_unique_non_empty_strings(): void
    {
        $invariants = AtlasForgeContinuumCertificationService::REQUIRED_INVARIANTS;

        $this->assertNotEmpty($invariants);
        $this->assertSame($invariants, array_values(array_unique($invariants)));

        foreach ($invariants as $invariant) {
            $this->assertIsString($invariant);
            $this->assertNotSame('', $invariant);
        }
    }

    public function test_canonical_context_refs_include_focused_unit_test_evidence(): void
    {
        $paths = AtlasForgeContinuumCertificationService::canonicalContextRefPaths();

        $this->assertContains(
            'tests/Unit/Ai/Programming/AtlasForgeContinuumCertificationServiceTest.php',
            $paths,
        );
        $this->assertContains(
            'tests/Feature/Ai/Programming/AtlasForgeContinuumCertificationTest.php',
            $paths,
        );
        $this->assertContains(
            'app/Services/Ai/Programming/AtlasForgeContinuumCertificationService.php',
            $paths,
        );
    }

    public function test_canonical_context_refs_include_runtime_dispatch_evidence(): void
    {
        $paths = AtlasForgeContinuumCertificationService::canonicalContextRefPaths();

        $this->assertContains(
            'app/Services/Ai/Programming/AtlasForgeRuntimeDispatchService.php',
            $paths,
        );
        $this->assertContains(
            'tests/Feature/Ai/Programming/AtlasForgeRuntimeDispatchTest.php',
            $paths,
        );
    }
}
