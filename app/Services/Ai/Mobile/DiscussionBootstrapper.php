<?php

namespace App\Services\Ai\Mobile;

use App\Models\AiInboxItem;
use App\Models\AiThread;
use App\Models\AiTrace;
use App\Services\Ai\AiGatewayService;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DiscussionBootstrapper
{
    public function __construct(
        private readonly AiGatewayService $gateway,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function bootstrap(AiInboxItem $item, AiThread $thread, string $focus): array
    {
        $payload = $item->payload ?? [];
        $existingTraceId = $this->string(data_get($payload, 'discussion_bootstrap_trace_id'));
        $existingTrace = $existingTraceId ? AiTrace::query()->find($existingTraceId) : null;
        if ($existingTrace instanceof AiTrace) {
            $this->syncThreadBootstrapMetadata($thread, [
                'status' => $existingTrace->status,
                'trace_id' => $existingTrace->id,
                'error' => $this->traceError($existingTrace),
                'context_bundle_id' => $item->context_bundle_id,
            ]);

            return [
                'status' => 'already_queued',
                'trace_id' => $existingTraceId,
                'context_bundle_id' => $item->context_bundle_id,
            ];
        }

        if (! $this->gatewaySchemaReady()) {
            $payload['discussion_bootstrap_status'] = 'skipped';
            $payload['discussion_bootstrap_error'] = 'ai_gateway_schema_unavailable';
            $payload['discussion_bootstrap_at'] = now()->toJSON();
            $item->update(['payload' => $payload]);
            $this->syncThreadBootstrapMetadata($thread, [
                'status' => 'skipped',
                'trace_id' => null,
                'error' => 'ai_gateway_schema_unavailable',
                'context_bundle_id' => $item->context_bundle_id,
            ]);

            return [
                'status' => 'skipped',
                'reason' => 'ai_gateway_schema_unavailable',
                'context_bundle_id' => $item->context_bundle_id,
            ];
        }

        try {
            $trace = $this->gateway->enqueueInteraction($this->bootstrapInput($item), [
                'client_id' => 'inbox-discuss-bootstrap-'.$item->id,
                'thread_id' => $thread->id,
                'new_thread' => false,
                'agent_slug' => 'orquestrador',
                'provider' => null,
                'kind' => 'analysis',
                'source_type' => 'inbox_item',
                'source_id' => $item->id,
                'include_semantic_context' => true,
                'context_note_limit' => 8,
                'priority' => 70,
                'payload' => $this->bootstrapPayload($item, $thread, $focus),
            ]);

            $payload['discussion_bootstrap_trace_id'] = $trace->id;
            $payload['discussion_bootstrap_status'] = 'queued';
            $payload['discussion_bootstrap_at'] = now()->toJSON();
            unset($payload['discussion_bootstrap_error']);
            $item->update(['payload' => $payload]);
            $this->syncThreadBootstrapMetadata($thread, [
                'status' => 'queued',
                'trace_id' => $trace->id,
                'error' => null,
                'context_bundle_id' => $item->context_bundle_id,
            ]);

            return [
                'status' => 'queued',
                'trace_id' => $trace->id,
                'context_bundle_id' => $item->context_bundle_id,
            ];
        } catch (Throwable $exception) {
            report($exception);

            $payload['discussion_bootstrap_status'] = 'failed';
            $payload['discussion_bootstrap_error'] = $exception->getMessage();
            $payload['discussion_bootstrap_at'] = now()->toJSON();
            $item->update(['payload' => $payload]);
            $this->syncThreadBootstrapMetadata($thread, [
                'status' => 'failed',
                'trace_id' => null,
                'error' => $exception->getMessage(),
                'context_bundle_id' => $item->context_bundle_id,
            ]);

            return [
                'status' => 'failed',
                'reason' => $exception->getMessage(),
                'context_bundle_id' => $item->context_bundle_id,
            ];
        }
    }

    private function gatewaySchemaReady(): bool
    {
        foreach ([
            'ai_threads',
            'ai_messages',
            'ai_traces',
            'ai_jobs',
            'ai_sessions',
            'ai_session_states',
            'ai_compactions',
            'ai_context_snapshots',
            'ai_provider_handoffs',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    private function bootstrapInput(AiInboxItem $item): string
    {
        return implode("\n\n", array_filter([
            'Analise este item operacional do Inbox como Atlas AI.',
            'Nao espere o operador reenviar contexto. Use o context bundle, evidencias, metricas, traces e historico disponiveis.',
            'Entregue uma revisao estruturada: o que aconteceu, por que importa, evidencias, hipoteses, risco, proximas acoes e quando promover para programacao.',
            'Item: '.$item->title,
            $item->summary ? 'Resumo: '.$item->summary : null,
        ]));
    }

    /**
     * @return array<string,mixed>
     */
    private function bootstrapPayload(AiInboxItem $item, AiThread $thread, string $focus): array
    {
        return [
            'app_surface' => 'atlas_ai_sheet',
            'thread_source' => 'mobile_gateway_inbox',
            'inbox_item_id' => $item->id,
            'context_bundle_id' => $item->context_bundle_id,
            'atlas_focus' => $focus,
            'atlas_mode' => 'operational',
            'atlas_workflow_mode' => 'operational_diagnostic',
            'capability_profile' => 'atlas_full_access',
            'permission_policy' => 'full_access',
            'execution_policy' => 'provider_execution_allowed',
            'permission_mode' => 'danger',
            'tool_permissions' => [
                'mode' => 'danger',
                'workspace' => $thread->workspace ?: (string) config('atlas.ai.workdir'),
                'confirmed' => true,
                'allow_unsandboxed_provider' => true,
                'source' => 'inbox_discuss_bootstrap',
            ],
            'mobile_runtime_policy' => [
                'allows_code_execution' => true,
                'reason' => 'Discutir com Atlas abre o Atlas principal com contexto operacional e runtime completo.',
            ],
            'atlas_mode_contract' => [
                'schema_version' => 1,
                'mode' => 'operational',
                'objective' => 'diagnosticar item operacional e transformar alerta em decisao acionavel',
                'must_use_context_bundle' => true,
                'expected_output' => [
                    'resumo_executivo',
                    'evidencias',
                    'hipoteses',
                    'risco',
                    'acoes_recomendadas',
                    'promocao_para_programacao_quando_necessario',
                ],
            ],
            'quality_policy' => [
                'require_evidence' => true,
                'require_uncertainty' => true,
                'require_next_actions' => true,
                'avoid_raw_json_as_primary_output' => true,
            ],
            'discussion_bootstrap' => [
                'auto_started' => true,
                'source' => 'inbox_discuss_action',
                'thread_id' => $thread->id,
            ],
        ];
    }

    /**
     * @param  array{status:string,trace_id:?string,error:?string,context_bundle_id:?string}  $state
     */
    private function syncThreadBootstrapMetadata(AiThread $thread, array $state): void
    {
        $metadata = is_array($thread->metadata) ? $thread->metadata : [];
        $metadata['discussion_bootstrap_status'] = $state['status'];
        $metadata['discussion_bootstrap_trace_id'] = $state['trace_id'];
        $metadata['discussion_bootstrap_error'] = $state['error'];
        $metadata['discussion_bootstrap_context_bundle_id'] = $state['context_bundle_id'];
        $metadata['discussion_bootstrap_at'] = now()->toJSON();
        $metadata['discussion_bootstrap_source'] = 'inbox_discuss_action';

        $thread->update(['metadata' => $metadata]);
    }

    private function traceError(AiTrace $trace): ?string
    {
        return $this->string(data_get($trace->metadata ?? [], 'error'))
            ?? $this->string(data_get($trace->metadata ?? [], 'error_message'))
            ?? $this->string(data_get($trace->job?->metadata ?? [], 'error'))
            ?? $this->string(data_get($trace->job?->payload ?? [], 'error'));
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
