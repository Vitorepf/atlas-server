<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming;

use App\Services\Ai\Programming\AtlasCodeObraCommandCenterService;
use PHPUnit\Framework\TestCase;

/**
 * Focused contract tests for AtlasCodeObraCommandCenterService (factory-critical runtime).
 */
final class AtlasCodeObraCommandCenterServiceTest extends TestCase
{
    public function test_normalize_obra_id_input_fail_closed_on_blank_values(): void
    {
        $this->assertNull(AtlasCodeObraCommandCenterService::normalizeObraIdInput(null));
        $this->assertNull(AtlasCodeObraCommandCenterService::normalizeObraIdInput(''));
        $this->assertNull(AtlasCodeObraCommandCenterService::normalizeObraIdInput('   '));
        $this->assertNull(AtlasCodeObraCommandCenterService::normalizeObraIdInput(0));
        $this->assertNull(AtlasCodeObraCommandCenterService::normalizeObraIdInput([]));
    }

    public function test_normalize_obra_id_input_trims_non_empty_strings(): void
    {
        $obra = '44444444-4444-4444-4444-444444444444';

        $this->assertSame($obra, AtlasCodeObraCommandCenterService::normalizeObraIdInput('  '.$obra.'  '));
    }

    public function test_canonical_context_refs_include_focused_unit_test_evidence(): void
    {
        $paths = AtlasCodeObraCommandCenterService::canonicalContextRefPaths();

        $this->assertContains(
            'tests/Unit/Ai/Programming/AtlasCodeObraCommandCenterServiceTest.php',
            $paths,
        );
        $this->assertContains(
            'tests/Feature/Ai/Programming/AtlasCodeObraCommandCenterTest.php',
            $paths,
        );
        $this->assertContains(
            'app/Services/Ai/Programming/AtlasCodeObraCommandCenterService.php',
            $paths,
        );
    }

    public function test_canonical_chat_message_kinds_match_schema_contract(): void
    {
        $this->assertSame(
            [
                'definition',
                'command',
                'question',
                'decision',
                'note',
                'restriction',
                'acceptance_criterion',
            ],
            AtlasCodeObraCommandCenterService::canonicalChatMessageKinds(),
        );
    }

    public function test_schema_version_is_stable(): void
    {
        $this->assertSame(
            'atlas.code.obra_command_center.v1',
            AtlasCodeObraCommandCenterService::SCHEMA_VERSION,
        );
    }
}
