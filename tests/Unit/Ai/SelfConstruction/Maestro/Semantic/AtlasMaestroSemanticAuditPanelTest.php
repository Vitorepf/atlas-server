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

    public function test_proxy_only_objective_is_rejected_before_quorum(): void
    {
        $result = (new AtlasMaestroSemanticAuditPanel)->audit([
            'objective' => 'proxy-only: re-export AtlasTaskQueueRegistry under a new namespace.',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasProxyFoo.php'],
            'acceptance_criteria' => ['Assert AtlasTaskPacketQualityInspector resolves.'],
        ]);

        $this->assertFalse($result['pass']);
        $this->assertSame([], $result['votes']);
        $this->assertSame('proxy_only_work_rejected', $result['panel_reason']);
    }

    public function test_brain_lane_with_non_brain_files_is_rejected_for_lane_coherence(): void
    {
        $result = (new AtlasMaestroSemanticAuditPanel)->audit([
            'objective' => 'Update docs for the brain module.',
            'lane' => 'final-brain',
            'allowed_files' => ['docs/loop-evolution-journal/autonomous.md'],
            'acceptance_criteria' => ['No runnable test.'],
        ]);

        $this->assertFalse($result['pass']);
        $this->assertSame([], $result['votes']);
        $this->assertSame('lane_file_coherence_failed', $result['panel_reason']);
    }

    public function test_coherent_final_brain_packet_passes_all_checks(): void
    {
        $panel = new AtlasMaestroSemanticAuditPanel(
            allowedFilesIntentChecker: new class
            {
                public function check(array $packet): array { return ['ok' => true]; }
            },
            orphanCallerVerifier: new class
            {
                public function verify(array $packet): array { return ['ok' => true]; }
            },
            symbolResolver: new class
            {
                public function resolve(string $symbol): array
                {
                    return ['symbol' => $symbol, 'exists' => true, 'file' => 'app/Brain.php', 'line' => 1];
                }
            },
        );

        $result = $panel->audit([
            'objective' => 'Implement AtlasBrainNextCommand to originate tasks from the brain organ.',
            'lane' => 'final-brain',
            'allowed_files' => [
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainNextCommand.php',
                'tests/Unit/Ai/AutonomousEvolution/Brain/AtlasBrainNextCommandTest.php',
            ],
            'acceptance_criteria' => [
                'Runnable proof: /opt/homebrew/bin/php artisan test tests/Unit/Ai/AutonomousEvolution/Brain/AtlasBrainNextCommandTest.php --filter=AtlasBrainNextCommandTest',
                'Assert AtlasBrainNextCommand resolves and originates tasks.',
            ],
        ]);

        $this->assertTrue($result['pass']);
        $this->assertTrue($result['runnable_acceptance']);
        $this->assertFalse($result['duplicate_symbol_risk']);
    }

    public function test_audit_result_includes_advisory_fields_on_quorum_pass(): void
    {
        $panel = new AtlasMaestroSemanticAuditPanel(
            allowedFilesIntentChecker: new class
            {
                public function check(array $packet): array { return ['ok' => true]; }
            },
            orphanCallerVerifier: new class
            {
                public function verify(array $packet): array { return ['ok' => true]; }
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
            'acceptance_criteria' => ['Assert AtlasFoo resolves.'],
        ]);

        $this->assertTrue($result['pass']);
        $this->assertArrayHasKey('runnable_acceptance', $result);
        $this->assertArrayHasKey('duplicate_symbol_risk', $result);
        $this->assertIsBool($result['runnable_acceptance']);
        $this->assertIsBool($result['duplicate_symbol_risk']);
    }
}
