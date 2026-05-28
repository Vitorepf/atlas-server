<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming;

use App\Services\Ai\Programming\AtlasForgeLiveExecutionService;
use PHPUnit\Framework\TestCase;

/**
 * Focused contract tests for AtlasForgeLiveExecutionService (factory-critical runtime).
 */
final class AtlasForgeLiveExecutionServiceTest extends TestCase
{
    public function test_normalize_obra_id_input_fail_closed_on_blank_values(): void
    {
        $this->assertNull(AtlasForgeLiveExecutionService::normalizeObraIdInput(null));
        $this->assertNull(AtlasForgeLiveExecutionService::normalizeObraIdInput(''));
        $this->assertNull(AtlasForgeLiveExecutionService::normalizeObraIdInput('   '));
        $this->assertNull(AtlasForgeLiveExecutionService::normalizeObraIdInput(0));
        $this->assertNull(AtlasForgeLiveExecutionService::normalizeObraIdInput([]));
    }

    public function test_normalize_obra_id_input_trims_non_empty_strings(): void
    {
        $obra = '33333333-3333-3333-3333-333333333333';

        $this->assertSame($obra, AtlasForgeLiveExecutionService::normalizeObraIdInput('  '.$obra.'  '));
    }

    public function test_canonical_context_refs_include_focused_unit_test_evidence(): void
    {
        $paths = AtlasForgeLiveExecutionService::canonicalContextRefPaths();

        $this->assertContains(
            'tests/Unit/Ai/Programming/AtlasForgeLiveExecutionServiceTest.php',
            $paths,
        );
        $this->assertContains(
            'tests/Feature/Ai/Programming/AtlasForgeLiveExecutionTest.php',
            $paths,
        );
    }
}
