<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming;

use App\Services\Ai\Programming\AtlasForgeRuntimeDispatchService;
use PHPUnit\Framework\TestCase;

/**
 * Focused contract tests for AtlasForgeRuntimeDispatchService (factory-critical runtime).
 */
final class AtlasForgeRuntimeDispatchServiceTest extends TestCase
{
    public function test_normalize_obra_id_input_fail_closed_on_blank_values(): void
    {
        $this->assertNull(AtlasForgeRuntimeDispatchService::normalizeObraIdInput(null));
        $this->assertNull(AtlasForgeRuntimeDispatchService::normalizeObraIdInput(''));
        $this->assertNull(AtlasForgeRuntimeDispatchService::normalizeObraIdInput('   '));
        $this->assertNull(AtlasForgeRuntimeDispatchService::normalizeObraIdInput(0));
        $this->assertNull(AtlasForgeRuntimeDispatchService::normalizeObraIdInput([]));
    }

    public function test_normalize_obra_id_input_trims_non_empty_strings(): void
    {
        $obra = '33333333-3333-3333-3333-333333333333';

        $this->assertSame($obra, AtlasForgeRuntimeDispatchService::normalizeObraIdInput('  '.$obra.'  '));
    }

    public function test_canonical_context_refs_include_focused_unit_test_evidence(): void
    {
        $paths = AtlasForgeRuntimeDispatchService::canonicalContextRefPaths();

        $this->assertContains(
            'tests/Unit/Ai/Programming/AtlasForgeRuntimeDispatchServiceTest.php',
            $paths,
        );
        $this->assertContains(
            'tests/Feature/Ai/Programming/AtlasForgeRuntimeDispatchTest.php',
            $paths,
        );
        $this->assertContains(
            'app/Services/Ai/Programming/AtlasForgeRuntimeDispatchService.php',
            $paths,
        );
    }

    public function test_schema_versions_are_stable(): void
    {
        $this->assertSame(
            'atlas.forge.runtime_dispatch_plan.v1',
            AtlasForgeRuntimeDispatchService::SCHEMA_VERSION,
        );
        $this->assertSame(
            'atlas.forge.runtime_dispatch_projection.v1',
            AtlasForgeRuntimeDispatchService::PROJECTION_SCHEMA_VERSION,
        );
    }

    public function test_canonical_blocker_codes_match_public_constants(): void
    {
        $this->assertSame(
            [
                AtlasForgeRuntimeDispatchService::BLOCKER_OBRA_REQUIRED,
                AtlasForgeRuntimeDispatchService::BLOCKER_OBRA_NOT_FOUND,
                AtlasForgeRuntimeDispatchService::BLOCKER_TOPOLOGY_MISSING,
                AtlasForgeRuntimeDispatchService::BLOCKER_LIVE_DECIDE_REQUIRED,
                AtlasForgeRuntimeDispatchService::BLOCKER_DECISION_RECEIPT_REQUIRED,
                AtlasForgeRuntimeDispatchService::BLOCKER_RUNTIME_DISPATCH_NOT_ALLOWED,
                AtlasForgeRuntimeDispatchService::BLOCKER_ROLE_INVALID,
                AtlasForgeRuntimeDispatchService::BLOCKER_ROLE_MISSING_PROVIDER,
                AtlasForgeRuntimeDispatchService::BLOCKER_FALLBACK_CHILD_RECEIPT_REQUIRED,
                AtlasForgeRuntimeDispatchService::BLOCKER_CAPACITY_EXHAUSTED,
                AtlasForgeRuntimeDispatchService::BLOCKER_AWIS_EXECUTION_GATE_BLOCKED,
            ],
            AtlasForgeRuntimeDispatchService::canonicalBlockerCodes(),
        );
    }

    public function test_dispatch_status_constants_are_stable(): void
    {
        $this->assertSame('dispatch_planned', AtlasForgeRuntimeDispatchService::STATUS_DISPATCH_PLANNED);
        $this->assertSame('blocked', AtlasForgeRuntimeDispatchService::STATUS_BLOCKED);
        $this->assertSame(
            'fallback_child_receipt_required',
            AtlasForgeRuntimeDispatchService::STATUS_FALLBACK_CHILD_RECEIPT_REQUIRED,
        );
        $this->assertSame(
            'provider_capacity_exhausted',
            AtlasForgeRuntimeDispatchService::STATUS_CAPACITY_EXHAUSTED,
        );
    }
}
