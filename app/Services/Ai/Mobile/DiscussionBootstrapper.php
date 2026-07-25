<?php

namespace App\Services\Ai\Mobile;

use App\Models\AiInboxItem;
use App\Models\AiThread;
use App\Models\AiTrace;
use App\Services\Ai\AiGatewayService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\AuditLogService;
use Throwable;

class DiscussionBootstrapper
{
    use MobileArrayHelper;

    public function __construct(
        private readonly AiGatewayService $gateway,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function bootstrap(AiInboxItem $item, AiThread $thread, string $focus): array
    {
        return $this->bootstrapInternal($item, $thread, $focus);
    }

    /**
     * @return array<string,mixed>
     */
    public function retry(AiInboxItem $item, AiThread $thread, string $focus): array
    {
        $payload = $item->payload ?? [];
        $previousTraceId = $this->string(data_get($payload, 'discussion_bootstrap_trace_id'));
        $previousTrace = $previousTraceId ? AiTrace::query()->find($previousTraceId) : null;
        $previousAttempt = max(1, (int) data_get($payload, 'discussion_bootstrap_attempt', 1));

        if ($previousTrace instanceof AiTrace && $this->traceIsStillAuthoritative($previousTrace)) {
            $this->recordBootstrapAudit('atlas_ai.bootstrap_retry_reused_active', $item, $thread, [
                'status' => $previousTrace->status,
                'trace_id' => $previousTrace->id,
                'attempt' => $previousAttempt,
                'retry' => true,
            ]);

            return $this->bootstrapInternal($item, $thread, $focus, false, $previousAttempt);
        }

        $attempt = $previousAttempt + 1;
        $payload['discussion_bootstrap_attempt'] = $attempt;
        $payload['discussion_bootstrap_previous_trace_id'] = $previousTraceId;
        $payload['discussion_bootstrap_status'] = 'retrying';
        $payload['discussion_bootstrap_retry_requested_at'] = now()->toJSON();
        $payload['discussion_bootstrap_at'] = now()->toJSON();
        unset($payload['discussion_bootstrap_trace_id'], $payload['discussion_bootstrap_error']);
        $item->update(['payload' => $payload]);

        $this->syncThreadBootstrapMetadata($thread, [
            'status' => 'retrying',
            'trace_id' => null,
            'error' => null,
            'context_bundle_id' => $item->context_bundle_id,
            'attempt' => $attempt,
        ]);

        return $this->bootstrapInternal($item->refresh(), $thread->refresh(), $focus, true, $attempt);
    }

    /**
     * @return array<string,mixed>
     */
    private function bootstrapInternal(AiInboxItem $item, AiThread $thread, string $focus, bool $force = false, ?int $attempt = null): array
    {
        $payload = $item->payload ?? [];
        $attempt ??= max(1, (int) data_get($payload, 'discussion_bootstrap_attempt', 1));
        $this->recordBootstrapAudit('atlas_ai.bootstrap_started', $item, $thread, [
            'status' => 'started',
            'focus' => $focus,
            'attempt' => $attempt,
            'retry' => $force,
        ]);

        $existingTraceId = $this->string(data_get($payload, 'discussion_bootstrap_trace_id'));
        $existingTrace = $existingTraceId ? AiTrace::query()->find($existingTraceId) : null;
        if ($existingTrace instanceof AiTrace && (! $force || $this->traceIsStillAuthoritative($existingTrace))) {
            $traceError = $this->traceError($existingTrace);
            $payload['discussion_bootstrap_trace_id'] = $existingTrace->id;
            $payload['discussion_bootstrap_status'] = $existingTrace->status;
            $payload['discussion_bootstrap_at'] = now()->toJSON();
            $payload['discussion_bootstrap_attempt'] = $attempt;
            if ($traceError) {
                $payload['discussion_bootstrap_error'] = $traceError;
            } else {
                unset($payload['discussion_bootstrap_error']);
            }
            $item->update(['payload' => $payload]);

            $this->syncThreadBootstrapMetadata($thread, [
                'status' => $existingTrace->status,
                'trace_id' => $existingTrace->id,
                'error' => $traceError,
                'context_bundle_id' => $item->context_bundle_id,
                'attempt' => $attempt,
            ]);
            $this->recordBootstrapAudit('atlas_ai.bootstrap_existing', $item, $thread, [
                'status' => $existingTrace->status,
                'trace_id' => $existingTrace->id,
                'attempt' => $attempt,
                'retry' => $force,
            ]);

            return [
                'status' => $this->existingTraceResponseStatus($existingTrace),
                'trace_id' => $existingTraceId,
                'context_bundle_id' => $item->context_bundle_id,
                'attempt' => $attempt,
                ...($traceError ? ['reason' => $traceError] : []),
            ];
        }

        if (! $this->gatewaySchemaReady()) {
            $payload['discussion_bootstrap_status'] = 'skipped';
            $payload['discussion_bootstrap_error'] = 'ai_gateway_schema_unavailable';
            $payload['discussion_bootstrap_at'] = now()->toJSON();
            $payload['discussion_bootstrap_attempt'] = $attempt;
            $item->update(['payload' => $payload]);
            $this->syncThreadBootstrapMetadata($thread, [
                'status' => 'skipped',
                'trace_id' => null,
                'error' => 'ai_gateway_schema_unavailable',
                'context_bundle_id' => $item->context_bundle_id,
                'attempt' => $attempt,
            ]);
            $this->recordBootstrapAudit('atlas_ai.bootstrap_skipped', $item, $thread, [
                'status' => 'skipped',
                'reason' => 'ai_gateway_schema_unavailable',
                'attempt' => $attempt,
                'retry' => $force,
            ]);

            return [
                'status' => 'skipped',
                'reason' => 'ai_gateway_schema_unavailable',
                'context_bundle_id' => $item->context_bundle_id,
                'attempt' => $attempt,
            ];
        }

        try {
            $trace = $this->gateway->enqueueInteraction($this->bootstrapInput($item), [
                'client_id' => $this->clientId($item, $force, $attempt),
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
                'payload' => $this->bootstrapPayload($item, $thread, $focus, $attempt, $force),
            ]);

            $payload['discussion_bootstrap_trace_id'] = $trace->id;
            $payload['discussion_bootstrap_status'] = 'queued';
            $payload['discussion_bootstrap_at'] = now()->toJSON();
            $payload['discussion_bootstrap_attempt'] = $attempt;
            unset($payload['discussion_bootstrap_error']);
            $item->update(['payload' => $payload]);
            $this->syncThreadBootstrapMetadata($thread, [
                'status' => 'queued',
                'trace_id' => $trace->id,
                'error' => null,
                'context_bundle_id' => $item->context_bundle_id,
                'attempt' => $attempt,
            ]);
            $this->recordBootstrapAudit('atlas_ai.bootstrap_trace_created', $item, $thread, [
                'status' => 'queued',
                'trace_id' => $trace->id,
                'attempt' => $attempt,
                'retry' => $force,
            ]);

            return [
                'status' => 'queued',
                'trace_id' => $trace->id,
                'context_bundle_id' => $item->context_bundle_id,
                'attempt' => $attempt,
            ];
        } catch (Throwable $exception) {
            report($exception);

            $payload['discussion_bootstrap_status'] = 'failed';
            $payload['discussion_bootstrap_error'] = $exception->getMessage();
            $payload['discussion_bootstrap_at'] = now()->toJSON();
            $payload['discussion_bootstrap_attempt'] = $attempt;
            $item->update(['payload' => $payload]);
            $this->syncThreadBootstrapMetadata($thread, [
                'status' => 'failed',
                'trace_id' => null,
                'error' => $exception->getMessage(),
                'context_bundle_id' => $item->context_bundle_id,
                'attempt' => $attempt,
            ]);
            $this->recordBootstrapAudit('atlas_ai.bootstrap_failed', $item, $thread, [
                'status' => 'failed',
                'reason' => $exception->getMessage(),
                'attempt' => $attempt,
                'retry' => $force,
            ]);

            return [
                'status' => 'failed',
                'reason' => $exception->getMessage(),
                'context_bundle_id' => $item->context_bundle_id,
                'attempt' => $attempt,
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
            if (! DatabaseTableAvailability::has($table)) {
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
    private function bootstrapPayload(AiInboxItem $item, AiThread $thread, string $focus, int $attempt, bool $retry): array
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
                'attempt' => $attempt,
                'retry' => $retry,
            ],
        ];
    }

    /**
     * @param  array{status:string,trace_id:?string,error:?string,context_bundle_id:?string,attempt?:int}  $state
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
        $metadata['discussion_bootstrap_attempt'] = $state['attempt'] ?? ($metadata['discussion_bootstrap_attempt'] ?? 1);

        $thread->update(['metadata' => $metadata]);
    }

    private function clientId(AiInboxItem $item, bool $retry, int $attempt): string
    {
        if (! $retry) {
            return 'inbox-discuss-bootstrap-'.$item->id;
        }

        return 'inbox-discuss-bootstrap-'.$item->id.'-retry-'.$attempt;
    }

    private function traceIsStillAuthoritative(AiTrace $trace): bool
    {
        return in_array($trace->status, ['queued', 'processing', 'succeeded', 'awaiting_user_choice'], true);
    }

    private function existingTraceResponseStatus(AiTrace $trace): string
    {
        if (in_array($trace->status, ['queued', 'processing'], true)) {
            return 'already_queued';
        }

        return $trace->status;
    }

    /**
     * @param  array<string,mixed>  $evidence
     */
    private function recordBootstrapAudit(string $eventType, AiInboxItem $item, AiThread $thread, array $evidence): void
    {
        $this->audit->record($eventType, [
            'subject_type' => 'ai_thread',
            'subject_id' => $thread->id,
            'actor_type' => 'system',
            'severity' => in_array($evidence['status'] ?? null, ['failed', 'skipped'], true) ? 'warning' : 'info',
            'summary' => 'Atlas AI operational bootstrap: '.($evidence['status'] ?? 'unknown').'.',
            'evidence' => [
                ...$evidence,
                'inbox_item_id' => $item->id,
                'thread_id' => $thread->id,
                'context_bundle_id' => $item->context_bundle_id,
            ],
            'privacy' => ['sensitivity' => 'private'],
        ]);
    }

    private function traceError(AiTrace $trace): ?string
    {
        return $this->string(data_get($trace->metadata ?? [], 'error'))
            ?? $this->string(data_get($trace->metadata ?? [], 'error_message'))
            ?? $this->string(data_get($trace->job?->metadata ?? [], 'error'))
            ?? $this->string(data_get($trace->job?->payload ?? [], 'error'));
    }

}
