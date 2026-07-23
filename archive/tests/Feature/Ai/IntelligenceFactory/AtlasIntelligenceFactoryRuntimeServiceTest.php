<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\IntelligenceFactory;

use App\Models\AtlasIntelligenceFactoryCapability;
use App\Models\AtlasIntelligenceFactoryEvolutionEvent;
use App\Models\AtlasIntelligenceFactoryGap;
use App\Services\Ai\IntelligenceFactory\AtlasIntelligenceFactoryRuntimeService;
use Tests\Concerns\CreatesIntelligenceFactoryTables;
use Tests\TestCase;

final class AtlasIntelligenceFactoryRuntimeServiceTest extends TestCase
{
    use CreatesIntelligenceFactoryTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createIntelligenceFactoryTables();
    }

    protected function tearDown(): void
    {
        $this->dropIntelligenceFactoryTables();
        parent::tearDown();
    }

    public function test_detects_youtube_capability_gap_with_hash(): void
    {
        $gap = app(AtlasIntelligenceFactoryRuntimeService::class)->detectGap([
            'objective' => 'Preciso analisar um video do YouTube em russo e salvar informacoes.',
            'domain' => 'research',
            'evidence_refs' => ['input:youtube'],
        ]);

        $this->assertSame(AtlasIntelligenceFactoryRuntimeService::GAP_SCHEMA, $gap['schema_version']);
        $this->assertSame('multimodal_video_intelligence', $gap['gap_type']);
        $this->assertSame('open', $gap['status']);
        $this->assertNotEmpty($gap['gap_hash']);
        $this->assertDatabaseCount('atlas_intelligence_factory_gaps', 1);
    }

    public function test_critical_external_action_is_blocked_without_approval(): void
    {
        $decision = app(AtlasIntelligenceFactoryRuntimeService::class)->decide([
            'objective' => 'Fazer day trade comprando e vendendo automaticamente na corretora.',
            'domain' => 'finance',
        ]);

        $this->assertSame('block', $decision['decision']);
        $this->assertSame('blocked', $decision['status']);
        $this->assertContains('human_approval_required', $decision['required_controls']);
    }

    public function test_registers_and_certifies_capability_with_evidence_but_never_auto_trusts(): void
    {
        $runtime = app(AtlasIntelligenceFactoryRuntimeService::class);
        $capability = $runtime->registerCapability([
            'capability_key' => 'engineering-tooling-patch-verifier',
            'name' => 'Patch Verifier',
            'capability_type' => 'workflow',
            'domain' => 'programming',
            'evidence_refs' => ['test:patch-verifier'],
        ]);
        $certification = $runtime->certifyCapability((string) $capability['capability_id']);

        $this->assertSame('passed', $certification['status']);
        $record = AtlasIntelligenceFactoryCapability::query()->firstOrFail();
        $this->assertSame('certified', $record->status);
        $this->assertNotSame('trusted', $record->status);
        $this->assertNotEmpty($record->certification_hash);
    }

    public function test_matching_certified_capability_is_used(): void
    {
        $runtime = app(AtlasIntelligenceFactoryRuntimeService::class);
        $runtime->registerCapability([
            'capability_key' => 'engineering_tooling_patch_verifier',
            'name' => 'Patch Verifier',
            'capability_type' => 'workflow',
            'domain' => 'programming',
            'evidence_refs' => ['test:capability'],
        ]);
        $runtime->certifyCapability((string) AtlasIntelligenceFactoryCapability::query()->firstOrFail()->id);

        $decision = $runtime->decide([
            'objective' => 'debug patch com teste no atlas dev',
            'domain' => 'programming',
            'flow_id' => 'atlas_dev',
        ]);

        $this->assertSame('use', $decision['decision']);
        $this->assertSame('ready', $decision['status']);
        $this->assertNotEmpty($decision['selected_capability_id']);
        $this->assertNotEmpty($decision['usage_event_id']);
        $this->assertNotEmpty($decision['usage_event_hash']);

        $event = AtlasIntelligenceFactoryEvolutionEvent::query()->firstOrFail();
        $this->assertSame('capability_used', $event->event_type);
        $this->assertSame('observed', $event->status);
        $this->assertSame('aseif_decision', $event->source_type);
        $this->assertFalse((bool) data_get($event->payload, 'auto_mutates_policy'));
    }

    public function test_control_plane_aggregates_without_raw_objective(): void
    {
        $runtime = app(AtlasIntelligenceFactoryRuntimeService::class);
        $runtime->advise([
            'objective' => 'Criar uma automacao segura para processar PDF grande.',
            'domain' => 'research',
            'evidence_refs' => ['e1'],
        ]);

        $report = $runtime->controlPlane();

        $this->assertSame('atlas.intelligence_factory.control_plane.v1', $report['schema_version']);
        $this->assertGreaterThanOrEqual(1, $report['summary']['open_gaps']);
        $this->assertStringNotContainsString('processar PDF grande', json_encode($report, JSON_UNESCAPED_UNICODE));
        $this->assertNotEmpty($report['control_plane_hash']);
        $this->assertSame('open', AtlasIntelligenceFactoryGap::query()->firstOrFail()->status);
    }
}
