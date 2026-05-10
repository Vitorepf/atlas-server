<?php

namespace Tests\Feature\Ai;

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
