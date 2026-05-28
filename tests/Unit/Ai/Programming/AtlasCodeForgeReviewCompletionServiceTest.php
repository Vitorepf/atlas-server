<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming;

use App\Services\Ai\Programming\AtlasCodeForgeReviewCompletionService;
use PHPUnit\Framework\TestCase;

/**
 * Focused contract tests for AtlasCodeForgeReviewCompletionService (factory-critical runtime).
 */
final class AtlasCodeForgeReviewCompletionServiceTest extends TestCase
{
    public function test_normalize_run_id_input_fail_closed_on_blank_values(): void
    {
        $this->assertNull(AtlasCodeForgeReviewCompletionService::normalizeRunIdInput(null));
        $this->assertNull(AtlasCodeForgeReviewCompletionService::normalizeRunIdInput(''));
        $this->assertNull(AtlasCodeForgeReviewCompletionService::normalizeRunIdInput('   '));
        $this->assertNull(AtlasCodeForgeReviewCompletionService::normalizeRunIdInput(0));
        $this->assertNull(AtlasCodeForgeReviewCompletionService::normalizeRunIdInput([]));
    }

    public function test_normalize_run_id_input_trims_non_empty_strings(): void
    {
        $runId = '01KRFPATHRUN1234567890ABCDEF';

        $this->assertSame($runId, AtlasCodeForgeReviewCompletionService::normalizeRunIdInput('  '.$runId.'  '));
    }

    public function test_canonical_context_refs_include_focused_unit_test_evidence(): void
    {
        $paths = AtlasCodeForgeReviewCompletionService::canonicalContextRefPaths();

        $this->assertContains(
            'tests/Unit/Ai/Programming/AtlasCodeForgeReviewCompletionServiceTest.php',
            $paths,
        );
        $this->assertContains(
            'tests/Feature/Ai/Programming/AtlasCodeForgeReviewCompletionTest.php',
            $paths,
        );
        $this->assertContains(
            'app/Services/Ai/Programming/AtlasCodeForgeReviewCompletionService.php',
            $paths,
        );
    }

    public function test_schema_versions_are_stable(): void
    {
        $this->assertSame(
            'atlas.code.forge_review_packet.v1',
            AtlasCodeForgeReviewCompletionService::REVIEW_PACKET_SCHEMA,
        );
        $this->assertSame(
            'atlas.code.forge_completion_claim.v1',
            AtlasCodeForgeReviewCompletionService::COMPLETION_CLAIM_SCHEMA,
        );
    }
}
