<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Semantic;

use App\Services\Ai\SelfConstruction\Maestro\Semantic\AtlasMaestroSemanticAuditPanel;
use Tests\TestCase;

final class AtlasMaestroSemanticAuditPanelTest extends TestCase
{
    public function test_well_formed_packet_passes_all_three_votes(): void
    {
        $result = (new AtlasMaestroSemanticAuditPanel)->audit([
            'objective' => 'Modify AtlasTaskPacketQualityInspector safely.',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasTaskPacketQualityInspector.php'],
            'acceptance_criteria' => ['Assert AtlasTaskPacketQualityInspector resolves and still inspects packets.'],
        ]);

        $this->assertTrue($result['pass']);
        $this->assertSame([true, true, true], $result['votes']);
    }

    public function test_rejects_one_of_three_when_allowed_files_and_orphan_verifier_fail_but_symbol_resolves(): void
    {
        $result = (new AtlasMaestroSemanticAuditPanel)->audit([
            'objective' => 'Modify AtlasTaskPacketQualityInspector safely.',
            'allowed_files' => ['docs/loop-foo.md'],
            'acceptance_criteria' => ['Assert AtlasTaskPacketQualityInspector resolves.'],
            'orphan_target' => 'App\\Services\\Ai\\SelfConstruction\\Maestro\\Semantic\\AtlasMaestroSemanticAuditPanel',
            'insertion_site' => [
                'file' => 'app/Foo.php',
                'line' => 10,
                'enclosing_source' => 'public function commit(): void { (new AtlasTaskScopedCommitter())->commit(); }',
            ],
        ]);

        $this->assertFalse($result['pass']);
        $this->assertSame([false, false, true], $result['votes']);
        $this->assertSame('semantic_quorum_failed', $result['panel_reason']);
    }

    public function test_quorum_boundary_passes_with_two_of_three_votes_from_test_doubles(): void
    {
        $panel = new AtlasMaestroSemanticAuditPanel(
            allowedFilesIntentChecker: new class
            {
                public function check(array $packet): array
                {
                    return ['ok' => true];
                }
            },
            orphanCallerVerifier: new class
            {
                public function verify(array $packet): array
                {
                    return ['ok' => false];
                }
            },
            symbolResolver: new class
            {
                public function resolve(string $symbol): array
                {
                    return ['symbol' => $symbol, 'exists' => true, 'file' => 'app/X.php', 'line' => 1];
                }
            },
        );

        $result = $panel->audit([
            'acceptance_criteria' => ['Assert AtlasFakeSymbol resolves.'],
        ]);

        $this->assertTrue($result['pass']);
        $this->assertSame([true, false, true], $result['votes']);
    }
}
