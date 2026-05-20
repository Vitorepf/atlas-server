<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\ConversationOps;

use App\Services\Ai\ConversationOps\AtlasConversationOperationsService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class AtlasConversationOperationsServiceTest extends TestCase
{
    public function test_health_report_extracts_decisions_blockers_and_emits_hash(): void
    {
        $payload = app(AtlasConversationOperationsService::class)->healthReport([
            'now' => CarbonImmutable::parse('2026-05-20 10:00:00'),
            'goal' => 'implementar ACIE e ACOL',
            'turns' => [
                ['role' => 'user', 'content' => 'decidimos que ACIE e infraestrutura interna'],
                ['role' => 'assistant', 'content' => 'blocker: precisa evidence refs antes de integrar'],
            ],
            'context_refs' => ['doc:docs/engineering-knowledge-base/atlas-conversation-operations-layer.md'],
        ]);

        $this->assertSame(AtlasConversationOperationsService::HEALTH_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(AtlasConversationOperationsService::STATUS_WATCH, $payload['status']);
        $this->assertSame(1, $payload['summary']['decision_count']);
        $this->assertSame(1, $payload['summary']['blocker_count']);
        $this->assertArrayHasKey('conversation_health_hash', $payload);
        $this->assertFalse($payload['claim_policy']['provider_calls_made']);
    }

    public function test_handoff_packet_has_required_return_contract_and_forbidden_actions(): void
    {
        $payload = app(AtlasConversationOperationsService::class)->handoffPacket([
            'now' => CarbonImmutable::parse('2026-05-20 10:00:00'),
            'role' => 'explorer',
            'task' => 'mapear contexto sem editar',
            'allowed_scope' => ['app/Services/Ai/ContextIntelligence'],
        ]);

        $this->assertSame(AtlasConversationOperationsService::HANDOFF_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertContains('evidence_refs', $payload['must_return']);
        $this->assertContains('run_provider', $payload['forbidden_actions']);
        $this->assertArrayHasKey('handoff_packet_hash', $payload);
    }

    public function test_subagent_return_without_evidence_is_blocked(): void
    {
        $payload = app(AtlasConversationOperationsService::class)->auditSubagentReturn([
            'findings' => [['summary' => 'ok']],
            'evidence_refs' => [],
        ]);

        $this->assertSame(AtlasConversationOperationsService::STATUS_BLOCKED, $payload['status']);
        $this->assertFalse($payload['integration_allowed']);
    }

    public function test_meta_agent_receipts_do_not_mutate_memory_or_remove_context(): void
    {
        $service = app(AtlasConversationOperationsService::class);
        $janitor = $service->contextJanitorReceipt([
            'turns' => [
                ['content' => 'talvez isso seja apenas rascunho'],
                ['content' => 'decidimos manter evidence refs'],
            ],
        ]);
        $curator = $service->memoryCuratorReceipt([
            'decisions' => [['kind' => 'decision', 'digest' => 'ACOL internal']],
            'blockers' => [['kind' => 'blocker', 'digest' => 'Needs evidence']],
        ]);
        $critic = $service->compressionCriticReport([
            'must_keep_coverage' => 0.5,
            'missing_must_keep_ids' => ['mk-1'],
        ]);

        $this->assertSame(AtlasConversationOperationsService::JANITOR_SCHEMA_VERSION, $janitor['schema_version']);
        $this->assertFalse($janitor['removal_allowed']);
        $this->assertNotEmpty($janitor['noise_candidates']);
        $this->assertSame(AtlasConversationOperationsService::MEMORY_CURATOR_SCHEMA_VERSION, $curator['schema_version']);
        $this->assertFalse($curator['promotion_allowed']);
        $this->assertSame(2, $curator['candidate_count']);
        $this->assertSame(AtlasConversationOperationsService::COMPRESSION_CRITIC_SCHEMA_VERSION, $critic['schema_version']);
        $this->assertSame(AtlasConversationOperationsService::STATUS_BLOCKED, $critic['status']);
        $this->assertFalse($critic['integration_allowed']);
    }
}
