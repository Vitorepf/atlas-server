<?php

namespace Tests\Feature;

use App\Jobs\ProcessAudioTranscription;
use App\Services\Ai\AiGatewayService;
use App\Services\Semantic\CurationProposalService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class CaptureTranscriptionRetryTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_text_capture_creates_ready_for_curation_event_and_semantic_clarification(): void
    {
        $this->createCaptureTables();
        $this->createSemanticCurationProposalTable();

        config()->set('atlas.token', 'test-token-with-enough-length-123');

        $this->postJson('/captures', [
            'client_id' => (string) Str::uuid(),
            'kind' => 'text',
            'domain' => 'blackink',
            'content_text' => 'Preciso de ideias de cruz para a Black Ink. Ideias que possam transformar a Black Ink em uma ferramenta unica.',
            'captured_at' => now()->toJSON(),
            'captured_timezone' => 'America/Sao_Paulo',
            'metadata' => [],
        ], [
            'X-Atlas-Token' => 'test-token-with-enough-length-123',
        ])
            ->assertCreated()
            ->assertJsonPath('metadata.semantic_events.0.type', 'capture_ready_for_curation')
            ->assertJsonPath('metadata.semantic_events.0.status', 'completed')
            ->assertJsonPath('metadata.semantic_clarification.result.suggested_type', 'synthesis')
            ->assertJsonPath('metadata.semantic_clarification.result.density.label', 'alta')
            ->assertJsonPath('metadata.semantic_clarification.result.possible_destination.note_type', 'synthesis')
            ->assertJsonPath('metadata.cognitive_quarantine.schema_version', 'atlas.capture.cognitive_quarantine.v1')
            ->assertJsonPath('metadata.cognitive_quarantine.memory_eligible', false)
            ->assertJsonPath('metadata.cognitive_quarantine.context_eligible', false)
            ->assertJsonPath('metadata.cognitive_quarantine.embedding_allowed', false)
            ->assertJsonPath('metadata.cognitive_quarantine.promotion_status', 'proposal_pending')
            ->assertJsonPath('metadata.cognitive_quarantine.review.status', 'pending')
            ->assertJsonPath('metadata.semantic_curation.schema_version', 'atlas.capture.semantic_curation_review.v1')
            ->assertJsonPath('metadata.semantic_curation.status', 'proposal_pending')
            ->assertJsonPath('review_workflow.schema_version', 'atlas.capture.review_workflow.v1')
            ->assertJsonPath('review_workflow.status', 'proposal_pending')
            ->assertJsonPath('review_workflow.human_gate', 'ratify_or_dismiss')
            ->assertJsonPath('review_workflow.actions.0.id', 'ratify_semantic_note')
            ->assertJsonPath('review_workflow.actions.1.id', 'promote_to_memory_registry')
            ->assertJsonPath('review_workflow.actions.2.id', 'promote_to_verbatim_store');

        $this->assertDatabaseHas('semantic_curation_proposals', [
            'source_type' => 'capture',
            'proposed_note_type' => 'synthesis',
            'status' => 'pending',
        ]);

        $capture = \DB::table('captures')->first();
        $metadata = json_decode($capture->metadata, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(hash('sha256', 'Preciso de ideias de cruz para a Black Ink. Ideias que possam transformar a Black Ink em uma ferramenta unica.'), $metadata['cognitive_quarantine']['content_hash']);

        $proposal = \DB::table('semantic_curation_proposals')->first();
        $this->assertSame($proposal->id, $metadata['semantic_curation']['proposal_id']);
        $this->assertSame($proposal->id, $metadata['cognitive_quarantine']['proposal']['proposal_id']);
        $proposalMetadata = json_decode($proposal->metadata, true, flags: JSON_THROW_ON_ERROR);
        $proposalFrontmatter = json_decode($proposal->proposed_frontmatter, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.capture.curation_proposal_quarantine.v1', $proposalMetadata['cognitive_quarantine']['schema_version']);
        $this->assertSame('proposal_pending', $proposalMetadata['cognitive_quarantine']['promotion_status']);
        $this->assertFalse($proposalMetadata['cognitive_quarantine']['memory_eligible']);
        $this->assertFalse($proposalMetadata['cognitive_quarantine']['context_eligible']);
        $this->assertFalse($proposalMetadata['cognitive_quarantine']['embedding_allowed']);
        $this->assertSame('pending', $proposalMetadata['cognitive_quarantine']['review']['status']);
        $this->assertSame($metadata['cognitive_quarantine']['content_hash'], $proposalMetadata['cognitive_quarantine']['content_hash']);
        $this->assertSame($capture->id, $proposalMetadata['cognitive_quarantine']['proposal']['capture_id']);
        $this->assertSame('atlas.capture.curation_proposal_quarantine.v1', $proposalFrontmatter['cognitive_quarantine']['schema_version']);
        $this->assertSame('proposal_pending', $proposalFrontmatter['cognitive_quarantine']['promotion_status']);
    }

    public function test_sensitive_capture_blocks_external_ai_and_records_redacted_audit(): void
    {
        $this->createCaptureTables();
        $this->createSemanticCurationProposalTable();
        $this->createAuditEventsTable();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        config()->set('atlas.ai.enabled', true);
        config()->set('atlas.semantic_memory.enqueue_ai_clarification', true);

        $this->postJson('/captures', [
            'client_id' => (string) Str::uuid(),
            'kind' => 'text',
            'domain' => 'saude',
            'content_text' => 'Preciso investigar um sintoma sensivel e transformar isso em uma hipotese de saude.',
            'captured_at' => now()->toJSON(),
            'captured_timezone' => 'America/Sao_Paulo',
            'metadata' => [
                'privacy' => [
                    'sensitivity' => 'sensitive',
                ],
            ],
        ], [
            'X-Atlas-Token' => 'test-token-with-enough-length-123',
        ])
            ->assertCreated()
            ->assertJsonPath('metadata.privacy.sensitivity', 'sensitive')
            ->assertJsonPath('metadata.privacy.external_ai_allowed', false);

        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'ai_external_blocked_by_privacy',
            'subject_type' => 'capture',
            'severity' => 'warning',
        ]);

        $event = \DB::table('audit_events')
            ->where('event_type', 'ai_external_blocked_by_privacy')
            ->first();
        $evidence = json_decode($event->evidence, true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($evidence['content_text']['redacted']);
        $this->assertArrayHasKey('sha256', $evidence['content_text']);

        $captureCreated = \DB::table('audit_events')
            ->where('event_type', 'capture_created')
            ->first();
        $createdEvidence = json_decode($captureCreated->evidence, true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($createdEvidence['content_text']['redacted']);
        $this->assertArrayHasKey('cognitive_quarantine', $createdEvidence);

        $proposalCreated = \DB::table('audit_events')
            ->where('event_type', 'curation_proposal_created')
            ->first();
        $proposalEvidence = json_decode($proposalCreated->evidence, true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($proposalEvidence['raw_source']['redacted']);
        $this->assertArrayHasKey('sha256', $proposalEvidence['raw_source']);
        $this->assertSame('atlas.capture.curation_proposal_quarantine.v1', $proposalEvidence['cognitive_quarantine']['schema_version']);
        $this->assertFalse($proposalEvidence['cognitive_quarantine']['memory_eligible']);
    }

    public function test_ai_gateway_blocks_sensitive_capture_source_before_queueing_job(): void
    {
        $this->createCaptureTables();
        $this->createAuditEventsTable();

        config()->set('atlas.ai.enabled', true);

        $captureId = (string) Str::uuid();
        $clientId = (string) Str::uuid();
        $now = now();

        \DB::table('captures')->insert([
            'id' => $captureId,
            'client_id' => $clientId,
            'kind' => 'text',
            'domain' => 'financas',
            'content_text' => 'Decisao financeira privada que nao deve sair do Atlas.',
            'content_file_path' => null,
            'content_duration_ms' => null,
            'content_size_bytes' => null,
            'content_sha256' => null,
            'content_mime_type' => null,
            'transcription_status' => 'na',
            'transcription_engine' => null,
            'transcription_error' => null,
            'captured_at' => $now,
            'captured_timezone' => 'America/Sao_Paulo',
            'captured_lat' => null,
            'captured_lng' => null,
            'metadata' => json_encode([
                'privacy' => [
                    'domain' => 'financas',
                    'sensitivity' => 'private',
                    'external_ai_allowed' => false,
                ],
            ], JSON_THROW_ON_ERROR),
            'pre_capture_digital_context' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        $this->expectException(RuntimeException::class);

        try {
            app(AiGatewayService::class)->enqueueInteraction('Analise esta captura privada.', [
                'source_type' => 'capture',
                'source_id' => $captureId,
                'agent_slug' => 'orquestrador',
            ]);
        } finally {
            $this->assertDatabaseHas('audit_events', [
                'event_type' => 'ai_interaction_blocked_by_privacy',
                'subject_type' => 'capture',
                'subject_id' => $captureId,
            ]);
        }
    }

    public function test_audit_suggestions_endpoint_includes_append_only_events(): void
    {
        $this->createCaptureTables();
        $this->createSemanticCurationProposalTable();
        $this->createAuditEventsTable();

        config()->set('atlas.token', 'test-token-with-enough-length-123');

        \DB::table('audit_events')->insert([
            'id' => (string) Str::uuid(),
            'event_type' => 'curation_proposal_created',
            'subject_type' => 'semantic_curation_proposal',
            'subject_id' => (string) Str::uuid(),
            'actor_type' => 'system',
            'actor_id' => null,
            'severity' => 'info',
            'summary' => 'Proposta criada por teste.',
            'evidence' => json_encode(['main_thesis' => 'Tese de teste'], JSON_THROW_ON_ERROR),
            'privacy' => json_encode(['sensitivity' => 'normal'], JSON_THROW_ON_ERROR),
            'refs' => json_encode(['proposal_id' => 'proposal-test'], JSON_THROW_ON_ERROR),
            'metadata' => '{}',
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('semantic_curation_proposals')->insert([
            'id' => (string) Str::uuid(),
            'source_type' => 'capture',
            'source_refs' => '{}',
            'proposed_note_type' => 'synthesis',
            'proposed_title' => 'Proposta de regressao',
            'proposed_summary' => 'Garante que o agregador mistura modelos e arrays sem quebrar.',
            'proposed_path' => null,
            'proposed_frontmatter' => '{}',
            'proposed_body' => null,
            'score' => 0.82,
            'reason' => 'Proposta usada para testar merge misto.',
            'status' => 'pending',
            'shown_at' => null,
            'resolved_at' => null,
            'metadata' => '{}',
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $this->getJson('/audit/suggestions?limit=5', [
            'X-Atlas-Token' => 'test-token-with-enough-length-123',
        ])
            ->assertOk()
            ->assertJsonPath('items.0.type', 'curation_proposal_created')
            ->assertJsonPath('items.0.why', 'Proposta criada por teste.')
            ->assertJsonFragment([
                'type' => 'curation_proposal',
                'title' => 'Proposta de regressao',
            ]);
    }

    public function test_short_strategic_capture_is_not_skipped_by_semantic_proposal_scan(): void
    {
        $this->createCaptureTables();
        $this->createSemanticCurationProposalTable();

        $captureId = (string) Str::uuid();
        $clientId = (string) Str::uuid();
        $now = now();

        \DB::table('captures')->insert([
            'id' => $captureId,
            'client_id' => $clientId,
            'kind' => 'text',
            'domain' => 'blackink',
            'content_text' => 'Preciso de ideias de cruz para a Black Ink. Ideias que possam transformar a Black Ink em uma ferramenta unica.',
            'content_file_path' => null,
            'content_duration_ms' => null,
            'content_size_bytes' => null,
            'content_sha256' => null,
            'content_mime_type' => null,
            'transcription_status' => 'na',
            'transcription_engine' => null,
            'transcription_error' => null,
            'captured_at' => $now,
            'captured_timezone' => 'America/Sao_Paulo',
            'captured_lat' => null,
            'captured_lng' => null,
            'metadata' => '{}',
            'pre_capture_digital_context' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        $stats = app(CurationProposalService::class)->scanRecentCaptures();

        $this->assertSame(1, $stats['created']);
        $this->assertDatabaseHas('semantic_curation_proposals', [
            'source_type' => 'capture',
            'proposed_note_type' => 'synthesis',
            'status' => 'pending',
        ]);
    }

    public function test_promote_triage_creates_curation_proposal_link_and_routed_inbox_state(): void
    {
        $this->createCaptureTables();
        $this->createSemanticCurationProposalTable();
        $this->createMemoryDeltaTable();
        $this->createDomainAndInboxTables();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];
        $captureId = (string) Str::uuid();

        \DB::table('captures')->insert([
            'id' => $captureId,
            'client_id' => (string) Str::uuid(),
            'kind' => 'text',
            'domain' => 'blackink',
            'content_text' => 'Ideia estratégica para transformar a Black Ink em um sistema único de execução.',
            'content_file_path' => null,
            'content_duration_ms' => null,
            'content_size_bytes' => null,
            'content_sha256' => null,
            'content_mime_type' => null,
            'transcription_status' => 'na',
            'transcription_engine' => null,
            'transcription_error' => null,
            'captured_at' => now(),
            'captured_timezone' => 'America/Sao_Paulo',
            'captured_lat' => null,
            'captured_lng' => null,
            'metadata' => '{}',
            'pre_capture_digital_context' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);

        $response = $this->postJson("/captures/{$captureId}/triage", [
            'action' => 'promote',
            'title' => 'Black Ink como sistema único de execução',
            'reason' => 'Tem valor estratégico.',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('capture.metadata.triage.status', 'proposed')
            ->assertJsonPath('capture.metadata.triage.destination', 'semantic_note')
            ->assertJsonPath('capture.metadata.triage.target_type', 'semantic_curation_proposal')
            ->assertJsonPath('capture.metadata.triage.knowledge_state', 'proposal_pending')
            ->assertJsonPath('capture.metadata.triage.human_gate', 'ratify_or_dismiss')
            ->assertJsonPath('capture.metadata.triage.next_action', 'ratify_proposal')
            ->assertJsonPath('capture.review_workflow.schema_version', 'atlas.capture.review_workflow.v1')
            ->assertJsonPath('capture.review_workflow.status', 'proposal_pending')
            ->assertJsonPath('capture.review_workflow.actions.0.id', 'ratify_semantic_note')
            ->assertJsonPath('capture.review_workflow.actions.1.id', 'promote_to_memory_registry')
            ->assertJsonPath('capture.review_workflow.actions.2.id', 'promote_to_verbatim_store')
            ->assertJsonPath('proposal.proposed_title', 'Black Ink como sistema único de execução');

        $proposalId = $response->json('proposal.id');
        $this->assertNotEmpty($proposalId);

        $this->assertDatabaseHas('capture_links', [
            'capture_id' => $captureId,
            'target_type' => 'semantic_curation_proposal',
            'target_id' => $proposalId,
            'relation_type' => 'triage_destination',
        ]);
        $this->assertDatabaseHas('ai_memory_deltas', [
            'scope' => 'capture:'.$captureId,
            'status' => 'pending',
            'type' => 'technical_context',
        ]);

        $delta = \DB::table('ai_memory_deltas')->where('scope', 'capture:'.$captureId)->first();
        $this->assertNotNull($delta);
        $this->assertStringContainsString('Capture candidate:', $delta->claim);
        $this->assertStringContainsString('Ideia estratégica', $delta->claim);
        $evidence = json_decode($delta->evidence, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('capture_quarantine', $evidence[0]['kind']);
        $this->assertSame($captureId, $evidence[0]['ref']);
        $this->assertSame($proposalId, $evidence[0]['proposal_id']);
        $this->assertArrayHasKey('content_hash', $evidence[0]);

        $capture = \DB::table('captures')->where('id', $captureId)->first();
        $metadata = json_decode($capture->metadata, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($delta->id, $metadata['triage']['memory_delta_id']);
        $this->assertSame('pending', $metadata['triage']['memory_delta_status']);

        $this->getJson('/inbox?status=open', $headers)
            ->assertOk()
            ->assertJsonCount(0, 'captures');

        $this->getJson('/inbox?status=routed', $headers)
            ->assertOk()
            ->assertJsonCount(1, 'captures')
            ->assertJsonPath('captures.0.id', $captureId)
            ->assertJsonPath('captures.0.review_workflow.proposal_id', $proposalId)
            ->assertJsonPath('captures.0.review_workflow.memory_delta_id', $delta->id)
            ->assertJsonPath('captures.0.review_workflow.actions.5.id', 'accept_memory_delta')
            ->assertJsonPath('captures.0.review_workflow.actions.6.id', 'reject_memory_delta');
    }

    public function test_accepting_curation_proposal_can_promote_ratified_memory_with_receipt(): void
    {
        $this->createCaptureTables();
        $this->createSemanticCurationProposalTable();
        $this->createMemoryDeltaTable();
        $this->migrateMemoryTable();
        $this->migrateVerbatimMemoryTable();
        $this->createSemanticNoteTables();
        $this->createAuditEventsTable();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        config()->set('atlas.semantic_memory.vault_path', storage_path('framework/testing/atlas-vault-'.Str::uuid()));
        $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

        $captureId = (string) Str::uuid();
        \DB::table('captures')->insert([
            'id' => $captureId,
            'client_id' => (string) Str::uuid(),
            'kind' => 'text',
            'domain' => 'blackink',
            'content_text' => 'A Black Ink precisa tratar ideias de cruz como memoria estrategica reutilizavel.',
            'content_file_path' => null,
            'content_duration_ms' => null,
            'content_size_bytes' => null,
            'content_sha256' => null,
            'content_mime_type' => null,
            'transcription_status' => 'na',
            'transcription_engine' => null,
            'transcription_error' => null,
            'captured_at' => now(),
            'captured_timezone' => 'America/Sao_Paulo',
            'captured_lat' => null,
            'captured_lng' => null,
            'metadata' => json_encode([
                'cognitive_quarantine' => [
                    'schema_version' => 'atlas.capture.cognitive_quarantine.v1',
                    'memory_eligible' => false,
                    'context_eligible' => false,
                    'embedding_allowed' => false,
                    'promotion_status' => 'unclassified',
                    'content_hash' => hash('sha256', 'A Black Ink precisa tratar ideias de cruz como memoria estrategica reutilizavel.'),
                ],
            ], JSON_THROW_ON_ERROR),
            'pre_capture_digital_context' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);

        $triage = $this->postJson("/captures/{$captureId}/triage", [
            'action' => 'promote',
            'title' => 'Ideias de cruz como memoria estrategica',
            'reason' => 'Deve virar conhecimento reutilizavel.',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('capture.metadata.triage.knowledge_state', 'proposal_pending');

        $proposalId = $triage->json('proposal.id');
        $deltaId = $triage->json('capture.metadata.triage.memory_delta_id');
        $this->assertNotEmpty($proposalId);
        $this->assertNotEmpty($deltaId);

        $this->postJson("/semantic/curation-proposals/{$proposalId}/accept", [
            'promote_to_memory' => true,
            'promote_to_verbatim' => true,
            'memory_type' => 'strategic_insight',
            'verbatim_type' => 'evidence',
            'promoted_by' => 'feature-test',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('proposal.metadata.memory_promotion.schema_version', 'atlas.memory.promotion_receipt.v1')
            ->assertJsonPath('proposal.metadata.memory_promotion.source', 'semantic_curation_proposal')
            ->assertJsonPath('proposal.metadata.memory_promotion.status', 'promoted')
            ->assertJsonPath('proposal.metadata.memory_promotion.memory_delta_id', $deltaId)
            ->assertJsonPath('proposal.metadata.verbatim_promotion.schema_version', 'atlas.verbatim_memory.promotion_receipt.v1')
            ->assertJsonPath('proposal.metadata.verbatim_promotion.status', 'promoted')
            ->assertJsonPath('note.title', 'Ideias de cruz como memoria estrategica');

        $delta = \DB::table('ai_memory_deltas')->where('id', $deltaId)->first();
        $this->assertSame('promoted', $delta->status);
        $this->assertNotEmpty($delta->promoted_memory_entry_id);

        $memory = \DB::table('atlas_memory_entries')->where('id', $delta->promoted_memory_entry_id)->first();
        $this->assertNotNull($memory);
        $this->assertSame('strategic_insight', $memory->memory_type);
        $this->assertSame('ai_memory_delta', $memory->source_type);
        $this->assertSame($deltaId, $memory->source_id);
        $memoryMetadata = json_decode($memory->metadata, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.memory.promotion_receipt.v1', $memoryMetadata['promotion_receipt']['schema_version']);
        $this->assertSame($proposalId, $memoryMetadata['promotion_receipt']['proposal_id']);
        $this->assertSame('feature-test', $memoryMetadata['promotion_receipt']['promoted_by']);

        $verbatim = \DB::table('atlas_verbatim_memories')
            ->where('source_type', 'capture')
            ->where('source_id', $captureId)
            ->first();
        $this->assertNotNull($verbatim);
        $this->assertSame('evidence', $verbatim->verbatim_type);
        $this->assertFalse((bool) $verbatim->external_ai_allowed);
        $this->assertSame('A Black Ink precisa tratar ideias de cruz como memoria estrategica reutilizavel.', $verbatim->verbatim_text);
        $verbatimMetadata = json_decode($verbatim->metadata, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.verbatim_memory.promotion_receipt.v1', $verbatimMetadata['promotion_receipt']['schema_version']);
        $this->assertSame('feature-test', $verbatimMetadata['promotion_receipt']['promoted_by']);

        $capture = \DB::table('captures')->where('id', $captureId)->first();
        $captureMetadata = json_decode($capture->metadata, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($memory->id, $captureMetadata['memory_promotion']['memory_entry_id']);
        $this->assertSame($verbatim->id, $captureMetadata['verbatim_promotion']['verbatim_memory_id']);
        $this->assertSame('promoted', $captureMetadata['triage']['memory_delta_status']);

        $audit = \DB::table('audit_events')
            ->where('event_type', 'curation_proposal_ratified')
            ->first();
        $auditEvidence = json_decode($audit->evidence, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($memory->id, $auditEvidence['memory_promotion']['memory_entry_id']);
        $this->assertSame($verbatim->id, $auditEvidence['verbatim_promotion']['verbatim_memory_id']);
        $this->assertArrayNotHasKey('raw_source', $auditEvidence);
    }

    public function test_capture_can_be_archived_through_triage_without_deleting_it(): void
    {
        $this->createCaptureTables();
        $this->createDomainAndInboxTables();

        config()->set('atlas.token', 'test-token-with-enough-length-123');

        $captureId = (string) Str::uuid();
        $clientId = (string) Str::uuid();
        $now = now();

        \DB::table('captures')->insert([
            'id' => $captureId,
            'client_id' => $clientId,
            'kind' => 'text',
            'domain' => 'blackink',
            'content_text' => 'Ideia bruta para triagem profissional do Inbox.',
            'content_file_path' => null,
            'content_duration_ms' => null,
            'content_size_bytes' => null,
            'content_sha256' => null,
            'content_mime_type' => null,
            'transcription_status' => 'na',
            'transcription_engine' => null,
            'transcription_error' => null,
            'captured_at' => $now,
            'captured_timezone' => 'America/Sao_Paulo',
            'captured_lat' => null,
            'captured_lng' => null,
            'metadata' => '{}',
            'pre_capture_digital_context' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        $this->postJson("/captures/{$captureId}/triage", [
            'action' => 'archive',
            'reason' => 'Sem uso futuro.',
            'metadata' => [
                'source' => 'inbox-test',
            ],
        ], [
            'X-Atlas-Token' => 'test-token-with-enough-length-123',
        ])
            ->assertOk()
            ->assertJsonPath('capture.metadata.triage.status', 'archived')
            ->assertJsonPath('capture.metadata.triage.destination', 'archive')
            ->assertJsonPath('capture.metadata.triage.metadata.source', 'inbox-test')
            ->assertJsonPath('capture.metadata.triage_history.0.action', 'archive')
            ->assertJsonPath('capture.metadata.triage_history.0.metadata.source', 'inbox-test')
            ->assertJsonPath('proposal', null);

        $this->assertDatabaseHas('captures', [
            'id' => $captureId,
            'deleted_at' => null,
        ]);

        $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

        $this->postJson("/captures/{$captureId}/triage", [
            'action' => 'create_task',
            'title' => 'Reabrir captura arquivada como tarefa',
            'priority' => 'urgent',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('capture.metadata.triage.destination', 'task')
            ->assertJsonPath('capture.metadata.triage.priority', 'urgent')
            ->assertJsonPath('capture.metadata.triage_history.0.previous_destination', 'archive')
            ->assertJsonPath('capture.metadata.triage_history.0.changed_destination', true);

        $this->assertDatabaseHas('atlas_tasks', [
            'source_capture_id' => $captureId,
            'title' => 'Reabrir captura arquivada como tarefa',
            'priority' => 'urgent',
            'status' => 'open',
        ]);
    }

    public function test_create_task_triage_creates_real_task_and_capture_link(): void
    {
        $this->createCaptureTables();
        $this->createDomainAndInboxTables();

        config()->set('atlas.token', 'test-token-with-enough-length-123');

        $captureId = (string) Str::uuid();
        $clientId = (string) Str::uuid();
        $now = now();

        \DB::table('captures')->insert([
            'id' => $captureId,
            'client_id' => $clientId,
            'kind' => 'text',
            'domain' => 'blackink',
            'content_text' => 'Definir a próxima ação para ideias de cruz da Black Ink.',
            'content_file_path' => null,
            'content_duration_ms' => null,
            'content_size_bytes' => null,
            'content_sha256' => null,
            'content_mime_type' => null,
            'transcription_status' => 'na',
            'transcription_engine' => null,
            'transcription_error' => null,
            'captured_at' => $now,
            'captured_timezone' => 'America/Sao_Paulo',
            'captured_lat' => null,
            'captured_lng' => null,
            'metadata' => '{}',
            'pre_capture_digital_context' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        $this->postJson("/captures/{$captureId}/triage", [
            'action' => 'create_task',
            'title' => 'Priorizar ideias de cruz da Black Ink',
            'priority' => 'high',
            'reason' => 'Precisa virar ação concreta.',
        ], [
            'X-Atlas-Token' => 'test-token-with-enough-length-123',
        ])
            ->assertOk()
            ->assertJsonPath('capture.metadata.triage.status', 'action_required')
            ->assertJsonPath('capture.metadata.triage.destination', 'task')
            ->assertJsonPath('capture.metadata.triage.target_type', 'task')
            ->assertJsonPath('capture.metadata.triage.target_title', 'Priorizar ideias de cruz da Black Ink')
            ->assertJsonPath('capture.links.0.target_type', 'task')
            ->assertJsonPath('capture.links.0.target_title', 'Priorizar ideias de cruz da Black Ink');

        $this->assertDatabaseHas('atlas_tasks', [
            'source_capture_id' => $captureId,
            'domain' => 'blackink',
            'title' => 'Priorizar ideias de cruz da Black Ink',
            'priority' => 'high',
            'status' => 'open',
            'planning_status' => 'suggested',
        ]);

        $task = \DB::table('atlas_tasks')->where('source_capture_id', $captureId)->whereNull('project_id')->first();
        $this->assertGreaterThanOrEqual(5, $task->estimated_minutes);
        $this->assertContains($task->energy_required, ['low', 'medium', 'high']);
        $this->assertGreaterThan(50, $task->priority_score);

        $this->assertDatabaseHas('capture_links', [
            'capture_id' => $captureId,
            'target_type' => 'task',
            'target_title' => 'Priorizar ideias de cruz da Black Ink',
            'relation_type' => 'triage_destination',
        ]);

        $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

        $openResponse = $this->getJson('/inbox?status=open', $headers)
            ->assertOk();
        $this->assertNotContains($captureId, array_column($openResponse->json('captures'), 'id'));

        $routedResponse = $this->getJson('/inbox?status=routed', $headers)
            ->assertOk();
        $this->assertContains($captureId, array_column($routedResponse->json('captures'), 'id'));
        $routedResponse->assertJsonPath('health.open_count', 0);

        $task = \DB::table('atlas_tasks')->where('source_capture_id', $captureId)->whereNull('project_id')->first();
        $this->assertNotNull($task);

        $projectResponse = $this->postJson("/captures/{$captureId}/triage", [
            'action' => 'create_project',
            'title' => 'Projeto de ideias de cruz da Black Ink',
            'goal' => 'Transformar ideias soltas em uma frente real.',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('capture.metadata.triage.destination', 'project')
            ->assertJsonPath('capture.metadata.triage.target_type', 'project')
            ->assertJsonPath('capture.metadata.triage.target_title', 'Projeto de ideias de cruz da Black Ink')
            ->assertJsonPath('capture.metadata.triage_history.0.previous_destination', 'task')
            ->assertJsonPath('capture.metadata.triage_history.0.previous_target_id', $task->id)
            ->assertJsonPath('capture.metadata.triage_history.0.changed_destination', true);

        $this->assertDatabaseHas('atlas_tasks', [
            'source_capture_id' => $captureId,
            'title' => 'Priorizar ideias de cruz da Black Ink',
            'status' => 'archived',
        ]);

        $project = \DB::table('atlas_projects')->where('source_capture_id', $captureId)->first();
        $this->assertNotNull($project);
        $nextTaskId = $projectResponse->json('capture.metadata.triage.active_next_task_id');
        $this->assertNotEmpty($nextTaskId);
        $this->assertSame($nextTaskId, $project->active_next_task_id);
        $this->assertNotEmpty($project->next_action);
        $this->assertDatabaseHas('atlas_tasks', [
            'id' => $nextTaskId,
            'project_id' => $project->id,
            'source_capture_id' => $captureId,
            'status' => 'open',
            'metadata->role' => 'active_next_action',
        ]);
        $this->assertDatabaseHas('atlas_project_events', [
            'project_id' => $project->id,
            'event_type' => 'next_action_created',
        ]);

        $this->postJson("/captures/{$captureId}/triage", [
            'action' => 'create_task',
            'title' => 'Voltar para tarefa da Black Ink',
            'priority' => 'urgent',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('capture.metadata.triage.destination', 'task')
            ->assertJsonPath('capture.metadata.triage.priority', 'urgent')
            ->assertJsonPath('capture.metadata.triage.target_type', 'task')
            ->assertJsonPath('capture.metadata.triage.target_id', $task->id)
            ->assertJsonPath('capture.metadata.triage.target_title', 'Voltar para tarefa da Black Ink')
            ->assertJsonPath('capture.metadata.triage_history.0.previous_destination', 'project')
            ->assertJsonPath('capture.metadata.triage_history.0.previous_target_id', $project->id)
            ->assertJsonPath('capture.metadata.triage_history.0.changed_destination', true);

        $this->assertDatabaseHas('atlas_projects', [
            'source_capture_id' => $captureId,
            'title' => 'Projeto de ideias de cruz da Black Ink',
            'status' => 'archived',
        ]);

        $this->assertDatabaseHas('atlas_tasks', [
            'id' => $task->id,
            'source_capture_id' => $captureId,
            'title' => 'Voltar para tarefa da Black Ink',
            'priority' => 'urgent',
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('atlas_tasks', [
            'id' => $nextTaskId,
            'project_id' => $project->id,
            'status' => 'archived',
        ]);
    }

    public function test_task_agenda_prioritizes_tasks_by_operational_score(): void
    {
        $this->createCaptureTables();
        $this->createDomainAndInboxTables();

        config()->set('atlas.token', 'test-token-with-enough-length-123');

        $now = now();
        $urgentId = (string) Str::uuid();
        $lowId = (string) Str::uuid();

        \DB::table('atlas_tasks')->insert([
            [
                'id' => $urgentId,
                'title' => 'Implementar agenda inteligente do Atlas',
                'description' => 'Tarefa de alto impacto que precisa de foco.',
                'status' => 'open',
                'priority' => 'urgent',
                'domain' => 'atlas',
                'source_capture_id' => null,
                'due_at' => $now->copy()->addDay(),
                'planned_for_date' => null,
                'planned_start_at' => null,
                'planned_end_at' => null,
                'estimated_minutes' => 60,
                'energy_required' => 'high',
                'urgency_score' => 92,
                'impact_score' => 94,
                'effort_score' => 55,
                'priority_score' => 92,
                'planning_status' => 'suggested',
                'completed_at' => null,
                'metadata' => '{}',
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ],
            [
                'id' => $lowId,
                'title' => 'Organizar detalhe administrativo',
                'description' => 'Pode esperar.',
                'status' => 'open',
                'priority' => 'low',
                'domain' => 'atlas',
                'source_capture_id' => null,
                'due_at' => null,
                'planned_for_date' => null,
                'planned_start_at' => null,
                'planned_end_at' => null,
                'estimated_minutes' => 15,
                'energy_required' => 'low',
                'urgency_score' => 20,
                'impact_score' => 24,
                'effort_score' => 12,
                'priority_score' => 24,
                'planning_status' => 'suggested',
                'completed_at' => null,
                'metadata' => '{}',
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ],
        ]);

        $this->getJson('/tasks/agenda?date='.$now->toDateString().'&timezone=America/Sao_Paulo&energy_level=5&capacity_minutes=120', [
            'X-Atlas-Token' => 'test-token-with-enough-length-123',
        ])
            ->assertOk()
            ->assertJsonPath('summary.focus_task', 'Implementar agenda inteligente do Atlas')
            ->assertJsonPath('tasks.0.id', $urgentId)
            ->assertJsonPath('tasks.0.bucket', 'prazo')
            ->assertJsonPath('tasks.0.recommended_start_at', fn ($value) => is_string($value) && $value !== '')
            ->assertJsonPath('tasks.1.id', $lowId);
    }

    public function test_project_api_creates_executable_project_and_agenda_task(): void
    {
        $this->createCaptureTables();
        $this->createDomainAndInboxTables();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

        $response = $this->postJson('/projects', [
            'title' => 'Estudar investimentos',
            'domain' => 'atlas',
            'goal' => 'Entender investimentos a ponto de montar uma rotina simples.',
            'priority' => 'high',
        ], $headers)
            ->assertCreated()
            ->assertJsonPath('project.title', 'Estudar investimentos')
            ->assertJsonPath('project.domain', 'atlas')
            ->assertJsonPath('project.project_type', 'study')
            ->assertJsonPath('project.execution_health.status', 'healthy')
            ->assertJsonPath('project.steps_count', 5)
            ->assertJsonPath('project.current_step.title', 'Definir pergunta de estudo')
            ->assertJsonPath('active_next_task.project.title', 'Estudar investimentos')
            ->assertJsonPath('active_next_task.project_step.title', 'Definir pergunta de estudo')
            ->assertJsonPath('active_next_task.execution_mode', 'study');

        $projectId = $response->json('project.id');
        $taskId = $response->json('active_next_task.id');
        $this->assertNotEmpty($projectId);
        $this->assertNotEmpty($taskId);

        $this->assertDatabaseHas('atlas_projects', [
            'id' => $projectId,
            'active_next_task_id' => $taskId,
            'project_type' => 'study',
            'priority' => 'high',
        ]);
        $this->assertDatabaseHas('atlas_project_steps', [
            'project_id' => $projectId,
            'step_order' => 1,
            'title' => 'Definir pergunta de estudo',
            'status' => 'active',
            'active_task_id' => $taskId,
        ]);
        $this->assertDatabaseHas('atlas_tasks', [
            'id' => $taskId,
            'project_id' => $projectId,
            'project_step_id' => $response->json('project.current_step_id'),
            'status' => 'open',
            'execution_mode' => 'study',
        ]);

        $agenda = $this->getJson('/tasks/agenda?domain=atlas&limit=5', $headers)
            ->assertOk()
            ->json('tasks');
        $agendaTask = collect($agenda)->firstWhere('id', $taskId);
        $this->assertNotNull($agendaTask);
        $this->assertSame('Estudar investimentos', $agendaTask['project_title']);

        $this->postJson("/projects/{$projectId}/next-action", [
            'title' => 'Ler 10 minutos sobre renda fixa e registrar 3 bullets',
            'priority' => 'high',
            'estimated_minutes' => 15,
        ], $headers)
            ->assertOk()
            ->assertJsonPath('project.active_next_task_id', $taskId)
            ->assertJsonPath('task.id', $taskId)
            ->assertJsonPath('task.title', 'Ler 10 minutos sobre renda fixa e registrar 3 bullets');

        $this->postJson("/tasks/{$taskId}/complete", [
            'note' => 'Primeira etapa concluída.',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('status', 'done');

        $project = \DB::table('atlas_projects')->where('id', $projectId)->first();
        $this->assertNotSame($taskId, $project->active_next_task_id);
        $this->assertNotNull($project->current_step_id);
        $this->assertDatabaseHas('atlas_project_steps', [
            'project_id' => $projectId,
            'step_order' => 1,
            'status' => 'done',
        ]);
        $this->assertDatabaseHas('atlas_project_steps', [
            'id' => $project->current_step_id,
            'project_id' => $projectId,
            'step_order' => 2,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('atlas_tasks', [
            'id' => $project->active_next_task_id,
            'project_id' => $projectId,
            'project_step_id' => $project->current_step_id,
            'status' => 'open',
        ]);

        $events = $this->getJson("/projects/{$projectId}/events", $headers)
            ->assertOk()
            ->json('events');
        $this->assertContains('next_action_updated', array_column($events, 'event_type'));
        $this->assertContains('step_completed', array_column($events, 'event_type'));
        $this->assertContains('step_activated', array_column($events, 'event_type'));
    }

    public function test_project_steps_can_be_manually_governed_without_leaking_old_tasks(): void
    {
        $this->createCaptureTables();
        $this->createDomainAndInboxTables();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

        $response = $this->postJson('/projects', [
            'title' => 'Criar app mac para Atlas',
            'domain' => 'atlas',
            'goal' => 'Construir um app mac simples para operar o Atlas.',
            'priority' => 'high',
        ], $headers)->assertCreated();

        $projectId = $response->json('project.id');
        $firstTaskId = $response->json('active_next_task.id');
        $secondStep = \DB::table('atlas_project_steps')
            ->where('project_id', $projectId)
            ->where('step_order', 2)
            ->first();
        $this->assertNotNull($secondStep);

        $activate = $this->postJson("/projects/{$projectId}/steps/{$secondStep->id}/activate", [], $headers)
            ->assertOk()
            ->assertJsonPath('project.current_step_id', $secondStep->id)
            ->assertJsonPath('step.status', 'active')
            ->assertJsonPath('active_next_task.project_step_id', $secondStep->id);

        $secondTaskId = $activate->json('active_next_task.id');
        $this->assertNotSame($firstTaskId, $secondTaskId);
        $this->assertDatabaseHas('atlas_tasks', [
            'id' => $firstTaskId,
            'status' => 'waiting',
        ]);

        $this->patchJson("/projects/{$projectId}/steps/{$secondStep->id}", [
            'status' => 'blocked',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('project.status', 'blocked')
            ->assertJsonPath('step.status', 'blocked')
            ->assertJsonPath('active_next_task', null);

        $this->assertDatabaseHas('atlas_tasks', [
            'id' => $secondTaskId,
            'status' => 'waiting',
        ]);

        $this->patchJson("/projects/{$projectId}/steps/{$secondStep->id}", [
            'status' => 'pending',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('project.status', 'active')
            ->assertJsonPath('step.status', 'pending');

        $this->patchJson("/projects/{$projectId}/steps/{$secondStep->id}", [
            'status' => 'skipped',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('step.status', 'skipped')
            ->assertJsonPath('project.current_step.step_order', 3)
            ->assertJsonPath('active_next_task.project_step.step_order', 3);

        $events = $this->getJson("/projects/{$projectId}/events", $headers)
            ->assertOk()
            ->json('events');
        $this->assertContains('step_blocked', array_column($events, 'event_type'));
        $this->assertContains('step_reopened', array_column($events, 'event_type'));
        $this->assertContains('step_skipped', array_column($events, 'event_type'));
    }

    public function test_project_status_transitions_keep_agenda_clean(): void
    {
        $this->createCaptureTables();
        $this->createDomainAndInboxTables();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

        $response = $this->postJson('/projects', [
            'title' => 'Criar app mac para Atlas',
            'domain' => 'atlas',
            'goal' => 'Construir um app mac simples para operar o Atlas.',
            'priority' => 'high',
        ], $headers)->assertCreated();

        $projectId = $response->json('project.id');
        $taskId = $response->json('active_next_task.id');
        $this->assertNotEmpty($projectId);
        $this->assertNotEmpty($taskId);

        $this->patchJson("/projects/{$projectId}", [
            'status' => 'paused',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('status', 'paused')
            ->assertJsonPath('execution_health.status', 'paused')
            ->assertJsonPath('active_next_task_id', null);

        $this->assertDatabaseHas('atlas_tasks', [
            'id' => $taskId,
            'status' => 'waiting',
        ]);
        $agendaWhilePaused = $this->getJson('/tasks/agenda?domain=atlas&limit=5', $headers)
            ->assertOk()
            ->json('tasks');
        $this->assertNull(collect($agendaWhilePaused)->firstWhere('id', $taskId));

        $this->patchJson("/projects/{$projectId}", [
            'status' => 'active',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('active_next_task_id', $taskId);

        $this->assertDatabaseHas('atlas_tasks', [
            'id' => $taskId,
            'status' => 'open',
        ]);

        $this->patchJson("/projects/{$projectId}", [
            'status' => 'completed',
        ], $headers)->assertUnprocessable();

        $this->patchJson("/projects/{$projectId}", [
            'status' => 'completed',
            'completion_outcome' => 'Protótipo encerrado manualmente.',
        ], $headers)->assertUnprocessable();

        $this->patchJson("/projects/{$projectId}", [
            'status' => 'completed',
            'completion_outcome' => 'Protótipo encerrado manualmente.',
            'completion_evidence' => 'Decisão registrada no teste.',
            'completion_note' => 'Fechamento manual porque o fluxo foi substituído.',
            'force_completion' => true,
        ], $headers)
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('execution_health.status', 'completed')
            ->assertJsonPath('active_next_task_id', null)
            ->assertJsonPath('next_action', null)
            ->assertJsonPath('metadata.created_from', 'projects.store')
            ->assertJsonPath('metadata.completion.status', 'force_completed')
            ->assertJsonPath('metadata.completion.outcome', 'Protótipo encerrado manualmente.')
            ->assertJsonPath('metadata.completion.readiness.open_task_count', 1);

        $this->assertDatabaseHas('atlas_tasks', [
            'id' => $taskId,
            'status' => 'archived',
        ]);
        $agendaAfterCompleted = $this->getJson('/tasks/agenda?domain=atlas&limit=5', $headers)
            ->assertOk()
            ->json('tasks');
        $this->assertNull(collect($agendaAfterCompleted)->firstWhere('id', $taskId));

        $events = $this->getJson("/projects/{$projectId}/events", $headers)
            ->assertOk()
            ->json('events');
        $this->assertContains('status_changed', array_column($events, 'event_type'));
    }

    public function test_project_review_queue_restores_executable_next_action(): void
    {
        $this->createCaptureTables();
        $this->createDomainAndInboxTables();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

        $response = $this->postJson('/projects', [
            'title' => 'Estudar investimentos',
            'domain' => 'atlas',
            'goal' => 'Entender investimentos sem procrastinar.',
            'priority' => 'high',
        ], $headers)->assertCreated();

        $projectId = $response->json('project.id');
        $taskId = $response->json('active_next_task.id');
        \DB::table('atlas_tasks')->where('id', $taskId)->update([
            'status' => 'archived',
            'updated_at' => now(),
        ]);
        \DB::table('atlas_projects')->where('id', $projectId)->update([
            'active_next_task_id' => null,
            'last_touched_at' => now()->subDays(9),
            'next_review_at' => now()->subDay(),
            'updated_at' => now(),
        ]);

        $queue = $this->getJson('/projects/review?domain=atlas', $headers)
            ->assertOk()
            ->assertJsonPath('summary.count', 1)
            ->assertJsonPath('items.0.project.id', $projectId)
            ->assertJsonPath('items.0.suggestion.action', 'ensure_next_action')
            ->json('items.0');

        $this->assertContains('missing_next_action', $queue['health']['reasons']);
        $this->assertContains('review_due', $queue['health']['reasons']);

        $review = $this->postJson("/projects/{$projectId}/review", [
            'action' => 'ensure_next_action',
            'next_action' => 'Ler 10 minutos sobre renda fixa e registrar 3 bullets',
            'review_interval_days' => 4,
        ], $headers)
            ->assertOk()
            ->assertJsonPath('project.execution_health.status', 'healthy')
            ->assertJsonPath('active_next_task.title', 'Ler 10 minutos sobre renda fixa e registrar 3 bullets');

        $newTaskId = $review->json('active_next_task.id');
        $this->assertNotEmpty($newTaskId);
        $this->assertNotSame($taskId, $newTaskId);
        $this->assertDatabaseHas('atlas_projects', [
            'id' => $projectId,
            'active_next_task_id' => $newTaskId,
        ]);
        $this->assertDatabaseHas('atlas_project_events', [
            'project_id' => $projectId,
            'event_type' => 'reviewed',
        ]);

        $this->getJson('/projects/review?domain=atlas', $headers)
            ->assertOk()
            ->assertJsonPath('summary.count', 0);
    }

    public function test_project_execution_start_returns_tdah_execution_packet_and_records_attempt(): void
    {
        $this->createCaptureTables();
        $this->createDomainAndInboxTables();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

        $response = $this->postJson('/projects', [
            'title' => 'Estudar investimentos',
            'domain' => 'atlas',
            'goal' => 'Entender investimentos a ponto de montar uma rotina simples.',
            'priority' => 'high',
        ], $headers)->assertCreated();

        $projectId = $response->json('project.id');
        $taskId = $response->json('active_next_task.id');

        $this->getJson("/projects/{$projectId}/execution?available_minutes=20&energy_level=2", $headers)
            ->assertOk()
            ->assertJsonPath('packet.status', 'ready')
            ->assertJsonPath('packet.task_id', $taskId)
            ->assertJsonPath('packet.timebox_minutes', 10)
            ->assertJsonPath('packet.why.0', 'É a etapa ativa 1: Definir pergunta de estudo.')
            ->assertJsonPath('packet.low_energy_action', 'Abrir a fonte e escrever uma pergunta ou três bullets, sem tentar estudar tudo.');

        $this->postJson("/projects/{$projectId}/execution/start", [
            'available_minutes' => 20,
            'energy_level' => 2,
            'environment' => 'mesa',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('active_next_task.id', $taskId)
            ->assertJsonPath('active_next_task.attempt_count', 1)
            ->assertJsonPath('active_next_task.planning_status', 'planned')
            ->assertJsonPath('packet.status', 'ready')
            ->assertJsonPath('packet.attempt_count', 1)
            ->assertJsonPath('packet.why.0', 'É a etapa ativa 1: Definir pergunta de estudo.')
            ->assertJsonPath('packet.done_when', 'Existe uma pergunta que pode ser respondida em um bloco curto.');

        $this->assertDatabaseHas('atlas_task_events', [
            'task_id' => $taskId,
            'event_type' => 'execution_started',
        ]);
        $this->assertDatabaseHas('atlas_project_events', [
            'project_id' => $projectId,
            'event_type' => 'execution_started',
        ]);
    }

    public function test_deferring_project_action_records_recovery_signal_on_project(): void
    {
        $this->createCaptureTables();
        $this->createDomainAndInboxTables();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

        $response = $this->postJson('/projects', [
            'title' => 'Criar app mac para Atlas',
            'domain' => 'atlas',
            'goal' => 'Construir um app mac simples para operar o Atlas.',
            'priority' => 'high',
        ], $headers)->assertCreated();

        $projectId = $response->json('project.id');
        $taskId = $response->json('active_next_task.id');
        $tomorrow = now('America/Sao_Paulo')->addDay()->toDateString();

        $this->postJson("/tasks/{$taskId}/defer", [
            'defer_until' => $tomorrow,
            'timezone' => 'America/Sao_Paulo',
            'reason' => 'Energia baixa, preciso reduzir o bloco.',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('planning_status', 'deferred')
            ->assertJsonPath('recovery_count', 1)
            ->assertJsonPath('failure_reason_last', 'Energia baixa, preciso reduzir o bloco.')
            ->assertJsonPath('metadata.defer.last_reason_code', 'low_energy')
            ->assertJsonPath('metadata.defer.recommended_action', 'recover')
            ->assertJsonPath('metadata.defer.suggested_recovery_minutes', 10);

        $project = \DB::table('atlas_projects')->where('id', $projectId)->first();
        $projectMetadata = is_string($project->metadata) ? json_decode($project->metadata, true) : (array) $project->metadata;
        $this->assertSame('low_energy', $projectMetadata['defer_learning']['last_reason_code']);
        $this->assertSame('recover', $projectMetadata['defer_learning']['recommended_action']);
        $this->assertNotNull($project->next_review_at);
        $event = \DB::table('atlas_project_events')
            ->where('project_id', $projectId)
            ->where('event_type', 'active_action_deferred')
            ->first();
        $this->assertNotNull($event);
        $payload = is_string($event->payload) ? json_decode($event->payload, true) : (array) $event->payload;
        $this->assertSame('low_energy', $payload['reason_code']);
        $this->assertSame('recover', $payload['recommended_action']);
    }

    public function test_due_deferred_project_returns_reason_based_review_suggestion(): void
    {
        $this->createCaptureTables();
        $this->createDomainAndInboxTables();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

        $response = $this->postJson('/projects', [
            'title' => 'Estudar investimentos',
            'domain' => 'atlas',
            'goal' => 'Entender o básico e criar rotina de estudo.',
            'priority' => 'high',
        ], $headers)->assertCreated();

        $projectId = $response->json('project.id');
        $taskId = $response->json('active_next_task.id');

        $this->postJson("/tasks/{$taskId}/defer", [
            'defer_until' => now('America/Sao_Paulo')->subDay()->toDateString(),
            'timezone' => 'America/Sao_Paulo',
            'reason_code' => 'too_big',
            'reason' => 'Está grande demais para começar.',
        ], $headers)->assertOk();

        $this->getJson('/projects/review?limit=5', $headers)
            ->assertOk()
            ->assertJsonPath('items.0.project.id', $projectId)
            ->assertJsonPath('items.0.health.deferred_ready', true)
            ->assertJsonPath('items.0.suggestion.action', 'rebuild_plan')
            ->assertJsonPath('items.0.suggestion.defer_reason_code', 'too_big')
            ->assertJsonPath('items.0.suggestion.target_minutes', 15);
    }

    public function test_project_recovery_reduces_deferred_action_to_micro_action(): void
    {
        $this->createCaptureTables();
        $this->createDomainAndInboxTables();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

        $response = $this->postJson('/projects', [
            'title' => 'Criar app mac para Atlas',
            'domain' => 'atlas',
            'goal' => 'Construir um app mac simples para operar o Atlas.',
            'priority' => 'high',
        ], $headers)->assertCreated();

        $projectId = $response->json('project.id');
        $taskId = $response->json('active_next_task.id');

        $this->postJson("/tasks/{$taskId}/defer", [
            'defer_until' => now('America/Sao_Paulo')->addDay()->toDateString(),
            'timezone' => 'America/Sao_Paulo',
            'reason' => 'Tarefa grande demais para a energia de hoje.',
        ], $headers)->assertOk();

        $this->postJson("/projects/{$projectId}/recover", [
            'target_minutes' => 10,
            'reason' => 'Retomar sem replanejar.',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('active_next_task.status', 'open')
            ->assertJsonPath('active_next_task.execution_mode', 'recovery')
            ->assertJsonPath('active_next_task.estimated_minutes', 10)
            ->assertJsonPath('active_next_task.energy_required', 'low')
            ->assertJsonPath('active_next_task.failure_reason_last', null)
            ->assertJsonPath('active_next_task.recovery_count', 2)
            ->assertJsonPath('packet.mode', 'recovery')
            ->assertJsonPath('packet.timebox_minutes', 10);

        $project = \DB::table('atlas_projects')->where('id', $projectId)->first();
        $this->assertSame($taskId, $project->active_next_task_id);
        $this->assertSame('active', $project->status);
        $this->assertDatabaseHas('atlas_project_events', [
            'project_id' => $projectId,
            'event_type' => 'recovery_action_created',
        ]);
    }

    public function test_stale_project_review_suggests_and_runs_recovery(): void
    {
        $this->createCaptureTables();
        $this->createDomainAndInboxTables();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

        $response = $this->postJson('/projects', [
            'title' => 'Estudar investimentos',
            'domain' => 'atlas',
            'goal' => 'Entender o básico e criar rotina de estudo.',
            'priority' => 'high',
        ], $headers)->assertCreated();

        $projectId = $response->json('project.id');
        \DB::table('atlas_projects')
            ->where('id', $projectId)
            ->update([
                'last_touched_at' => now()->subDays(8),
                'next_review_at' => now()->addDays(2),
                'updated_at' => now()->subDays(8),
            ]);

        $this->getJson('/projects/review?limit=5', $headers)
            ->assertOk()
            ->assertJsonPath('items.0.project.id', $projectId)
            ->assertJsonPath('items.0.suggestion.action', 'recover');

        $this->postJson("/projects/{$projectId}/review", [
            'action' => 'recover',
            'target_minutes' => 10,
            'review_interval_days' => 2,
            'note' => 'Retomar projeto parado.',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('active_next_task.execution_mode', 'recovery')
            ->assertJsonPath('active_next_task.estimated_minutes', 10)
            ->assertJsonPath('suggestion.action', 'mark_reviewed');

        $this->assertDatabaseHas('atlas_project_events', [
            'project_id' => $projectId,
            'event_type' => 'recovery_action_created',
        ]);
        $this->assertDatabaseHas('atlas_project_events', [
            'project_id' => $projectId,
            'event_type' => 'reviewed',
        ]);
    }

    public function test_completing_project_action_records_execution_learning(): void
    {
        $this->createCaptureTables();
        $this->createDomainAndInboxTables();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

        $response = $this->postJson('/projects', [
            'title' => 'Estudar investimentos',
            'domain' => 'atlas',
            'goal' => 'Entender investimentos a ponto de montar uma rotina simples.',
            'priority' => 'high',
        ], $headers)->assertCreated();

        $projectId = $response->json('project.id');
        $taskId = $response->json('active_next_task.id');

        $this->postJson("/projects/{$projectId}/execution/start", [
            'available_minutes' => 20,
        ], $headers)->assertOk();

        $this->postJson("/tasks/{$taskId}/complete", [
            'actual_minutes' => 18,
            'completion_quality' => 'complete',
            'energy_after' => 3,
            'note' => 'Fechei a pergunta de estudo.',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('metadata.execution.last_actual_minutes', 18)
            ->assertJsonPath('metadata.execution.last_estimate_ratio', 1.8)
            ->assertJsonPath('metadata.execution.calibrated_estimate_minutes', 13);

        $project = \DB::table('atlas_projects')->where('id', $projectId)->first();
        $projectMetadata = is_string($project->metadata) ? json_decode($project->metadata, true) : (array) $project->metadata;
        $this->assertSame(1, $projectMetadata['execution_learning']['completed_actions_count']);
        $this->assertSame(18, $projectMetadata['execution_learning']['last_actual_minutes']);
        $this->assertSame(1.8, $projectMetadata['execution_learning']['average_estimate_ratio']);
        $this->assertSame('underestimated', $projectMetadata['execution_learning']['estimate_bias']);
        $this->assertNotNull($project->active_next_task_id);

        $nextTask = \DB::table('atlas_tasks')->where('id', $project->active_next_task_id)->first();
        $this->assertSame(34, $nextTask->estimated_minutes);
        $nextTaskMetadata = is_string($nextTask->metadata) ? json_decode($nextTask->metadata, true) : (array) $nextTask->metadata;
        $this->assertTrue($nextTaskMetadata['estimate_calibration']['applied']);
        $this->assertSame(25, $nextTaskMetadata['estimate_calibration']['base_minutes']);
        $this->assertSame(34, $nextTaskMetadata['estimate_calibration']['calibrated_minutes']);

        $event = \DB::table('atlas_project_events')
            ->where('project_id', $projectId)
            ->where('event_type', 'execution_completed')
            ->first();
        $this->assertNotNull($event);
        $payload = is_string($event->payload) ? json_decode($event->payload, true) : (array) $event->payload;
        $this->assertSame(18, $payload['actual_minutes']);
        $this->assertSame(1.8, $payload['estimate_ratio']);
    }

    public function test_partial_project_execution_records_progress_without_advancing_step(): void
    {
        $this->createCaptureTables();
        $this->createDomainAndInboxTables();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

        $response = $this->postJson('/projects', [
            'title' => 'Estudar investimentos',
            'domain' => 'atlas',
            'goal' => 'Entender investimentos a ponto de montar uma rotina simples.',
            'priority' => 'high',
        ], $headers)->assertCreated();

        $projectId = $response->json('project.id');
        $taskId = $response->json('active_next_task.id');
        $stepId = $response->json('project.current_step_id');

        $this->postJson("/tasks/{$taskId}/complete", [
            'actual_minutes' => 12,
            'completion_quality' => 'partial',
            'energy_after' => 2,
            'outcome' => 'Li renda fixa e escrevi dois bullets.',
            'evidence' => 'Notas no caderno.',
            'next_hint' => 'Ler inflação e CDI.',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('status', 'open')
            ->assertJsonPath('completed_at', null)
            ->assertJsonPath('metadata.execution.last_completion_quality', 'partial')
            ->assertJsonPath('metadata.execution.last_action_completed', false)
            ->assertJsonPath('metadata.execution.last_outcome', 'Li renda fixa e escrevi dois bullets.');

        $project = \DB::table('atlas_projects')->where('id', $projectId)->first();
        $this->assertSame($taskId, $project->active_next_task_id);
        $this->assertSame($stepId, $project->current_step_id);
        $this->assertDatabaseHas('atlas_project_steps', [
            'id' => $stepId,
            'status' => 'active',
        ]);

        $projectMetadata = is_string($project->metadata) ? json_decode($project->metadata, true) : (array) $project->metadata;
        $this->assertSame(0, $projectMetadata['execution_learning']['completed_actions_count']);
        $this->assertSame(1, $projectMetadata['execution_learning']['execution_results_count']);
        $this->assertSame('partial', $projectMetadata['execution_learning']['last_completion_quality']);

        $events = $this->getJson("/projects/{$projectId}/events", $headers)
            ->assertOk()
            ->json('events');
        $eventTypes = array_column($events, 'event_type');
        $this->assertContains('execution_progress_recorded', $eventTypes);
        $this->assertNotContains('step_completed', $eventTypes);

        $agenda = $this->getJson('/tasks/agenda?domain=atlas&energy_level=2&capacity_minutes=90&limit=5', $headers)
            ->assertOk()
            ->json('tasks');
        $agendaTask = collect($agenda)->firstWhere('id', $taskId);
        $this->assertNotNull($agendaTask);
        $this->assertSame('continue', $agendaTask['agenda_intent']);
        $this->assertSame('continuação', $agendaTask['bucket']);
        $this->assertContains('progresso parcial recente; manter continuidade', $agendaTask['why']);
    }

    public function test_blocked_project_execution_blocks_step_without_completing_it(): void
    {
        $this->createCaptureTables();
        $this->createDomainAndInboxTables();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

        $response = $this->postJson('/projects', [
            'title' => 'Criar app mac para Atlas',
            'domain' => 'atlas',
            'goal' => 'Construir um app mac simples para operar o Atlas.',
            'priority' => 'high',
        ], $headers)->assertCreated();

        $projectId = $response->json('project.id');
        $taskId = $response->json('active_next_task.id');
        $stepId = $response->json('project.current_step_id');

        $this->postJson("/tasks/{$taskId}/complete", [
            'actual_minutes' => 8,
            'completion_quality' => 'blocked',
            'energy_after' => 1,
            'outcome' => 'Não consegui avançar porque falta escolher a stack.',
            'blocker' => 'Stack indefinida.',
            'next_hint' => 'Decidir entre SwiftUI e Electron.',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('status', 'waiting')
            ->assertJsonPath('planning_status', 'deferred')
            ->assertJsonPath('failure_reason_last', 'Stack indefinida.')
            ->assertJsonPath('metadata.execution.last_completion_quality', 'blocked')
            ->assertJsonPath('metadata.execution.last_action_completed', false);

        $this->assertDatabaseHas('atlas_projects', [
            'id' => $projectId,
            'status' => 'blocked',
            'active_next_task_id' => null,
            'current_step_id' => $stepId,
        ]);
        $this->assertDatabaseHas('atlas_project_steps', [
            'id' => $stepId,
            'status' => 'blocked',
            'active_task_id' => null,
        ]);

        $events = $this->getJson("/projects/{$projectId}/events", $headers)
            ->assertOk()
            ->json('events');
        $eventTypes = array_column($events, 'event_type');
        $this->assertContains('execution_blocked', $eventTypes);
        $this->assertContains('step_blocked', $eventTypes);
        $this->assertNotContains('step_completed', $eventTypes);
    }

    public function test_task_agenda_respects_calendar_blocks(): void
    {
        $this->createCaptureTables();
        $this->createDomainAndInboxTables();

        config()->set('atlas.token', 'test-token-with-enough-length-123');

        $now = now();
        $taskId = (string) Str::uuid();
        \DB::table('atlas_tasks')->insert([
            'id' => $taskId,
            'title' => 'Escrever especificação do fluxo de agenda',
            'description' => 'Tarefa deve entrar depois do bloco real da manhã.',
            'status' => 'open',
            'priority' => 'high',
            'domain' => 'atlas',
            'source_capture_id' => null,
            'due_at' => null,
            'planned_for_date' => null,
            'planned_start_at' => null,
            'planned_end_at' => null,
            'estimated_minutes' => 45,
            'energy_required' => 'high',
            'urgency_score' => 76,
            'impact_score' => 88,
            'effort_score' => 46,
            'priority_score' => 80,
            'planning_status' => 'suggested',
            'completed_at' => null,
            'metadata' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        $this->postJson('/calendar/blocks', [
            'block_date' => $now->toDateString(),
            'timezone' => 'America/Sao_Paulo',
            'title' => 'Reunião externa',
            'starts_at' => CarbonImmutable::parse($now->toDateString().' 09:00:00', 'America/Sao_Paulo')->toJSON(),
            'ends_at' => CarbonImmutable::parse($now->toDateString().' 10:00:00', 'America/Sao_Paulo')->toJSON(),
            'source' => 'external_calendar',
            'source_ref' => 'calendar-event-1',
            'metadata' => ['provider' => 'test'],
        ], [
            'X-Atlas-Token' => 'test-token-with-enough-length-123',
        ])
            ->assertCreated()
            ->assertJsonPath('title', 'Reunião externa')
            ->assertJsonPath('source', 'external_calendar');

        $this->getJson('/tasks/agenda?date='.$now->toDateString().'&timezone=America/Sao_Paulo&energy_level=5&capacity_minutes=120', [
            'X-Atlas-Token' => 'test-token-with-enough-length-123',
        ])
            ->assertOk()
            ->assertJsonPath('blocks.0.title', 'Reunião externa')
            ->assertJsonPath('tasks.0.id', $taskId)
            ->assertJsonPath('tasks.0.recommended_start_at', function ($value): bool {
                return is_string($value) && str_contains($value, '13:05:00.000000Z');
            });
    }

    public function test_agenda_plan_persists_task_windows_and_system_calendar_blocks_idempotently(): void
    {
        $this->createCaptureTables();
        $this->createDomainAndInboxTables();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];
        $date = CarbonImmutable::parse('2026-04-30', 'America/Sao_Paulo');
        $firstTaskId = (string) Str::uuid();
        $secondTaskId = (string) Str::uuid();
        $now = now();

        foreach ([
            [
                'id' => $firstTaskId,
                'title' => 'Executar rotina principal do Atlas',
                'priority' => 'high',
                'estimated_minutes' => 45,
                'energy_required' => 'high',
                'priority_score' => 84,
            ],
            [
                'id' => $secondTaskId,
                'title' => 'Organizar pendências rápidas',
                'priority' => 'normal',
                'estimated_minutes' => 20,
                'energy_required' => 'low',
                'priority_score' => 54,
            ],
        ] as $task) {
            \DB::table('atlas_tasks')->insert([
                'id' => $task['id'],
                'title' => $task['title'],
                'description' => null,
                'status' => 'open',
                'priority' => $task['priority'],
                'domain' => 'atlas',
                'source_capture_id' => null,
                'due_at' => null,
                'planned_for_date' => null,
                'planned_start_at' => null,
                'planned_end_at' => null,
                'estimated_minutes' => $task['estimated_minutes'],
                'energy_required' => $task['energy_required'],
                'urgency_score' => $task['priority_score'],
                'impact_score' => $task['priority_score'],
                'effort_score' => 25,
                'priority_score' => $task['priority_score'],
                'planning_status' => 'suggested',
                'completed_at' => null,
                'metadata' => '{}',
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ]);
        }

        $this->postJson('/calendar/blocks', [
            'block_date' => $date->toDateString(),
            'timezone' => 'America/Sao_Paulo',
            'title' => 'Consulta já marcada',
            'starts_at' => $date->setTime(9, 0)->toJSON(),
            'ends_at' => $date->setTime(10, 0)->toJSON(),
            'source' => 'external_calendar',
            'source_ref' => 'external-1',
            'metadata' => ['provider' => 'test'],
        ], $headers)->assertCreated();

        $this->postJson('/tasks/agenda/plan', [
            'date' => $date->toDateString(),
            'timezone' => 'America/Sao_Paulo',
            'energy_level' => 5,
            'capacity_minutes' => 120,
            'limit' => 10,
        ], $headers)
            ->assertOk()
            ->assertJsonPath('planned_count', 2)
            ->assertJsonPath('skipped_count', 0)
            ->assertJsonPath('planned_tasks.0.id', $firstTaskId);

        $firstTask = \DB::table('atlas_tasks')->where('id', $firstTaskId)->first();
        $this->assertSame('scheduled', $firstTask->planning_status);
        $this->assertStringStartsWith($date->toDateString(), (string) $firstTask->planned_for_date);
        $this->assertSame('10:05', CarbonImmutable::parse($firstTask->planned_start_at)->setTimezone('America/Sao_Paulo')->format('H:i'));

        $this->assertSame(2, \DB::table('atlas_calendar_blocks')->where('source', 'system')->count());
        $this->assertDatabaseHas('atlas_task_events', [
            'task_id' => $firstTaskId,
            'event_type' => 'agenda_planned',
            'source' => 'agenda_planner',
        ]);

        $this->postJson('/tasks/agenda/plan', [
            'date' => $date->toDateString(),
            'timezone' => 'America/Sao_Paulo',
            'energy_level' => 5,
            'capacity_minutes' => 120,
            'limit' => 10,
        ], $headers)
            ->assertOk()
            ->assertJsonPath('planned_count', 2);

        $this->assertSame(2, \DB::table('atlas_calendar_blocks')->where('source', 'system')->count());
    }

    public function test_week_agenda_distributes_tasks_without_duplicates_and_persists_idempotently(): void
    {
        $this->createCaptureTables();
        $this->createDomainAndInboxTables();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];
        $start = now()->startOfDay()->toDateString();
        $taskIds = [];

        foreach ([
            ['Planejar entrega principal', 'urgent', 94],
            ['Escrever documentação', 'high', 82],
            ['Revisar projeto parado', 'high', 74],
        ] as $index => [$title, $priority, $score]) {
            $id = (string) Str::uuid();
            $taskIds[] = $id;
            \DB::table('atlas_tasks')->insert([
                'id' => $id,
                'title' => $title,
                'description' => null,
                'status' => 'open',
                'priority' => $priority,
                'domain' => 'atlas',
                'source_capture_id' => null,
                'project_id' => null,
                'project_step_id' => null,
                'routine_id' => null,
                'routine_occurrence_date' => null,
                'due_at' => null,
                'planned_for_date' => null,
                'planned_start_at' => null,
                'planned_end_at' => null,
                'estimated_minutes' => 80,
                'energy_required' => 'high',
                'urgency_score' => $score,
                'impact_score' => $score,
                'effort_score' => 60,
                'priority_score' => $score,
                'planning_status' => 'suggested',
                'completed_at' => null,
                'execution_mode' => 'deep_work',
                'friction_level' => 50 + $index,
                'emotional_resistance' => 40,
                'clarity_level' => 70,
                'starter_step' => null,
                'minimum_viable_action' => null,
                'if_then_plan' => null,
                'reward_hint' => null,
                'failure_reason_last' => null,
                'attempt_count' => 0,
                'recovery_count' => 0,
                'metadata' => '{}',
                'created_at' => now()->subMinutes($index),
                'updated_at' => now()->subMinutes($index),
                'deleted_at' => null,
            ]);
        }

        $week = $this->getJson("/tasks/agenda/week?start_date={$start}&days=3&weekday_capacity_minutes=90&weekend_capacity_minutes=90&daily_limit=5", $headers)
            ->assertOk()
            ->assertJsonPath('summary.task_count', 3)
            ->json();

        $scheduledIds = collect($week['days'])
            ->flatMap(fn (array $day): array => array_column($day['agenda']['tasks'], 'id'))
            ->values()
            ->all();
        $this->assertCount(3, $scheduledIds);
        $this->assertSame($scheduledIds, array_values(array_unique($scheduledIds)));

        $response = $this->postJson('/tasks/agenda/week/plan', [
            'start_date' => $start,
            'timezone' => 'America/Sao_Paulo',
            'days' => 3,
            'weekday_capacity_minutes' => 90,
            'weekend_capacity_minutes' => 90,
            'daily_limit' => 5,
            'create_blocks' => true,
        ], $headers)
            ->assertOk()
            ->assertJsonPath('planned_count', 3);

        $plannedDates = collect($response->json('planned_tasks'))->pluck('date')->unique()->values()->all();
        $this->assertCount(3, $plannedDates);
        foreach ($taskIds as $taskId) {
            $this->assertDatabaseHas('atlas_tasks', [
                'id' => $taskId,
                'planning_status' => 'scheduled',
            ]);
        }
        $this->assertSame(3, \DB::table('atlas_calendar_blocks')->where('source', 'system')->count());

        $this->postJson('/tasks/agenda/week/plan', [
            'start_date' => $start,
            'timezone' => 'America/Sao_Paulo',
            'days' => 3,
            'weekday_capacity_minutes' => 90,
            'weekend_capacity_minutes' => 90,
            'daily_limit' => 5,
            'create_blocks' => true,
        ], $headers)->assertOk();

        $this->assertSame(3, \DB::table('atlas_calendar_blocks')->where('source', 'system')->count());
    }

    public function test_task_can_be_scheduled_deferred_completed_and_explained_by_events(): void
    {
        $this->createCaptureTables();
        $this->createDomainAndInboxTables();

        config()->set('atlas.token', 'test-token-with-enough-length-123');

        $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];
        $now = now();
        $taskId = (string) Str::uuid();
        \DB::table('atlas_tasks')->insert([
            'id' => $taskId,
            'title' => 'Revisar tarefas do Atlas',
            'description' => 'Fluxo precisa aceitar edição e conclusão.',
            'status' => 'open',
            'priority' => 'normal',
            'domain' => 'atlas',
            'source_capture_id' => null,
            'due_at' => null,
            'planned_for_date' => null,
            'planned_start_at' => null,
            'planned_end_at' => null,
            'estimated_minutes' => 25,
            'energy_required' => 'medium',
            'urgency_score' => 50,
            'impact_score' => 50,
            'effort_score' => 25,
            'priority_score' => 50,
            'planning_status' => 'suggested',
            'completed_at' => null,
            'metadata' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        $this->postJson("/tasks/{$taskId}/schedule", [
            'planned_start_at' => $now->copy()->setTime(15, 0)->toJSON(),
            'estimated_minutes' => 40,
            'timezone' => 'America/Sao_Paulo',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('planning_status', 'scheduled')
            ->assertJsonPath('estimated_minutes', 40);

        $this->postJson("/tasks/{$taskId}/defer", [
            'defer_until' => $now->copy()->addDay()->toDateString(),
            'timezone' => 'America/Sao_Paulo',
            'reason' => 'Fazer amanhã.',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('planning_status', 'deferred')
            ->assertJsonPath('planned_for_date', $now->copy()->addDay()->toDateString());

        $this->postJson('/calendar/blocks', [
            'block_date' => $now->toDateString(),
            'timezone' => 'America/Sao_Paulo',
            'title' => 'Revisar tarefas do Atlas',
            'starts_at' => CarbonImmutable::parse($now->toDateString().' 16:00:00', 'America/Sao_Paulo')->toJSON(),
            'ends_at' => CarbonImmutable::parse($now->toDateString().' 16:40:00', 'America/Sao_Paulo')->toJSON(),
            'source' => 'external_calendar',
            'source_ref' => 'apple-event-1',
            'task_id' => $taskId,
            'metadata' => ['provider' => 'apple_calendar'],
        ], $headers)
            ->assertCreated()
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('source_ref', 'apple-event-1');

        $this->postJson("/tasks/{$taskId}/complete", [
            'note' => 'Concluída no teste.',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('completed_at', fn ($value) => is_string($value) && $value !== '');

        $this->getJson("/tasks/{$taskId}/events", $headers)
            ->assertOk()
            ->assertJsonFragment(['event_type' => 'completed'])
            ->assertJsonFragment(['event_type' => 'scheduled'])
            ->assertJsonFragment(['event_type' => 'deferred'])
            ->assertJsonFragment(['event_type' => 'calendar_block_created']);
    }

    public function test_capture_with_destination_link_is_not_treated_as_open_or_without_destination(): void
    {
        $this->createCaptureTables();
        $this->createDomainAndInboxTables();

        config()->set('atlas.token', 'test-token-with-enough-length-123');

        $captureId = (string) Str::uuid();
        $noteId = (string) Str::uuid();
        $now = now();

        \DB::table('captures')->insert([
            'id' => $captureId,
            'client_id' => (string) Str::uuid(),
            'kind' => 'text',
            'domain' => 'blackink',
            'content_text' => 'Uma proposta automática já virou nota viva e não deve seguir aberta.',
            'content_file_path' => null,
            'content_duration_ms' => null,
            'content_size_bytes' => null,
            'content_sha256' => null,
            'content_mime_type' => null,
            'transcription_status' => 'na',
            'transcription_engine' => null,
            'transcription_error' => null,
            'captured_at' => $now,
            'captured_timezone' => 'America/Sao_Paulo',
            'captured_lat' => null,
            'captured_lng' => null,
            'metadata' => '{}',
            'pre_capture_digital_context' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        \DB::table('capture_links')->insert([
            'id' => (string) Str::uuid(),
            'capture_id' => $captureId,
            'target_type' => 'semantic_note',
            'target_id' => $noteId,
            'target_title' => 'Nota viva aceita',
            'relation_type' => 'triage_destination',
            'metadata' => json_encode(['action' => 'curation_proposal_accepted'], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

        $openResponse = $this->getJson('/inbox?status=open', $headers)->assertOk();
        $this->assertNotContains($captureId, array_column($openResponse->json('captures'), 'id'));

        $withoutDestinationResponse = $this->getJson('/inbox?status=no_destination', $headers)->assertOk();
        $this->assertNotContains($captureId, array_column($withoutDestinationResponse->json('captures'), 'id'));

        $candidateResponse = $this->getJson('/inbox?status=candidate', $headers)->assertOk();
        $this->assertNotContains($captureId, array_column($candidateResponse->json('captures'), 'id'));

        $routedResponse = $this->getJson('/inbox?status=routed', $headers)->assertOk();
        $this->assertContains($captureId, array_column($routedResponse->json('captures'), 'id'));

        $this->postJson("/captures/{$captureId}/triage", [
            'action' => 'create_task',
            'title' => 'Transformar nota viva em tarefa operacional',
            'priority' => 'high',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('capture.metadata.triage.destination', 'task')
            ->assertJsonPath('capture.metadata.triage.priority', 'high')
            ->assertJsonPath('capture.metadata.triage_history.0.previous_destination', 'semantic_note')
            ->assertJsonPath('capture.metadata.triage_history.0.previous_target_id', $noteId)
            ->assertJsonPath('capture.metadata.triage_history.0.changed_destination', true);

        $this->assertDatabaseHas('atlas_tasks', [
            'source_capture_id' => $captureId,
            'title' => 'Transformar nota viva em tarefa operacional',
            'priority' => 'high',
            'status' => 'open',
        ]);
    }

    public function test_bulk_triage_can_reclassify_mixed_open_and_routed_captures(): void
    {
        $this->createCaptureTables();
        $this->createDomainAndInboxTables();

        config()->set('atlas.token', 'test-token-with-enough-length-123');

        $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];
        $now = now();
        $openCaptureId = (string) Str::uuid();
        $routedCaptureId = (string) Str::uuid();

        foreach ([
            [$openCaptureId, 'Captura aberta que deve permanecer aberta.'],
            [$routedCaptureId, 'Captura que vai virar tarefa antes do bulk.'],
        ] as [$captureId, $text]) {
            \DB::table('captures')->insert([
                'id' => $captureId,
                'client_id' => (string) Str::uuid(),
                'kind' => 'text',
                'domain' => 'blackink',
                'content_text' => $text,
                'content_file_path' => null,
                'content_duration_ms' => null,
                'content_size_bytes' => null,
                'content_sha256' => null,
                'content_mime_type' => null,
                'transcription_status' => 'na',
                'transcription_engine' => null,
                'transcription_error' => null,
                'captured_at' => $now,
                'captured_timezone' => 'America/Sao_Paulo',
                'captured_lat' => null,
                'captured_lng' => null,
                'metadata' => '{}',
                'pre_capture_digital_context' => '{}',
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ]);
        }

        $this->postJson("/captures/{$routedCaptureId}/triage", [
            'action' => 'create_task',
            'title' => 'Destino final antes do bulk',
        ], $headers)->assertOk();

        $task = \DB::table('atlas_tasks')->where('source_capture_id', $routedCaptureId)->first();
        $this->assertNotNull($task);

        $this->postJson('/inbox/bulk', [
            'capture_ids' => [$openCaptureId, $routedCaptureId],
            'action' => 'archive',
            'reason' => 'Bulk deve reclassificar destinos existentes.',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('updated_count', 2);

        $openCapture = \DB::table('captures')->where('id', $openCaptureId)->first();
        $openMetadata = json_decode($openCapture->metadata, true, flags: JSON_THROW_ON_ERROR);
        $routedCapture = \DB::table('captures')->where('id', $routedCaptureId)->first();
        $routedMetadata = json_decode($routedCapture->metadata, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('archive', $openMetadata['triage']['destination']);
        $this->assertSame('archive', $routedMetadata['triage']['destination']);
        $this->assertSame('task', $routedMetadata['triage_history'][0]['previous_destination']);
        $this->assertSame($task->id, $routedMetadata['triage_history'][0]['previous_target_id']);

        $this->assertDatabaseHas('atlas_tasks', [
            'id' => $task->id,
            'status' => 'archived',
        ]);
    }

    public function test_snoozed_capture_leaves_open_inbox_and_can_be_listed_until_it_returns(): void
    {
        $this->createCaptureTables();
        $this->createDomainAndInboxTables();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];
        $captureId = (string) Str::uuid();
        $now = now();

        \DB::table('captures')->insert([
            'id' => $captureId,
            'client_id' => (string) Str::uuid(),
            'kind' => 'text',
            'domain' => 'blackink',
            'content_text' => 'Captura que deve voltar depois.',
            'content_file_path' => null,
            'content_duration_ms' => null,
            'content_size_bytes' => null,
            'content_sha256' => null,
            'content_mime_type' => null,
            'transcription_status' => 'na',
            'transcription_engine' => null,
            'transcription_error' => null,
            'captured_at' => $now,
            'captured_timezone' => 'America/Sao_Paulo',
            'captured_lat' => null,
            'captured_lng' => null,
            'metadata' => '{}',
            'pre_capture_digital_context' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        $snoozedUntil = now()->addDays(2)->toJSON();
        $this->postJson("/captures/{$captureId}/triage", [
            'action' => 'snooze',
            'snoozed_until' => $snoozedUntil,
            'reason' => 'Voltar depois.',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('capture.metadata.triage.status', 'snoozed')
            ->assertJsonPath('capture.metadata.triage.snoozed_until', $snoozedUntil);

        $this->getJson('/inbox?status=open', $headers)
            ->assertOk()
            ->assertJsonCount(0, 'captures')
            ->assertJsonPath('health.open_count', 0);

        $this->getJson('/inbox?status=snoozed', $headers)
            ->assertOk()
            ->assertJsonCount(1, 'captures')
            ->assertJsonPath('captures.0.id', $captureId);

        $capture = \DB::table('captures')->where('id', $captureId)->first();
        $metadata = json_decode($capture->metadata, true, flags: JSON_THROW_ON_ERROR);
        $metadata['triage']['snoozed_until'] = now()->subMinute()->toJSON();
        \DB::table('captures')->where('id', $captureId)->update([
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
        ]);

        $this->getJson('/inbox?status=open', $headers)
            ->assertOk()
            ->assertJsonCount(1, 'captures')
            ->assertJsonPath('captures.0.id', $captureId)
            ->assertJsonPath('health.open_count', 1)
            ->assertJsonPath('health.no_destination_count', 1)
            ->assertJsonPath('health.curation_candidate_count', 1);

        $this->getJson('/inbox?status=no_destination', $headers)
            ->assertOk()
            ->assertJsonCount(1, 'captures')
            ->assertJsonPath('captures.0.id', $captureId);

        $this->getJson('/inbox?status=candidate', $headers)
            ->assertOk()
            ->assertJsonCount(1, 'captures')
            ->assertJsonPath('captures.0.id', $captureId);

        $this->getJson('/inbox?status=snoozed', $headers)
            ->assertOk()
            ->assertJsonCount(0, 'captures');
    }

    public function test_recurring_routine_generates_one_real_task_for_today_and_feeds_agenda(): void
    {
        $this->createCaptureTables();
        $this->createDomainAndInboxTables();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];
        $today = CarbonImmutable::parse('2026-04-30', 'America/Sao_Paulo');
        $frozenNow = $today->setTime(7, 0)->setTimezone('UTC');
        Carbon::setTestNow($frozenNow);
        CarbonImmutable::setTestNow($frozenNow);

        $routineId = $this->postJson('/routines', [
            'title' => 'Revisar plano do Atlas',
            'domain' => 'atlas',
            'frequency' => 'daily',
            'timezone' => 'America/Sao_Paulo',
            'preferred_time' => '08:30',
            'estimated_minutes' => 20,
            'priority' => 'high',
            'energy_required' => 'medium',
            'execution_mode' => 'maintenance',
            'starter_step' => 'Abrir a agenda e escolher o primeiro bloco.',
            'minimum_viable_action' => 'Revisar uma única ação.',
        ], $headers)
            ->assertCreated()
            ->assertJsonPath('title', 'Revisar plano do Atlas')
            ->assertJsonPath('next_occurrence_date', $today->toDateString())
            ->json('id');

        $this->postJson('/routines/generate-due', [
            'date' => $today->toDateString(),
            'timezone' => 'America/Sao_Paulo',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('generated_count', 1)
            ->assertJsonPath('created_count', 1)
            ->assertJsonPath('tasks.0.routine_id', $routineId)
            ->assertJsonPath('tasks.0.routine_occurrence_date', $today->toDateString())
            ->assertJsonPath('tasks.0.planning_status', 'scheduled');

        $this->postJson('/routines/generate-due', [
            'date' => $today->toDateString(),
            'timezone' => 'America/Sao_Paulo',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('generated_count', 0)
            ->assertJsonPath('created_count', 0);

        $this->assertDatabaseCount('atlas_tasks', 1);
        $this->assertDatabaseHas('atlas_tasks', [
            'routine_id' => $routineId,
            'title' => 'Revisar plano do Atlas',
            'status' => 'open',
        ]);
        $taskRow = \DB::table('atlas_tasks')->where('routine_id', $routineId)->first();
        $this->assertStringStartsWith($today->toDateString(), (string) $taskRow->routine_occurrence_date);
        $this->assertSame('08:30', CarbonImmutable::parse((string) $taskRow->planned_start_at, 'UTC')->setTimezone('America/Sao_Paulo')->format('H:i'));

        $agenda = $this->getJson('/tasks/agenda?date='.$today->toDateString().'&timezone=America/Sao_Paulo&energy_level=3&capacity_minutes=120', $headers)
            ->assertOk()
            ->json('tasks');

        $this->assertCount(1, $agenda);
        $this->assertSame($routineId, $agenda[0]['routine_id']);
        $this->assertSame('Revisar plano do Atlas', $agenda[0]['routine_title']);
        $this->assertSame('08:30', CarbonImmutable::parse($agenda[0]['planned_start_at'])->setTimezone('America/Sao_Paulo')->format('H:i'));

        $this->postJson('/tasks/'.$agenda[0]['id'].'/complete', [
            'completed_at' => $today->setTime(9, 0)->toJSON(),
            'note' => 'Rotina feita.',
        ], $headers)->assertOk();

        $routine = \DB::table('atlas_routines')->where('id', $routineId)->first();
        $metadata = json_decode($routine->metadata, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $metadata['streak']['current']);
        $this->assertSame(1, $metadata['streak']['best']);
        $this->assertSame($today->toDateString(), $metadata['streak']['last_completed_date']);

        $this->assertDatabaseHas('atlas_routine_events', [
            'routine_id' => $routineId,
            'event_type' => 'occurrence_completed',
        ]);
    }

    public function test_failed_audio_capture_can_be_requeued_for_transcription(): void
    {
        $this->createCaptureTables();
        Queue::fake();
        Storage::fake('atlas');

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        config()->set('atlas.transcription.enabled', true);

        $captureId = (string) Str::uuid();
        $clientId = (string) Str::uuid();
        $now = now();

        Storage::disk('atlas')->put('audio/test.m4a', 'audio-bytes');

        \DB::table('captures')->insert([
            'id' => $captureId,
            'client_id' => $clientId,
            'kind' => 'audio',
            'domain' => 'blackink',
            'content_text' => null,
            'content_file_path' => 'audio/test.m4a',
            'content_duration_ms' => 1200,
            'content_size_bytes' => 11,
            'content_sha256' => hash('sha256', 'audio-bytes'),
            'content_mime_type' => 'audio/x-m4a',
            'transcription_status' => 'failed',
            'transcription_engine' => null,
            'transcription_error' => 'previous failure',
            'captured_at' => $now,
            'captured_timezone' => 'America/Sao_Paulo',
            'captured_lat' => null,
            'captured_lng' => null,
            'metadata' => '{}',
            'pre_capture_digital_context' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        $this->postJson("/captures/{$captureId}/transcription/retry", [], [
            'X-Atlas-Token' => 'test-token-with-enough-length-123',
        ])
            ->assertAccepted()
            ->assertJsonPath('transcription_status', 'pending')
            ->assertJsonPath('transcription_error', null)
            ->assertJsonPath('content_file_exists', true)
            ->assertJsonPath('content_file_integrity', 'available');

        $this->assertDatabaseHas('transcription_jobs', [
            'capture_id' => $captureId,
            'status' => 'queued',
        ]);

        Queue::assertPushed(ProcessAudioTranscription::class);
    }

    private function createCaptureTables(): void
    {
        Schema::dropIfExists('capture_links');
        Schema::dropIfExists('ai_memory_deltas');
        Schema::dropIfExists('atlas_calendar_blocks');
        Schema::dropIfExists('atlas_project_blockers');
        Schema::dropIfExists('atlas_task_events');
        Schema::dropIfExists('atlas_routine_events');
        Schema::dropIfExists('atlas_project_events');
        Schema::dropIfExists('atlas_tasks');
        Schema::dropIfExists('atlas_routines');
        Schema::dropIfExists('atlas_project_steps');
        Schema::dropIfExists('atlas_projects');
        Schema::dropIfExists('atlas_domains');
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('transcription_jobs');
        Schema::dropIfExists('semantic_curation_proposals');
        Schema::dropIfExists('captures');

        Schema::create('captures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('client_id')->unique();
            $table->string('kind');
            $table->string('domain');
            $table->text('content_text')->nullable();
            $table->string('content_file_path')->nullable();
            $table->integer('content_duration_ms')->nullable();
            $table->integer('content_size_bytes')->nullable();
            $table->string('content_sha256')->nullable();
            $table->string('content_mime_type')->nullable();
            $table->string('transcription_status')->default('pending');
            $table->string('transcription_engine')->nullable();
            $table->text('transcription_error')->nullable();
            $table->timestamp('captured_at');
            $table->string('captured_timezone');
            $table->decimal('captured_lat', 10, 7)->nullable();
            $table->decimal('captured_lng', 10, 7)->nullable();
            $table->json('metadata')->default('{}');
            $table->json('pre_capture_digital_context')->default('{}');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('transcription_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('capture_id');
            $table->string('status')->default('queued');
            $table->integer('attempts')->default(0);
            $table->integer('max_attempts')->default(3);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    private function createDomainAndInboxTables(): void
    {
        Schema::create('atlas_domains', function (Blueprint $table): void {
            $table->string('slug')->primary();
            $table->string('label');
            $table->text('description')->nullable();
            $table->string('color_light')->default('#1B3A57');
            $table->string('color_dark')->default('#6892B5');
            $table->string('default_sensitivity')->default('normal');
            $table->string('external_ai_policy')->default('allow');
            $table->boolean('active')->default(true);
            $table->integer('sort_order')->default(100);
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        foreach (config('atlas.domains.defaults') as $domain) {
            \DB::table('atlas_domains')->insert([
                'slug' => $domain['slug'],
                'label' => $domain['label'],
                'description' => $domain['description'] ?? null,
                'color_light' => $domain['color_light'],
                'color_dark' => $domain['color_dark'],
                'default_sensitivity' => $domain['default_sensitivity'],
                'external_ai_policy' => $domain['external_ai_policy'] ?? 'allow',
                'active' => $domain['active'] ?? true,
                'sort_order' => $domain['sort_order'] ?? 100,
                'metadata' => '{}',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::create('atlas_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('open');
            $table->string('priority')->default('normal');
            $table->string('domain');
            $table->uuid('source_capture_id')->nullable();
            $table->uuid('project_id')->nullable();
            $table->uuid('project_step_id')->nullable();
            $table->uuid('routine_id')->nullable();
            $table->date('routine_occurrence_date')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->date('planned_for_date')->nullable();
            $table->timestamp('planned_start_at')->nullable();
            $table->timestamp('planned_end_at')->nullable();
            $table->integer('estimated_minutes')->default(25);
            $table->string('energy_required')->default('medium');
            $table->smallInteger('urgency_score')->default(50);
            $table->smallInteger('impact_score')->default(50);
            $table->smallInteger('effort_score')->default(50);
            $table->smallInteger('priority_score')->default(50);
            $table->string('planning_status')->default('unscheduled');
            $table->timestamp('completed_at')->nullable();
            $table->string('execution_mode')->default('quick_win');
            $table->smallInteger('friction_level')->default(50);
            $table->smallInteger('emotional_resistance')->default(50);
            $table->smallInteger('clarity_level')->default(60);
            $table->text('starter_step')->nullable();
            $table->text('minimum_viable_action')->nullable();
            $table->text('if_then_plan')->nullable();
            $table->text('reward_hint')->nullable();
            $table->text('failure_reason_last')->nullable();
            $table->integer('attempt_count')->default(0);
            $table->integer('recovery_count')->default(0);
            $table->json('metadata')->default('{}');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('atlas_routines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('active');
            $table->string('domain');
            $table->uuid('source_capture_id')->nullable();
            $table->uuid('project_id')->nullable();
            $table->string('frequency')->default('daily');
            $table->json('weekdays')->default('[]');
            $table->string('timezone')->default('UTC');
            $table->string('preferred_time')->nullable();
            $table->integer('estimated_minutes')->default(25);
            $table->string('energy_required')->default('medium');
            $table->string('priority')->default('normal');
            $table->string('execution_mode')->default('maintenance');
            $table->smallInteger('friction_level')->default(45);
            $table->smallInteger('emotional_resistance')->default(35);
            $table->smallInteger('clarity_level')->default(75);
            $table->text('starter_step')->nullable();
            $table->text('minimum_viable_action')->nullable();
            $table->text('if_then_plan')->nullable();
            $table->text('reward_hint')->nullable();
            $table->date('next_occurrence_date')->nullable();
            $table->date('last_generated_for_date')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('atlas_routine_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('routine_id');
            $table->string('event_type');
            $table->string('source')->default('app');
            $table->json('payload')->default('{}');
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('atlas_task_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('task_id');
            $table->string('event_type');
            $table->string('source')->default('app');
            $table->json('payload')->default('{}');
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('atlas_calendar_blocks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->date('block_date');
            $table->string('timezone');
            $table->string('title');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('source')->default('manual');
            $table->string('source_ref')->nullable();
            $table->uuid('task_id')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('atlas_projects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('active');
            $table->string('domain');
            $table->uuid('source_capture_id')->nullable();
            $table->text('goal')->nullable();
            $table->text('next_action')->nullable();
            $table->string('project_type')->default('personal');
            $table->text('desired_outcome')->nullable();
            $table->text('minimum_viable_outcome')->nullable();
            $table->text('definition_of_done')->nullable();
            $table->text('why_now')->nullable();
            $table->timestamp('deadline_at')->nullable();
            $table->string('deadline_kind')->default('none');
            $table->string('priority')->default('normal');
            $table->string('energy_profile')->default('mixed');
            $table->string('avoidance_reason')->default('unknown');
            $table->uuid('active_next_task_id')->nullable();
            $table->uuid('current_step_id')->nullable();
            $table->timestamp('last_touched_at')->nullable();
            $table->timestamp('next_review_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('paused_until')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('atlas_project_steps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('active_task_id')->nullable();
            $table->integer('step_order');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('pending');
            $table->string('step_type')->default('action');
            $table->text('expected_output')->nullable();
            $table->text('acceptance_criteria')->nullable();
            $table->integer('estimated_minutes')->default(25);
            $table->string('energy_required')->default('medium');
            $table->smallInteger('friction_level')->default(50);
            $table->json('metadata')->default('{}');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('atlas_project_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->string('event_type');
            $table->string('source')->default('app');
            $table->json('payload')->default('{}');
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('atlas_project_blockers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('task_id')->nullable();
            $table->uuid('project_step_id')->nullable();
            $table->uuid('unblock_task_id')->nullable();
            $table->string('status')->default('open');
            $table->string('severity')->default('medium');
            $table->string('reason_code')->default('other');
            $table->text('description');
            $table->text('unblock_next_action')->nullable();
            $table->string('waiting_on')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->uuid('created_from_event_id')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('capture_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('capture_id');
            $table->string('target_type');
            $table->uuid('target_id')->nullable();
            $table->string('target_title')->nullable();
            $table->string('relation_type')->default('triage_destination');
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });
    }

    private function createSemanticCurationProposalTable(): void
    {
        Schema::create('semantic_curation_proposals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source_type');
            $table->json('source_refs')->default('{}');
            $table->string('proposed_note_type');
            $table->string('proposed_title');
            $table->text('proposed_summary');
            $table->string('proposed_path')->nullable();
            $table->json('proposed_frontmatter')->default('{}');
            $table->text('proposed_body')->nullable();
            $table->decimal('score', 4, 3)->nullable();
            $table->text('reason');
            $table->string('status')->default('pending');
            $table->timestamp('shown_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });
    }

    private function createMemoryDeltaTable(): void
    {
        Schema::create('ai_memory_deltas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('source_trace_id')->nullable()->index();
            $table->uuid('source_session_id')->nullable()->index();
            $table->string('source_workspace')->nullable();
            $table->string('type', 32)->default('process');
            $table->text('claim');
            $table->json('evidence');
            $table->string('scope', 255)->default('global');
            $table->float('confidence')->default(0.5);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->json('use_when')->nullable();
            $table->json('do_not_use_when')->nullable();
            $table->boolean('requires_confirmation')->default(true);
            $table->string('status', 16)->default('pending');
            $table->uuid('superseded_by')->nullable();
            $table->uuid('promoted_memory_entry_id')->nullable()->index();
            $table->timestamp('promoted_at')->nullable()->index();
            $table->timestamps();
        });
    }

    private function migrateMemoryTable(): void
    {
        Schema::dropIfExists('atlas_memory_entry_relations');
        Schema::dropIfExists('atlas_memory_entries');

        (require database_path('migrations/2026_05_02_000000_create_atlas_memory_entries_table.php'))->up();
        (require database_path('migrations/2026_05_02_003000_create_atlas_memory_entry_relations_table.php'))->up();
        (require database_path('migrations/2026_05_02_005000_add_privacy_columns_to_atlas_memory_entries.php'))->up();
    }

    private function migrateVerbatimMemoryTable(): void
    {
        Schema::dropIfExists('atlas_verbatim_memories');

        (require database_path('migrations/2026_05_02_004000_create_atlas_verbatim_memories_table.php'))->up();
    }

    private function createSemanticNoteTables(): void
    {
        Schema::dropIfExists('semantic_note_links');
        Schema::dropIfExists('semantic_notes');

        Schema::create('semantic_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('note_key')->unique();
            $table->string('path')->unique();
            $table->string('title');
            $table->string('type');
            $table->string('status');
            $table->string('confidence')->default('low');
            $table->string('maturity')->default('draft');
            $table->json('domains')->default('[]');
            $table->text('summary')->nullable();
            $table->text('body_excerpt')->nullable();
            $table->json('frontmatter')->default('{}');
            $table->json('when_to_use')->default('[]');
            $table->json('trigger_signals')->default('[]');
            $table->json('do_not_use_when')->default('[]');
            $table->json('postgres_refs')->default('[]');
            $table->string('content_hash', 64);
            $table->timestamp('indexed_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_activated_at')->nullable();
            $table->timestamp('last_practiced_at')->nullable();
            $table->unsignedInteger('activation_count')->default(0);
            $table->float('usefulness_avg')->nullable();
            $table->json('validation_errors')->default('[]');
            $table->json('metadata')->default('{}');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('semantic_note_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('source_note_id');
            $table->uuid('target_note_id');
            $table->string('link_type');
            $table->text('explanation');
            $table->string('created_by')->default('atlas_suggestion');
            $table->float('confidence')->nullable();
            $table->boolean('confirmed_by_operator')->default(false);
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });
    }

    private function createAuditEventsTable(): void
    {
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_type');
            $table->string('subject_type')->nullable();
            $table->uuid('subject_id')->nullable();
            $table->string('actor_type')->default('system');
            $table->string('actor_id')->nullable();
            $table->string('severity')->default('info');
            $table->text('summary');
            $table->json('evidence')->default('{}');
            $table->json('privacy')->default('{}');
            $table->json('refs')->default('{}');
            $table->json('metadata')->default('{}');
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();
        });
    }
}
