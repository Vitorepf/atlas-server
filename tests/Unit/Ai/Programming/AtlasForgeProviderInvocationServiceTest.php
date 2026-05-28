<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming;

use App\Services\Ai\Programming\AtlasForgeProviderInvocationService;
use PHPUnit\Framework\TestCase;

/**
 * Focused contract tests for AtlasForgeProviderInvocationService (factory-critical runtime).
 */
final class AtlasForgeProviderInvocationServiceTest extends TestCase
{
    public function test_normalize_obra_id_input_fail_closed_on_blank_values(): void
    {
        $this->assertNull(AtlasForgeProviderInvocationService::normalizeObraIdInput(null));
        $this->assertNull(AtlasForgeProviderInvocationService::normalizeObraIdInput(''));
        $this->assertNull(AtlasForgeProviderInvocationService::normalizeObraIdInput('   '));
        $this->assertNull(AtlasForgeProviderInvocationService::normalizeObraIdInput(0));
        $this->assertNull(AtlasForgeProviderInvocationService::normalizeObraIdInput([]));
    }

    public function test_normalize_obra_id_input_trims_non_empty_strings(): void
    {
        $obra = '33333333-3333-3333-3333-333333333333';

        $this->assertSame($obra, AtlasForgeProviderInvocationService::normalizeObraIdInput('  '.$obra.'  '));
    }

    public function test_canonical_context_refs_include_focused_unit_test_evidence(): void
    {
        $paths = AtlasForgeProviderInvocationService::canonicalContextRefPaths();

        $this->assertContains(
            'tests/Unit/Ai/Programming/AtlasForgeProviderInvocationServiceTest.php',
            $paths,
        );
        $this->assertContains(
            'tests/Feature/Ai/Programming/AtlasForgeProviderInvocationTest.php',
            $paths,
        );
        $this->assertContains(
            'app/Services/Ai/Programming/AtlasForgeProviderInvocationService.php',
            $paths,
        );
    }

    public function test_schema_versions_are_stable(): void
    {
        $this->assertSame(
            'atlas.forge.provider_invocation.v1',
            AtlasForgeProviderInvocationService::SCHEMA_VERSION,
        );
        $this->assertSame(
            'atlas.forge.provider_invocation_receipt.v1',
            AtlasForgeProviderInvocationService::RECEIPT_SCHEMA_VERSION,
        );
    }

    public function test_canonical_blocker_codes_match_public_constants(): void
    {
        $this->assertSame(
            [
                AtlasForgeProviderInvocationService::BLOCKER_OBRA_REQUIRED,
                AtlasForgeProviderInvocationService::BLOCKER_OBRA_NOT_FOUND,
                AtlasForgeProviderInvocationService::BLOCKER_RUNTIME_DISPATCH_REQUIRED,
                AtlasForgeProviderInvocationService::BLOCKER_LIVE_DECIDE_DISPATCH_REQUIRED,
                AtlasForgeProviderInvocationService::BLOCKER_DECISION_RECEIPT_REQUIRED,
                AtlasForgeProviderInvocationService::BLOCKER_RUNTIME_DISPATCH_NOT_ALLOWED,
                AtlasForgeProviderInvocationService::BLOCKER_ROLE_INVALID,
                AtlasForgeProviderInvocationService::BLOCKER_OPERATOR_APPROVAL_REQUIRED,
                AtlasForgeProviderInvocationService::BLOCKER_BUDGET_APPROVAL_REQUIRED,
                AtlasForgeProviderInvocationService::BLOCKER_RUNTIME_DISPATCH_CONFIRMATION_REQUIRED,
                AtlasForgeProviderInvocationService::BLOCKER_PROVIDER_DRIVER_MISSING,
                AtlasForgeProviderInvocationService::BLOCKER_PROVIDER_CAPACITY_EXHAUSTED,
                AtlasForgeProviderInvocationService::BLOCKER_TIMEOUT_INVALID,
                AtlasForgeProviderInvocationService::BLOCKER_MODE_INVALID,
                AtlasForgeProviderInvocationService::BLOCKER_AWIS_EXECUTION_GATE_REQUIRED,
                AtlasForgeProviderInvocationService::BLOCKER_AWIS_EXECUTION_GATE_BLOCKED,
            ],
            AtlasForgeProviderInvocationService::canonicalBlockerCodes(),
        );
    }

    public function test_invocation_status_and_mode_constants_are_stable(): void
    {
        $this->assertSame('dry_run', AtlasForgeProviderInvocationService::MODE_DRY_RUN);
        $this->assertSame('execute', AtlasForgeProviderInvocationService::MODE_EXECUTE);
        $this->assertSame('blocked', AtlasForgeProviderInvocationService::STATUS_BLOCKED);
        $this->assertSame('planned', AtlasForgeProviderInvocationService::STATUS_PLANNED);
        $this->assertSame('executed', AtlasForgeProviderInvocationService::STATUS_EXECUTED);
    }
}
