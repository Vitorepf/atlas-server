<?php

namespace Tests\Unit\Ai;

use App\Models\AiJob;
use App\Models\AiJobAttempt;
use App\Services\Ai\AiProviderResult;
use App\Services\Ai\Kernel\Evidence\ProviderUsagePayload;
use Tests\TestCase;

class HermesExecutiveRuntimePacketEvidenceTest extends TestCase
{
    public function test_provider_usage_payload_references_hermes_mission_and_result_packet(): void
    {
        $job = new AiJob([
            'provider' => 'hermes_cli',
            'model' => 'hermes_cli_default',
            'kind' => 'interaction',
            'input_text' => 'execute a governed mission',
            'prompt' => 'compiled prompt',
            'payload' => [
                'decision_receipt' => [
                    'receipt_v2' => [
                        'domain' => 'general',
                        'flow' => 'general.answer',
                        'metadata' => [
                            'task_profile' => [
                                'task_type' => 'diagnostic',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $job->attempts = 1;

        $attempt = new AiJobAttempt([
            'provider' => 'hermes_cli',
            'model' => 'hermes_cli_default',
            'attempt_number' => 1,
            'started_at' => now()->subSecond(),
        ]);

        $result = new AiProviderResult(
            ok: true,
            output: 'ok',
            command: ['hermes', 'chat', '--quiet', '--query', '[prompt:redacted]'],
            exitCode: 0,
            durationMs: 321,
            stdout: 'ok',
            stderr: '',
            metadata: [
                'executive_mission' => [
                    'mission_id' => 'hermes_mission_test',
                    'mission_hash' => 'mission_hash_test',
                ],
                'hermes_result_packet' => [
                    'result_id' => 'hermes_result_test',
                    'result_hash' => 'result_hash_test',
                    'memory_gate' => [
                        'candidate_count' => 2,
                    ],
                    'procedure_gate' => [
                        'candidate_count' => 1,
                    ],
                    'schedule_gate' => [
                        'candidate_count' => 1,
                    ],
                    'gateway' => [
                        'delivery_authority' => 'atlas',
                    ],
                ],
                'hermes_memory_adapter' => [
                    'status' => 'persisted_for_review',
                    'persisted_count' => 1,
                    'duplicate_count' => 0,
                    'receipt_hash' => 'memory_adapter_hash_test',
                ],
                'hermes_schedule_adapter' => [
                    'status' => 'persisted_for_review',
                    'persisted_count' => 1,
                    'duplicate_count' => 0,
                    'receipt_hash' => 'schedule_adapter_hash_test',
                ],
            ],
        );

        $payload = app(ProviderUsagePayload::class)->returned($job, $attempt, $result, 'response_hash_test');

        $this->assertSame('atlas.provider_usage.v1', $payload['schema_version']);
        $this->assertSame('returned', $payload['phase']);
        $this->assertSame('hermes_cli', $payload['provider_cli']);
        $this->assertSame('atlas.provider_usage.executive_runtime_packet_ref.v1', data_get($payload, 'executive_runtime_packet.schema_version'));
        $this->assertSame('hermes_mission_test', data_get($payload, 'executive_runtime_packet.mission_id'));
        $this->assertSame('mission_hash_test', data_get($payload, 'executive_runtime_packet.mission_hash'));
        $this->assertSame('hermes_result_test', data_get($payload, 'executive_runtime_packet.result_id'));
        $this->assertSame('result_hash_test', data_get($payload, 'executive_runtime_packet.result_hash'));
        $this->assertSame(2, data_get($payload, 'executive_runtime_packet.memory_delta_candidate_count'));
        $this->assertSame('persisted_for_review', data_get($payload, 'executive_runtime_packet.memory_adapter_status'));
        $this->assertSame(1, data_get($payload, 'executive_runtime_packet.memory_adapter_persisted_count'));
        $this->assertSame(0, data_get($payload, 'executive_runtime_packet.memory_adapter_duplicate_count'));
        $this->assertSame('memory_adapter_hash_test', data_get($payload, 'executive_runtime_packet.memory_adapter_receipt_hash'));
        $this->assertSame(1, data_get($payload, 'executive_runtime_packet.procedure_candidate_count'));
        $this->assertSame(1, data_get($payload, 'executive_runtime_packet.schedule_candidate_count'));
        $this->assertSame('persisted_for_review', data_get($payload, 'executive_runtime_packet.schedule_adapter_status'));
        $this->assertSame(1, data_get($payload, 'executive_runtime_packet.schedule_adapter_persisted_count'));
        $this->assertSame(0, data_get($payload, 'executive_runtime_packet.schedule_adapter_duplicate_count'));
        $this->assertSame('schedule_adapter_hash_test', data_get($payload, 'executive_runtime_packet.schedule_adapter_receipt_hash'));
        $this->assertSame('atlas', data_get($payload, 'executive_runtime_packet.gateway_delivery_authority'));
        $this->assertTrue((bool) data_get($payload, 'executive_runtime_packet.provider_is_executor_only'));
    }

    public function test_provider_usage_payload_surfaces_governed_adapter_and_gate_receipts(): void
    {
        $job = new AiJob([
            'provider' => 'hermes_cli',
            'model' => 'hermes_cli_default',
            'kind' => 'interaction',
            'input_text' => 'execute a governed mission',
            'prompt' => 'compiled prompt',
        ]);
        $job->attempts = 1;

        $attempt = new AiJobAttempt([
            'provider' => 'hermes_cli',
            'model' => 'hermes_cli_default',
            'attempt_number' => 1,
            'started_at' => now()->subSecond(),
        ]);

        $result = new AiProviderResult(
            ok: true,
            output: 'ok',
            command: ['hermes', 'chat', '--quiet', '--query', '[prompt:redacted]'],
            exitCode: 0,
            durationMs: 210,
            stdout: 'ok',
            stderr: '',
            metadata: [
                'executive_mission' => [
                    'mission_id' => 'hermes_mission_test',
                    'mission_hash' => 'mission_hash_test',
                ],
                'hermes_result_packet' => [
                    'result_id' => 'hermes_result_test',
                    'result_hash' => 'result_hash_test',
                ],
                'hermes_gateway_adapter' => [
                    'status' => 'ingress_normalized_delivery_blocked',
                    'receipt_hash' => 'gateway_adapter_hash_test',
                ],
                'hermes_procedure_adapter' => [
                    'status' => 'persisted_for_review',
                    'persisted_count' => 2,
                    'duplicate_count' => 1,
                    'receipt_hash' => 'procedure_adapter_hash_test',
                ],
                'hermes_schedule_activation' => [
                    'status' => 'activated',
                    'activated_task_id' => 4242,
                    'receipt_hash' => 'schedule_activation_hash_test',
                ],
                'hermes_memory_gate_review' => [
                    'status' => 'promoted',
                    'promoted_count' => 1,
                    'rejected_count' => 0,
                    'deduped_count' => 2,
                    'receipt_hash' => 'memory_gate_review_hash_test',
                ],
                'hermes_runtime_router' => [
                    'reason' => 'ops_long_running_tool_heavy_task_matched_hermes_policy',
                    'runtime_role' => 'executive_runtime',
                ],
            ],
        );

        $payload = app(ProviderUsagePayload::class)->returned($job, $attempt, $result, 'response_hash_test');

        $this->assertSame('ingress_normalized_delivery_blocked', data_get($payload, 'executive_runtime_packet.gateway_adapter_status'));
        $this->assertSame('gateway_adapter_hash_test', data_get($payload, 'executive_runtime_packet.gateway_adapter_receipt_hash'));
        $this->assertSame('persisted_for_review', data_get($payload, 'executive_runtime_packet.procedure_adapter_status'));
        $this->assertSame(2, data_get($payload, 'executive_runtime_packet.procedure_adapter_persisted_count'));
        $this->assertSame(1, data_get($payload, 'executive_runtime_packet.procedure_adapter_duplicate_count'));
        $this->assertSame('procedure_adapter_hash_test', data_get($payload, 'executive_runtime_packet.procedure_adapter_receipt_hash'));
        $this->assertSame('activated', data_get($payload, 'executive_runtime_packet.schedule_activation_status'));
        $this->assertSame(4242, data_get($payload, 'executive_runtime_packet.schedule_activated_task_id'));
        $this->assertSame('schedule_activation_hash_test', data_get($payload, 'executive_runtime_packet.schedule_activation_receipt_hash'));
        $this->assertSame('promoted', data_get($payload, 'executive_runtime_packet.memory_gate_review_status'));
        $this->assertSame(1, data_get($payload, 'executive_runtime_packet.memory_gate_promoted_count'));
        $this->assertSame(0, data_get($payload, 'executive_runtime_packet.memory_gate_rejected_count'));
        $this->assertSame(2, data_get($payload, 'executive_runtime_packet.memory_gate_deduped_count'));
        $this->assertSame('memory_gate_review_hash_test', data_get($payload, 'executive_runtime_packet.memory_gate_review_receipt_hash'));
        $this->assertSame('ops_long_running_tool_heavy_task_matched_hermes_policy', data_get($payload, 'executive_runtime_packet.runtime_router_reason'));
        $this->assertSame('executive_runtime', data_get($payload, 'executive_runtime_packet.runtime_router_role'));
    }
}
