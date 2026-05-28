<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming;

use App\Services\Ai\Programming\AtlasCodeForgeFastPathService;
use PHPUnit\Framework\TestCase;

/**
 * Focused contract tests for AtlasCodeForgeFastPathService (factory-critical runtime).
 */
final class AtlasCodeForgeFastPathServiceTest extends TestCase
{
    public function test_normalize_obra_id_input_fail_closed_on_blank_values(): void
    {
        $this->assertNull(AtlasCodeForgeFastPathService::normalizeObraIdInput(null));
        $this->assertNull(AtlasCodeForgeFastPathService::normalizeObraIdInput(''));
        $this->assertNull(AtlasCodeForgeFastPathService::normalizeObraIdInput('   '));
        $this->assertNull(AtlasCodeForgeFastPathService::normalizeObraIdInput(0));
        $this->assertNull(AtlasCodeForgeFastPathService::normalizeObraIdInput([]));
    }

    public function test_normalize_obra_id_input_trims_non_empty_strings(): void
    {
        $obra = '33333333-3333-3333-3333-333333333333';

        $this->assertSame($obra, AtlasCodeForgeFastPathService::normalizeObraIdInput('  '.$obra.'  '));
    }

    public function test_normalize_mode_input_defaults_to_execute_async_for_invalid_values(): void
    {
        $this->assertSame(
            AtlasCodeForgeFastPathService::MODE_EXECUTE_ASYNC,
            AtlasCodeForgeFastPathService::normalizeModeInput(null),
        );
        $this->assertSame(
            AtlasCodeForgeFastPathService::MODE_EXECUTE_ASYNC,
            AtlasCodeForgeFastPathService::normalizeModeInput(''),
        );
        $this->assertSame(
            AtlasCodeForgeFastPathService::MODE_EXECUTE_ASYNC,
            AtlasCodeForgeFastPathService::normalizeModeInput('invalid-mode'),
        );
    }

    public function test_normalize_mode_input_preserves_canonical_modes(): void
    {
        foreach (AtlasCodeForgeFastPathService::canonicalExecutionModes() as $mode) {
            $this->assertSame($mode, AtlasCodeForgeFastPathService::normalizeModeInput($mode));
            $this->assertSame($mode, AtlasCodeForgeFastPathService::normalizeModeInput('  '.$mode.'  '));
        }
    }

    public function test_canonical_context_refs_include_focused_unit_test_evidence(): void
    {
        $paths = AtlasCodeForgeFastPathService::canonicalContextRefPaths();

        $this->assertContains(
            'tests/Unit/Ai/Programming/AtlasCodeForgeFastPathServiceTest.php',
            $paths,
        );
        $this->assertContains(
            'tests/Feature/Ai/Programming/AtlasCodeForgeFastPathTest.php',
            $paths,
        );
        $this->assertContains(
            'app/Services/Ai/Programming/AtlasCodeForgeFastPathService.php',
            $paths,
        );
    }

    public function test_canonical_execution_modes_match_schema_contract(): void
    {
        $this->assertSame(
            [
                AtlasCodeForgeFastPathService::MODE_PREPARE_ONLY,
                AtlasCodeForgeFastPathService::MODE_EXECUTE_ASYNC,
                AtlasCodeForgeFastPathService::MODE_EXECUTE_SYNC,
            ],
            AtlasCodeForgeFastPathService::canonicalExecutionModes(),
        );
    }

    public function test_canonical_stages_match_schema_contract(): void
    {
        $this->assertSame(
            [
                'obra_binding',
                'workspace_binding',
                'work_item_resolution',
                'spec_plan_resolution',
                'task_queue_resolution',
                'execution_dispatch',
                'state_projection',
                'operator_next_action',
            ],
            AtlasCodeForgeFastPathService::CANONICAL_STAGES,
        );
    }

    public function test_schema_versions_are_stable(): void
    {
        $this->assertSame(
            'atlas.code.forge_fast_path.v1',
            AtlasCodeForgeFastPathService::SCHEMA_VERSION,
        );
        $this->assertSame(
            'atlas.code.forge_fast_path_run.v1',
            AtlasCodeForgeFastPathService::RUN_SCHEMA_VERSION,
        );
    }
}
