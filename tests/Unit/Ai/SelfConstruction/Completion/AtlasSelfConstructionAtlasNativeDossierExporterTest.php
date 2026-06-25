<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Completion;

use App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionAtlasNativeDossierExporter;
use Tests\TestCase;

class AtlasSelfConstructionAtlasNativeDossierExporterTest extends TestCase
{
    public function test_ready_dossier_carries_atlas_native_evidence_sections_only(): void
    {
        $dossier = (new AtlasSelfConstructionAtlasNativeDossierExporter)->export($this->readyFacts());

        self::assertSame('ready', $dossier['final_state']);
        self::assertSame(AtlasSelfConstructionAtlasNativeDossierExporter::MANDATORY_FIELDS, $dossier['mandatory_fields']);
        foreach (AtlasSelfConstructionAtlasNativeDossierExporter::MANDATORY_FIELDS as $field) {
            self::assertArrayHasKey($field, $dossier['evidence_sections']);
        }
        self::assertSame('atlas_native', $dossier['evidence_sections']['owner']['final_runtime_owner']);
        self::assertSame('atlas_server', $dossier['evidence_sections']['owner']['steady_state_runtime_owner']);
        self::assertSame([], $dossier['blockers']);
    }

    public function test_hold_dossier_preserves_hold_state_and_blockers(): void
    {
        $facts = $this->readyFacts();
        $facts['finalization']['final_state'] = 'hold';
        $facts['finalization']['blockers'] = ['evidence:docs_unhealthy'];

        $dossier = (new AtlasSelfConstructionAtlasNativeDossierExporter)->export($facts);

        self::assertSame('hold', $dossier['final_state']);
        self::assertContains('evidence:docs_unhealthy', $dossier['blockers']);
    }

    public function test_blocked_dossier_preserves_blocked_state_and_blockers(): void
    {
        $facts = $this->readyFacts();
        $facts['finalization']['final_state'] = 'blocked';
        $facts['finalization']['blockers'] = ['ledger_blocker:unsafe_release', 'dependency:foo'];

        $dossier = (new AtlasSelfConstructionAtlasNativeDossierExporter)->export($facts);

        self::assertSame('blocked', $dossier['final_state']);
        self::assertSame(['ledger_blocker:unsafe_release', 'dependency:foo'], $dossier['blockers']);
        self::assertNotSame('ready', $dossier['final_state']);
    }

    public function test_missing_optional_receipt_details_default_to_empty(): void
    {
        $facts = $this->readyFacts();
        $facts['receipts'] = [['kind' => 'commit_sha']]; // missing ref + observed_at

        $dossier = (new AtlasSelfConstructionAtlasNativeDossierExporter)->export($facts);

        self::assertCount(1, $dossier['receipts']);
        self::assertSame('commit_sha', $dossier['receipts'][0]['kind']);
        self::assertSame('', $dossier['receipts'][0]['ref']);
        self::assertSame('', $dossier['receipts'][0]['observed_at']);
    }

    public function test_dossier_id_is_deterministic_for_identical_facts(): void
    {
        $exporter = new AtlasSelfConstructionAtlasNativeDossierExporter();
        $a = $exporter->export($this->readyFacts());
        $b = $exporter->export($this->readyFacts());

        self::assertSame($a['dossier_id'], $b['dossier_id']);
        self::assertStringStartsWith('atlas-dossier_', $a['dossier_id']);
    }

    public function test_dossier_id_changes_when_final_state_changes(): void
    {
        $exporter = new AtlasSelfConstructionAtlasNativeDossierExporter();
        $a = $exporter->export($this->readyFacts());
        $hold = $this->readyFacts();
        $hold['finalization']['final_state'] = 'hold';
        $b = $exporter->export($hold);

        self::assertNotSame($a['dossier_id'], $b['dossier_id']);
    }

    /**
     * @return array<string,mixed>
     */
    private function readyFacts(): array
    {
        return [
            'finalization' => [
                'final_state' => 'ready',
                'blockers' => [],
                'readiness_sections' => [
                    'evidence_verifier' => ['passed' => true, 'status' => 'atlas_native_ready'],
                    'dependency_gate' => ['passed' => true, 'status' => 'passed'],
                    'autonomy_level' => 'atlas_native_24_7',
                    'ledger' => [],
                ],
                'next_atlas_actions' => [],
            ],
            'evidence_facts' => [
                'final_runtime_owner' => 'atlas_native',
                'steady_state_runtime_owner' => 'atlas_server',
                'autonomy_dependencies' => [
                    'depends_on_operator' => false,
                    'depends_on_claude_code' => false,
                    'depends_on_codex' => false,
                    'depends_on_external_provider_network' => false,
                ],
                'serving_queue_health' => true,
                'native_worker_readiness' => true,
                'verification_court_readiness' => true,
                'merge_governor_readiness' => true,
                'rollback_readiness' => true,
                'learning_transfer_readiness' => true,
                'docs_health' => true,
                'kb_sync' => true,
                'code_index_readiness' => true,
                'multi_project_lane_readiness' => true,
            ],
            'receipts' => [
                ['kind' => 'commit_sha', 'ref' => 'abc1234', 'observed_at' => '2026-06-25T05:00:00+00:00'],
                ['kind' => 'merge_sha', 'ref' => 'def5678', 'observed_at' => '2026-06-25T05:01:00+00:00'],
            ],
        ];
    }
}
