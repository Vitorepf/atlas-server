<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming;

use App\Services\Ai\Programming\AtlasCodeForgeUxOrchestratorService;
use PHPUnit\Framework\TestCase;

/**
 * Focused contract tests for AtlasCodeForgeUxOrchestratorService (factory-critical runtime).
 */
final class AtlasCodeForgeUxOrchestratorServiceTest extends TestCase
{
    public function test_normalize_obra_id_input_fail_closed_on_blank_values(): void
    {
        $this->assertNull(AtlasCodeForgeUxOrchestratorService::normalizeObraIdInput(null));
        $this->assertNull(AtlasCodeForgeUxOrchestratorService::normalizeObraIdInput(''));
        $this->assertNull(AtlasCodeForgeUxOrchestratorService::normalizeObraIdInput('   '));
        $this->assertNull(AtlasCodeForgeUxOrchestratorService::normalizeObraIdInput(0));
        $this->assertNull(AtlasCodeForgeUxOrchestratorService::normalizeObraIdInput([]));
    }

    public function test_normalize_obra_id_input_trims_non_empty_strings(): void
    {
        $obra = '33333333-3333-3333-3333-333333333333';

        $this->assertSame($obra, AtlasCodeForgeUxOrchestratorService::normalizeObraIdInput('  '.$obra.'  '));
    }

    public function test_canonical_context_refs_include_focused_unit_test_evidence(): void
    {
        $paths = AtlasCodeForgeUxOrchestratorService::canonicalContextRefPaths();

        $this->assertContains(
            'tests/Unit/Ai/Programming/AtlasCodeForgeUxOrchestratorServiceTest.php',
            $paths,
        );
        $this->assertContains(
            'tests/Feature/Ai/Programming/AtlasCodeForgeUxOrchestratorTest.php',
            $paths,
        );
        $this->assertContains(
            'app/Services/Ai/Programming/AtlasCodeForgeUxOrchestratorService.php',
            $paths,
        );
    }

    public function test_canonical_chat_message_kinds_match_schema_contract(): void
    {
        $this->assertSame(
            ['definition', 'command', 'question', 'decision', 'note'],
            AtlasCodeForgeUxOrchestratorService::canonicalChatMessageKinds(),
        );
    }

    public function test_schema_version_is_stable(): void
    {
        $this->assertSame(
            'atlas.code.forge_ux_orchestrator.v1',
            AtlasCodeForgeUxOrchestratorService::SCHEMA_VERSION,
        );
    }
}
