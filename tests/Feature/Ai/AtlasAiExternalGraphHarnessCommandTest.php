<?php

namespace Tests\Feature\Ai;

use App\Models\AiInboxItem;
use App\Services\Ai\Mobile\ProposalInboxEmitter;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasAiExternalGraphHarnessCommandTest extends TestCase
{
    public function test_command_outputs_read_only_contract(): void
    {
        $exit = Artisan::call('atlas:ai:external-graph-harness', [
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('atlas.external_graph_harness.report.v1', data_get($payload, 'external_graph_harness.schema_version'));
        $this->assertSame('implemented_read_only_contract', data_get($payload, 'external_graph_harness.contract.status'));
        $this->assertFalse(data_get($payload, 'external_graph_harness.contract.guardrails.provider_calls_enabled'));
        $this->assertFalse(data_get($payload, 'external_graph_harness.contract.guardrails.writes_memory_registry'));
        $this->assertNull(data_get($payload, 'external_graph_harness.candidate_validation'));
    }

    public function test_command_validates_candidate_file_without_writes(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'atlas-external-graph-');
        file_put_contents($file, json_encode($this->candidate(), JSON_THROW_ON_ERROR));

        $exit = Artisan::call('atlas:ai:external-graph-harness', [
            '--candidate-file' => $file,
            '--json' => true,
        ]);

        @unlink($file);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('accepted_read_only_candidate', data_get($payload, 'external_graph_harness.candidate_validation.status'));
        $this->assertSame(1, data_get($payload, 'external_graph_harness.candidate_validation.node_count'));
        $this->assertFalse(data_get($payload, 'external_graph_harness.candidate_validation.guardrails.writes_constelacao'));
        $this->assertSame('atlas.external_graph_review_packet.v1', data_get($payload, 'external_graph_harness.candidate_validation.review_packet.schema_version'));
        $this->assertSame('ready_for_human_review', data_get($payload, 'external_graph_harness.candidate_validation.review_packet.status'));
        $this->assertSame('approve_or_reject_external_graph_candidate_for_native_extractor_improvement', data_get($payload, 'external_graph_harness.candidate_validation.review_packet.required_human_decision'));
        $this->assertTrue(data_get($payload, 'external_graph_harness.candidate_validation.review_packet.rollback_plan_required'));
        $this->assertContains('keep_graph_rag_runtime_disabled', data_get($payload, 'external_graph_harness.candidate_validation.review_packet.rollback_required'));
        $this->assertFalse(data_get($payload, 'external_graph_harness.candidate_validation.review_packet.auto_promotion_allowed'));
        $this->assertContains('enable_python_graph_rag_runtime', data_get($payload, 'external_graph_harness.candidate_validation.review_packet.forbidden_until_review'));
        $this->assertContains('constelacao', data_get($payload, 'external_graph_harness.candidate_validation.review_packet.blocked_runtime_targets'));
        $this->assertSame('atlas.runtime_invocation_contract.v1', data_get($payload, 'external_graph_harness.candidate_validation.review_packet.future_runtime_invocation_contract.schema_version'));
        $this->assertSame('python_ai_data', data_get($payload, 'external_graph_harness.candidate_validation.review_packet.future_runtime_invocation_contract.selected_runtime_family'));
        $this->assertSame('external_graph_candidate_runtime', data_get($payload, 'external_graph_harness.candidate_validation.review_packet.future_runtime_invocation_contract.runtime_id'));
        $this->assertFalse(data_get($payload, 'external_graph_harness.candidate_validation.review_packet.future_runtime_invocation_contract.auto_enable_allowed_now'));
    }

    public function test_command_builds_sandbox_candidate_without_runtime_or_writes(): void
    {
        $exit = Artisan::call('atlas:ai:external-graph-harness', [
            '--scan-root' => 'docs/engineering-knowledge-base/code-intelligence',
            '--max-files' => 3,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('candidate_built_read_only', data_get($payload, 'sandbox_candidate.status'));
        $this->assertSame('atlas.external_graph_candidate.v1', data_get($payload, 'sandbox_candidate.candidate.schema_version'));
        $this->assertSame('accepted_read_only_candidate', data_get($payload, 'sandbox_candidate.candidate_validation.status'));
        $this->assertFalse(data_get($payload, 'sandbox_candidate.candidate.metadata.graphify_executed'));
        $this->assertFalse(data_get($payload, 'sandbox_candidate.guardrails.provider_calls_enabled'));
        $this->assertFalse(data_get($payload, 'external_graph_harness.promotion_allowed'));
        $this->assertSame('review_external_graph_candidate_against_native_code_intelligence', data_get($payload, 'external_graph_harness.next_action'));
    }

    public function test_command_can_emit_review_inbox_for_accepted_candidate_without_promotion(): void
    {
        $capturedPayload = null;
        $inboxItem = new AiInboxItem;
        $inboxItem->id = '00000000-0000-0000-0000-000000000684';
        $inboxItem->title = 'Revisar candidato de grafo externo do Atlas';
        $inboxItem->payload = [
            'proposal_contract' => [
                'review_signal' => [
                    'recommended_action' => 'review_external_graph_candidate',
                ],
            ],
        ];

        $this->mock(ProposalInboxEmitter::class, function ($mock) use ($inboxItem, &$capturedPayload): void {
            $mock->shouldReceive('emit')
                ->once()
                ->andReturnUsing(function (array $payload) use ($inboxItem, &$capturedPayload): AiInboxItem {
                    $capturedPayload = $payload;

                    return $inboxItem;
                });
        });

        $exit = Artisan::call('atlas:ai:external-graph-harness', [
            '--scan-root' => 'docs/engineering-knowledge-base/code-intelligence',
            '--max-files' => 3,
            '--emit-review-inbox' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('emitted', data_get($payload, 'emitted_inbox_item.status'));
        $this->assertSame($inboxItem->id, data_get($payload, 'emitted_inbox_item.id'));
        $this->assertSame('Revisar candidato de grafo externo do Atlas', data_get($capturedPayload, 'title'));
        $this->assertSame('code_intelligence', data_get($capturedPayload, 'category'));
        $this->assertSame('external_graph_harness', data_get($capturedPayload, 'source_type'));
        $this->assertSame('atlas.external_graph_review_inbox.v1', data_get($capturedPayload, 'metadata.schema_version'));
        $this->assertSame('review_external_graph_candidate', data_get($capturedPayload, 'metadata.review_signal.recommended_action'));
        $this->assertSame('review_external_graph_candidate', data_get($capturedPayload, 'available_actions.0.id'));
        $this->assertSame('atlas.external_graph_review_inbox.v1', data_get($capturedPayload, 'payload.external_graph_review.schema_version'));
        $this->assertSame('atlas.external_graph_review_packet.v1', data_get($capturedPayload, 'payload.external_graph_review.review_packet.schema_version'));
        $this->assertFalse(data_get($capturedPayload, 'payload.external_graph_review.promotion_allowed'));
        $this->assertFalse(data_get($capturedPayload, 'payload.external_graph_review.provider_call_allowed'));
        $this->assertFalse(data_get($capturedPayload, 'payload.external_graph_review.runtime_execution_allowed'));
        $this->assertFalse(data_get($capturedPayload, 'payload.external_graph_review.memory_write_allowed'));
        $this->assertFalse(data_get($capturedPayload, 'payload.external_graph_review.context_injection_allowed'));
        $this->assertFalse(data_get($capturedPayload, 'payload.external_graph_review.constelacao_promotion_allowed'));
        $this->assertFalse(data_get($capturedPayload, 'payload.external_graph_review.raw_graph_payload_persisted'));
        $this->assertContains('enable_python_graph_rag_runtime', data_get($capturedPayload, 'payload.external_graph_review.review_packet.forbidden_until_review'));
        $this->assertStringNotContainsString('api_secret', json_encode($capturedPayload, JSON_THROW_ON_ERROR));
        $this->assertArrayNotHasKey('candidate', data_get($capturedPayload, 'payload.external_graph_review'));
    }

    /**
     * @return array<string,mixed>
     */
    private function candidate(): array
    {
        return [
            'schema_version' => 'atlas.external_graph_candidate.v1',
            'source_tool' => 'graphify',
            'source_tool_version' => '0.7.11',
            'source_archive_hash' => str_repeat('b', 64),
            'scan_root' => 'docs/engineering-knowledge-base',
            'generated_at' => '2026-05-09T12:00:00Z',
            'privacy_class' => 'engineering_internal',
            'review_state' => 'candidate',
            'nodes' => [
                [
                    'id' => 'doc:external_graph_harness',
                    'label' => 'External Graph Harness',
                    'kind' => 'doc',
                    'source_refs' => [
                        ['path' => 'docs/engineering-knowledge-base/code-intelligence/external-graph-harness.md'],
                    ],
                ],
            ],
            'edges' => [],
        ];
    }
}
