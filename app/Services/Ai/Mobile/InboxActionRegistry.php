<?php

namespace App\Services\Ai\Mobile;

use App\Models\AiInboxItem;
use App\Models\AiMessage;
use App\Models\AiPerformanceRecommendation;
use App\Models\AiThread;
use App\Models\AtlasMobileDevice;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Kernel\Evidence\LedgerProjectionWorker;
use App\Services\Ai\Telemetry\Engine\RecommendationLifecycleService;
use App\Services\AuditLogService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InboxActionRegistry
{
    private const CODE_ACTIONS = [
        'commit',
        'open_pr',
        'merge',
        'apply_patch',
        'push_branch',
    ];

    public function __construct(
        private readonly AtlasInboxService $inbox,
        private readonly AuditLogService $audit,
        private readonly ProposalInboxEmitter $proposals,
        private readonly RecommendationLifecycleService $recommendations,
        private readonly DiscussionBootstrapper $discussionBootstrapper,
        private readonly AtlasEvidenceLedger $ledger,
        private readonly LedgerProjectionWorker $ledgerProjectionWorker,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function handle(AiInboxItem $item, string $actionId, array $input = [], ?string $idempotencyKey = null, ?AtlasMobileDevice $actor = null): array
    {
        $idempotencyKey = $this->string($idempotencyKey);

        return DB::transaction(function () use ($item, $actionId, $input, $idempotencyKey, $actor): array {
            /** @var AiInboxItem $locked */
            $locked = AiInboxItem::query()
                ->whereKey($item->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $existing = $locked->response;
            if (
                $idempotencyKey !== null
                && is_array($existing)
                && ($existing['idempotency_key'] ?? null) === $idempotencyKey
                && ($existing['action'] ?? null) === $actionId
            ) {
                $existingResult = is_array($existing['result'] ?? null) ? $existing['result'] : [];
                if ($actionId !== 'discuss' || $this->discussResultIsUsable($existingResult)) {
                    return [
                        'ok' => true,
                        'idempotent' => true,
                        'result' => $existingResult,
                        'item' => $locked,
                    ];
                }
            }

            $this->assertActionAvailable($locked, $actionId);

            $this->audit->record('inbox.action.requested', [
                'subject_type' => 'ai_inbox_item',
                'subject_id' => $locked->id,
                'actor_type' => $actor ? 'mobile_device' : 'operator_cli',
                'actor_id' => $actor?->id,
                'severity' => 'info',
                'summary' => "Inbox action requested: {$actionId}.",
                'evidence' => ['action' => $actionId],
                'privacy' => ['sensitivity' => 'private'],
            ]);

            $result = match ($actionId) {
                'mark_read' => ['item' => $this->inbox->markRead($locked)],
                'dismiss', 'discard' => $this->isRecommendationItem($locked)
                    ? $this->transitionRecommendation($locked, $actionId, $input)
                    : ['item' => $this->inbox->dismiss($locked, $this->string($input['reason'] ?? null))],
                'snooze' => $this->isRecommendationItem($locked)
                    ? $this->transitionRecommendation($locked, $actionId, $input)
                    : ['item' => $this->inbox->snooze($locked, Carbon::parse((string) ($input['snoozed_until'] ?? '')), $this->string($input['reason'] ?? null))],
                'discuss' => $this->discuss($locked),
                'approve_once', 'approve_session', 'approve_workspace_1h', 'deny' => $this->resolveApproval($locked, $actionId, $input),
                'view_trace', 'review_patch' => $this->readOnlyResult($locked, $actionId),
                'create_proposal' => $this->createProposal($locked),
                'run_ledger_projection' => $this->runLedgerProjection($locked, $input),
                'ignore_30d' => $this->ignoreThirtyDays($locked),
                'acknowledge_recommendation', 'apply_recommendation', 'reject_recommendation' => $this->transitionRecommendation($locked, $actionId, $input),
                default => throw ValidationException::withMessages(['action' => 'Action handler nao implementado.']),
            };

            $fresh = ($result['item'] ?? $locked)->refresh();
            $response = $fresh->response ?? [];
            if (! in_array($actionId, ['dismiss', 'discard', 'snooze', 'approve_once', 'approve_session', 'approve_workspace_1h', 'deny', 'ignore_30d'], true)) {
                $fresh->update([
                    'response' => [
                        ...$response,
                        'action' => $actionId,
                        'idempotency_key' => $idempotencyKey,
                        'result' => $this->serializableResult($result),
                        'responded_at' => now()->toJSON(),
                    ],
                    'read_at' => $fresh->read_at ?? now(),
                    'status' => $fresh->status === 'unread' ? 'read' : $fresh->status,
                ]);
            } else {
                $fresh->update([
                    'response' => [
                        ...($fresh->response ?? []),
                        'idempotency_key' => $idempotencyKey,
                        'result' => $this->serializableResult($result),
                    ],
                ]);
            }

            $this->audit->record('inbox.action.completed', [
                'subject_type' => 'ai_inbox_item',
                'subject_id' => $fresh->id,
                'actor_type' => $actor ? 'mobile_device' : 'operator_cli',
                'actor_id' => $actor?->id,
                'severity' => 'info',
                'summary' => "Inbox action completed: {$actionId}.",
                'evidence' => ['action' => $actionId],
                'privacy' => ['sensitivity' => 'private'],
            ]);
            $serializedResult = $this->serializableResult($result);
            $this->recordInboxActionLedgerEvent($fresh, $actionId, $serializedResult, $actor, $idempotencyKey);

            return [
                'ok' => true,
                'idempotent' => false,
                'result' => $serializedResult,
                'item' => $fresh->refresh(),
            ];
        });
    }

    /**
     * @return array{ok:bool,idempotent:bool,result:array<string,mixed>,item:AiInboxItem}
     */
    public function retryDiscussionBootstrap(AiInboxItem $item, ?AtlasMobileDevice $actor = null): array
    {
        return DB::transaction(function () use ($item, $actor): array {
            /** @var AiInboxItem $locked */
            $locked = AiInboxItem::query()
                ->whereKey($item->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertItemStateAllowsAction($locked, 'discuss');

            $payload = $locked->payload ?? [];
            $threadId = $this->string(data_get($payload, 'discussion_thread_id'));
            $thread = $threadId ? AiThread::query()->find($threadId) : null;
            if (! $thread) {
                throw ValidationException::withMessages(['action' => 'Conversa operacional ainda nao existe para retry.']);
            }

            $this->audit->record('inbox.bootstrap_retry.requested', [
                'subject_type' => 'ai_inbox_item',
                'subject_id' => $locked->id,
                'actor_type' => $actor ? 'mobile_device' : 'operator_cli',
                'actor_id' => $actor?->id,
                'severity' => 'info',
                'summary' => 'Inbox discussion bootstrap retry requested.',
                'evidence' => [
                    'thread_id' => $thread->id,
                    'previous_status' => data_get($payload, 'discussion_bootstrap_status'),
                    'previous_trace_id' => data_get($payload, 'discussion_bootstrap_trace_id'),
                ],
                'privacy' => ['sensitivity' => 'private'],
            ]);

            $focus = $this->atlasFocusForInboxItem($locked);
            $bootstrap = $this->discussionBootstrapper->retry($locked, $thread, $focus);
            $fresh = $locked->refresh();
            $response = $fresh->response ?? [];
            $result = [
                'item' => $fresh,
                'thread_id' => $thread->id,
                'deep_link' => "atlas://thread/{$thread->id}",
                'focus' => $focus,
                'bootstrap' => $bootstrap,
            ];

            $fresh->update([
                'response' => [
                    ...$response,
                    'action' => 'retry_discussion_bootstrap',
                    'result' => $this->serializableResult($result),
                    'responded_at' => now()->toJSON(),
                ],
                'read_at' => $fresh->read_at ?? now(),
                'status' => $fresh->status === 'unread' ? 'read' : $fresh->status,
            ]);

            $this->audit->record('inbox.bootstrap_retry.completed', [
                'subject_type' => 'ai_inbox_item',
                'subject_id' => $fresh->id,
                'actor_type' => $actor ? 'mobile_device' : 'operator_cli',
                'actor_id' => $actor?->id,
                'severity' => ($bootstrap['status'] ?? null) === 'failed' ? 'warning' : 'info',
                'summary' => 'Inbox discussion bootstrap retry completed.',
                'evidence' => [
                    'thread_id' => $thread->id,
                    'bootstrap' => $bootstrap,
                ],
                'privacy' => ['sensitivity' => 'private'],
            ]);

            return [
                'ok' => true,
                'idempotent' => false,
                'result' => $this->serializableResult($result),
                'item' => $fresh->refresh(),
            ];
        });
    }

    private function assertActionAvailable(AiInboxItem $item, string $actionId): void
    {
        $this->assertItemStateAllowsAction($item, $actionId);

        $actions = collect($item->available_actions ?? [])->pluck('id')->all();
        $common = ['mark_read', 'dismiss', 'snooze'];
        if (! in_array($actionId, $actions, true) && ! in_array($actionId, $common, true)) {
            throw ValidationException::withMessages(['action' => 'Action nao disponivel para este item.']);
        }

        $this->assertCodeActionGate($item, $actionId);

        if ($item->expires_at && $item->expires_at->isPast() && ! in_array($actionId, ['dismiss', 'mark_read'], true)) {
            throw ValidationException::withMessages(['action' => 'Item expirado.']);
        }
    }

    private function assertItemStateAllowsAction(AiInboxItem $item, string $actionId): void
    {
        if (in_array($item->status, ['resolved', 'dismissed'], true) && $actionId !== 'mark_read') {
            throw ValidationException::withMessages(['action' => 'Item ja esta fechado.']);
        }

        if ($item->status === 'expired' && ! in_array($actionId, ['dismiss', 'mark_read'], true)) {
            throw ValidationException::withMessages(['action' => 'Item expirado.']);
        }

        if ($item->status === 'snoozed'
            && $item->snoozed_until
            && $item->snoozed_until->isFuture()
            && ! in_array($actionId, ['dismiss', 'mark_read'], true)) {
            throw ValidationException::withMessages(['action' => 'Item adiado ate '.$item->snoozed_until->toJSON().'.']);
        }
    }

    private function assertCodeActionGate(AiInboxItem $item, string $actionId): void
    {
        if (! in_array($actionId, self::CODE_ACTIONS, true)) {
            return;
        }

        $payload = $item->payload ?? [];
        $gateStatus = $this->string(data_get($payload, 'quality_gate_status'))
            ?? $this->string(data_get($payload, 'gate.status'))
            ?? $this->string(data_get($payload, 'policy.quality_gate_status'));

        if ($gateStatus !== 'passed') {
            throw ValidationException::withMessages(['action' => 'Action de codigo exige quality_gate_status=passed.']);
        }

        throw ValidationException::withMessages(['action' => 'Action de codigo ainda nao possui handler mobile seguro.']);
    }

    /**
     * @return array{item:AiInboxItem,thread_id:string,deep_link:string,focus:string,bootstrap:array<string,mixed>}
     */
    private function discuss(AiInboxItem $item): array
    {
        if (! Schema::hasTable('ai_threads') || ! Schema::hasTable('ai_messages')) {
            throw ValidationException::withMessages(['action' => 'Tabelas de threads ainda nao existem.']);
        }

        $payload = $item->payload ?? [];
        $existingThreadId = $payload['discussion_thread_id'] ?? null;
        $existingThread = is_string($existingThreadId) ? AiThread::query()->find($existingThreadId) : null;
        if ($existingThread) {
            $this->inbox->markRead($item);
            $focus = $this->atlasFocusForInboxItem($item);
            $bootstrap = $this->discussionBootstrapper->bootstrap($item->refresh(), $existingThread, $focus);

            return [
                'item' => $item->refresh(),
                'thread_id' => $existingThreadId,
                'deep_link' => "atlas://thread/{$existingThreadId}",
                'focus' => $focus,
                'bootstrap' => $bootstrap,
            ];
        }

        return DB::transaction(function () use ($item, $payload): array {
            $bundle = $item->contextBundle;
            $focus = $this->atlasFocusForInboxItem($item);
            $thread = AiThread::query()->create([
                'title' => $item->title,
                'summary' => $bundle?->summary ?? $item->summary,
                'status' => 'active',
                'surface' => 'mobile',
                'source_type' => 'inbox_item',
                'source_id' => $item->id,
                'message_count' => 0,
                'metadata' => [
                    'atlas_focus' => $focus,
                    'initial_focus' => $focus,
                    'source_type' => 'ai_inbox_item',
                    'source_id' => $item->id,
                    'inbox_item_id' => $item->id,
                    'context_bundle_id' => $item->context_bundle_id,
                    'context_label' => $this->contextLabelForInboxItem($item),
                    'capability_profile' => 'mobile_operational_read',
                    'permission_policy' => 'read_only_until_approval',
                    'execution_policy' => 'no_code_execution',
                    'discussion_entrypoint' => 'atlas_ai_sheet',
                ],
            ]);

            AiMessage::query()->create([
                'thread_id' => $thread->id,
                'position' => 1,
                'role' => 'system',
                'status' => 'final',
                'content' => $bundle?->body_for_thread ?? $this->fallbackThreadContext($item),
                'token_estimate' => $bundle?->token_estimate,
                'occurred_at' => now(),
                'metadata' => ['source' => 'inbox_context_bundle'],
            ]);

            AiMessage::query()->create([
                'thread_id' => $thread->id,
                'position' => 2,
                'role' => 'user',
                'status' => 'final',
                'content' => 'Vamos discutir este item com o Atlas.',
                'token_estimate' => 10,
                'occurred_at' => now(),
                'metadata' => ['source' => 'mobile_thread_seed', 'atlas_focus' => $focus],
            ]);

            $thread->update([
                'message_count' => 2,
                'last_message_at' => now(),
            ]);

            $payload['discussion_thread_id'] = $thread->id;
            $item->update([
                'payload' => $payload,
                'status' => $item->status === 'unread' ? 'read' : $item->status,
                'read_at' => $item->read_at ?? now(),
            ]);

            $bootstrap = $this->discussionBootstrapper->bootstrap($item->refresh(), $thread, $focus);

            return [
                'item' => $item->refresh(),
                'thread_id' => $thread->id,
                'deep_link' => "atlas://thread/{$thread->id}",
                'focus' => $focus,
                'bootstrap' => $bootstrap,
            ];
        });
    }

    private function atlasFocusForInboxItem(AiInboxItem $item): string
    {
        return match ($item->type) {
            'capture' => 'general',
            default => 'operational',
        };
    }

    private function contextLabelForInboxItem(AiInboxItem $item): string
    {
        $category = $this->string($item->category);

        return $category
            ? 'Inbox - '.$this->humanLabel($category)
            : 'Inbox operacional';
    }

    private function humanLabel(string $value): string
    {
        return Str::of($value)
            ->replace(['_', '-'], ' ')
            ->squish()
            ->title()
            ->toString();
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{item:AiInboxItem,approval_action:string,approval_grant:?array<string,mixed>}
     */
    private function resolveApproval(AiInboxItem $item, string $actionId, array $input): array
    {
        if ($item->type !== 'approval') {
            throw ValidationException::withMessages(['action' => 'Approval action so pode ser usada em item approval.']);
        }

        $grant = $this->approvalGrant($item, $actionId, $input);

        $item->update([
            'status' => 'resolved',
            'read_at' => $item->read_at ?? now(),
            'resolved_at' => now(),
            'response' => [
                'action' => $actionId,
                'reason' => $this->string($input['reason'] ?? null),
                'approval_grant' => $grant,
                'responded_at' => now()->toJSON(),
            ],
        ]);

        return [
            'item' => $item->refresh(),
            'approval_action' => $actionId,
            'approval_grant' => $grant,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>|null
     */
    private function approvalGrant(AiInboxItem $item, string $actionId, array $input): ?array
    {
        if ($actionId === 'deny') {
            return null;
        }

        $payload = $item->payload ?? [];
        $workspace = $this->string($input['workspace'] ?? null)
            ?? $this->string(data_get($payload, 'workspace'))
            ?? $this->string(data_get($payload, 'runtime.workspace'))
            ?? $this->string(data_get($payload, 'tool_context.workspace'));

        if ($actionId === 'approve_workspace_1h' && $workspace === null) {
            throw ValidationException::withMessages(['workspace' => 'Workspace obrigatorio para approve_workspace_1h.']);
        }

        $scope = match ($actionId) {
            'approve_once' => 'once',
            'approve_session' => 'session',
            'approve_workspace_1h' => 'workspace',
            default => 'unknown',
        };

        return [
            'scope' => $scope,
            'workspace' => $workspace,
            'expires_at' => $actionId === 'approve_workspace_1h' ? now()->addHour()->toJSON() : null,
            'tool' => $this->string(data_get($payload, 'tool')),
            'risk' => $this->string(data_get($payload, 'risk')),
        ];
    }

    /**
     * @return array{item:AiInboxItem,payload:array<string,mixed>}
     */
    private function readOnlyResult(AiInboxItem $item, string $actionId): array
    {
        $payload = $item->payload ?? [];
        $proposalContract = $this->array(data_get($payload, 'proposal_contract'));

        return [
            'item' => $this->inbox->markRead($item),
            'payload' => [
                'action' => $actionId,
                'source_type' => $item->source_type,
                'source_id' => $item->source_id,
                'proposal_contract' => $proposalContract,
                'review_signal' => $this->array(data_get($proposalContract, 'review_signal')),
                'recommended_action' => $this->string(data_get($proposalContract, 'review_signal.recommended_action')),
                'source_refs' => $this->array(data_get($proposalContract, 'source_refs')),
                'trace_refs' => $this->array(data_get($proposalContract, 'trace_refs')),
                'job_refs' => $this->array(data_get($proposalContract, 'job_refs')),
                'file_refs' => $this->array(data_get($proposalContract, 'file_refs')),
                'diff_refs' => $this->array(data_get($proposalContract, 'diff_refs')),
                'payload' => $payload,
            ],
        ];
    }

    /**
     * @return array{item:AiInboxItem,proposal_item_id:?string,proposal_deep_link:?string}
     */
    private function createProposal(AiInboxItem $item): array
    {
        if ($item->type !== 'self_diagnostic') {
            throw ValidationException::withMessages(['action' => 'create_proposal so pode ser usado em self_diagnostic.']);
        }

        $payload = $item->payload ?? [];
        $proposal = $this->proposals->emit([
            'title' => 'Proposta gerada de auto-diagnostico: '.$item->title,
            'finding' => $this->string($payload['observation'] ?? null) ?? $item->summary,
            'problem' => $this->string($payload['hypothesis'] ?? null) ?? $item->body ?? 'Auto-diagnostico detectou problema sem hipotese estruturada.',
            'solution' => $this->string($payload['proposed_fix'] ?? null) ?? 'Discutir ajuste com Atlas antes de aplicar qualquer mudanca.',
            'worth_it' => 'Vale avaliar porque o proprio loop de qualidade detectou regressao ou risco operacional.',
            'best_solution_rationale' => 'Converter em proposal mantem revisao humana, contexto completo e bloqueia commit/merge automatico.',
            'alternatives' => ['Discutir primeiro sem criar patch.', 'Ignorar por 30 dias se o sinal for ruido.'],
            'category' => 'self_diagnostic',
            'source_type' => 'ai_inbox_item',
            'source_id' => $item->id,
            'source_refs' => [['type' => 'self_diagnostic', 'id' => $item->id]],
            'dedupe_key' => 'proposal:self-diagnostic:'.($item->dedupe_key ?: $item->id),
            'confidence' => $item->confidence_score !== null ? (float) $item->confidence_score : 0.7,
        ]);

        $payload['proposal_inbox_item_id'] = $proposal?->id;
        $payload['proposal_created_at'] = now()->toJSON();
        $item->update([
            'payload' => $payload,
            'status' => $item->status === 'unread' ? 'read' : $item->status,
            'read_at' => $item->read_at ?? now(),
        ]);

        return [
            'item' => $item->refresh(),
            'proposal_item_id' => $proposal?->id,
            'proposal_deep_link' => $proposal?->deep_link,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{item:AiInboxItem,ledger_projection:array<string,mixed>,ledger_projection_action:array<string,mixed>,applied:bool,dry_run:bool,command:string,source_health_status:?string}
     */
    private function runLedgerProjection(AiInboxItem $item, array $input): array
    {
        $payload = $item->payload ?? [];
        $sourceHealth = $this->array(data_get($payload, 'ledger_projection_health'));
        if ($sourceHealth === []) {
            $sourceHealth = $this->array(data_get($payload, 'projection_health'));
        }

        $hours = $this->positiveInt($input['hours'] ?? null)
            ?? $this->positiveInt($input['projection_hours'] ?? null)
            ?? $this->positiveInt(data_get($payload, 'ledger_projection.hours'))
            ?? $this->positiveInt(data_get($payload, 'ledger_projection_health.scheduler.hours'))
            ?? $this->positiveInt(data_get($payload, 'scheduler.hours'))
            ?? (int) config('atlas_ai.ledger_projection.hours', 24);
        $limit = $this->positiveInt($input['limit'] ?? null)
            ?? $this->positiveInt($input['projection_limit'] ?? null)
            ?? $this->positiveInt(data_get($payload, 'ledger_projection.limit'))
            ?? $this->positiveInt(data_get($payload, 'ledger_projection_health.scheduler.limit'))
            ?? $this->positiveInt(data_get($payload, 'scheduler.limit'))
            ?? (int) config('atlas_ai.ledger_projection.limit', 500);
        $dryRun = $this->booleanValue($input['dry_run'] ?? data_get($payload, 'ledger_projection.dry_run', false));

        $hours = max(1, min(8760, $hours));
        $limit = max(1, min(5000, $limit));

        $report = $this->ledgerProjectionWorker->project($limit, $hours, $dryRun);
        $applied = ! $dryRun
            && (bool) ($report['available'] ?? false)
            && ($report['status'] ?? null) === 'ok'
            && (int) ($report['projected_count'] ?? 0) > 0;

        $payload['ledger_projection_action'] = [
            'schema_version' => 'atlas.inbox_action.ledger_projection.v1',
            'dry_run' => $dryRun,
            'hours' => $hours,
            'limit' => $limit,
            'applied' => $applied,
            'available' => (bool) ($report['available'] ?? false),
            'status' => $report['status'] ?? 'unknown',
            'event_count' => (int) ($report['event_count'] ?? 0),
            'projected_count' => (int) ($report['projected_count'] ?? 0),
            'skipped_count' => (int) ($report['skipped_count'] ?? 0),
            'completed_at' => now()->toJSON(),
        ];

        $item->update([
            'payload' => $payload,
            'status' => $applied ? 'resolved' : ($item->status === 'unread' ? 'read' : $item->status),
            'read_at' => $item->read_at ?? now(),
            'resolved_at' => $applied ? now() : $item->resolved_at,
        ]);

        return [
            'item' => $item->refresh(),
            'ledger_projection' => $report,
            'ledger_projection_action' => $payload['ledger_projection_action'],
            'applied' => $applied,
            'dry_run' => $dryRun,
            'command' => 'atlas:ai:ledger-project --hours='.$hours.' --limit='.$limit.($dryRun ? ' --dry-run' : '').' --json',
            'source_health_status' => $this->string($sourceHealth['status'] ?? null),
        ];
    }

    /**
     * @return array{item:AiInboxItem,ignored_until:string}
     */
    private function ignoreThirtyDays(AiInboxItem $item): array
    {
        if ($item->type !== 'self_diagnostic') {
            throw ValidationException::withMessages(['action' => 'ignore_30d so pode ser usado em self_diagnostic.']);
        }

        $until = now()->addDays(30);
        $payload = $item->payload ?? [];
        $payload['ignored_until'] = $until->toJSON();

        $item->update([
            'status' => 'resolved',
            'read_at' => $item->read_at ?? now(),
            'resolved_at' => now(),
            'payload' => $payload,
            'response' => [
                'action' => 'ignore_30d',
                'ignored_until' => $until->toJSON(),
                'responded_at' => now()->toJSON(),
            ],
        ]);

        return [
            'item' => $item->refresh(),
            'ignored_until' => $until->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{item:AiInboxItem,recommendation_id:string,state:string}
     */
    private function transitionRecommendation(AiInboxItem $item, string $actionId, array $input): array
    {
        $recommendationId = $this->string(data_get($item->payload ?? [], 'recommendation.id'))
            ?? ($item->source_type === 'ai_performance_recommendation' ? $this->string($item->source_id) : null);

        if ($recommendationId === null) {
            throw ValidationException::withMessages(['action' => 'Item nao referencia uma recomendacao de performance.']);
        }

        /** @var AiPerformanceRecommendation $recommendation */
        $recommendation = AiPerformanceRecommendation::query()->findOrFail($recommendationId);
        $state = match ($actionId) {
            'acknowledge_recommendation' => 'acknowledged',
            'apply_recommendation' => 'applied',
            'reject_recommendation', 'dismiss', 'discard' => 'rejected',
            'snooze' => 'snoozed',
        };

        $metadata = ['source' => 'mobile_inbox', 'inbox_item_id' => $item->id];
        $snoozedUntil = null;
        if ($state === 'snoozed') {
            $snoozedUntil = $this->string($input['snoozed_until'] ?? null);
            if ($snoozedUntil === null) {
                throw ValidationException::withMessages(['snoozed_until' => 'A data de adiamento precisa ser informada.']);
            }
            $metadata['snoozed_until'] = $snoozedUntil;
        }

        $recommendation = $this->recommendations->transition(
            $recommendation,
            $state,
            $this->string($input['reason'] ?? null) ?? $actionId,
            $metadata,
        );

        $payload = $item->payload ?? [];
        data_set($payload, 'recommendation.state', $recommendation->state);
        data_set($payload, 'recommendation.measurement_due_at', $recommendation->measurement_due_at?->toJSON());
        data_set($payload, 'recommendation.snoozed_until', $recommendation->snoozed_until?->toJSON());
        data_set($payload, 'recommendation.closed_at', $recommendation->closed_at?->toJSON());
        data_set($payload, 'recommendation.closed_reason', $recommendation->closed_reason);

        $updates = [
            'payload' => $payload,
            'read_at' => $item->read_at ?? now(),
            'response' => [
                'action' => $actionId,
                'reason' => $this->string($input['reason'] ?? null),
                'recommendation_id' => $recommendation->id,
                'state' => $recommendation->state,
                'snoozed_until' => $recommendation->snoozed_until?->toJSON(),
                'responded_at' => now()->toJSON(),
            ],
        ];

        if (in_array($state, ['applied', 'rejected'], true)) {
            $updates['resolved_at'] = now();
            if (in_array($actionId, ['dismiss', 'discard'], true)) {
                $updates['status'] = 'dismissed';
                $updates['dismissed_at'] = now();
            } else {
                $updates['status'] = 'resolved';
            }
        } elseif ($state === 'snoozed') {
            $updates['status'] = 'snoozed';
            $updates['snoozed_until'] = Carbon::parse((string) $snoozedUntil);
        } elseif ($item->status === 'unread') {
            $updates['status'] = 'read';
        }

        $item->update($updates);

        return [
            'item' => $item->refresh(),
            'recommendation_id' => $recommendation->id,
            'state' => $recommendation->state,
        ];
    }

    private function isRecommendationItem(AiInboxItem $item): bool
    {
        return $item->source_type === 'ai_performance_recommendation'
            || $this->string(data_get($item->payload ?? [], 'recommendation.id')) !== null;
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function discussResultIsUsable(array $result): bool
    {
        $threadId = $this->string($result['thread_id'] ?? null)
            ?? $this->string(data_get($result, 'thread.id'));

        return $threadId !== null && AiThread::query()->whereKey($threadId)->exists();
    }

    private function fallbackThreadContext(AiInboxItem $item): string
    {
        return trim(implode("\n\n", array_filter([
            '# '.$item->title,
            $item->summary,
            $item->body,
            'Tipo: '.$item->type,
            'Severidade: '.$item->severity,
        ])));
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function recordInboxActionLedgerEvent(
        AiInboxItem $item,
        string $actionId,
        array $result,
        ?AtlasMobileDevice $actor,
        ?string $idempotencyKey,
    ): void {
        $payload = [
            'schema_version' => 'atlas.inbox_action.v1',
            'action' => $actionId,
            'idempotency_key' => $idempotencyKey,
            'inbox_item' => [
                'id' => $item->id,
                'type' => $item->type,
                'category' => $item->category,
                'severity' => $item->severity,
                'status' => $item->status,
                'source_type' => $item->source_type,
                'source_id' => $item->source_id,
                'dedupe_key' => $item->dedupe_key,
            ],
            'actor' => [
                'type' => $actor ? 'mobile_device' : 'operator_cli',
                'id' => $actor?->id,
            ],
            'result' => $result,
            'proposal_contract' => $this->array(data_get($item->payload ?? [], 'proposal_contract')),
            'review_signal' => $this->array(data_get($item->payload ?? [], 'proposal_contract.review_signal')),
            'recommended_action' => $this->string(data_get($item->payload ?? [], 'proposal_contract.review_signal.recommended_action')),
        ];

        $this->ledger->record(LedgerEventType::InboxActionRecorded, $payload, [
            'tenant_id' => 'default',
            'operator_id' => $actor ? 'mobile_device:'.$actor->id : 'operator_cli',
            'envelope_id' => 'inbox_item:'.$item->id,
            'correlation_id' => $item->id,
            'emitter_stage' => 'atlas.inbox',
            'emitter_version' => 'atlas.inbox_action.v1',
        ]);
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function serializableResult(array $result): array
    {
        return collect($result)
            ->reject(fn (mixed $value): bool => $value instanceof AiInboxItem)
            ->all();
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    private function booleanValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return is_numeric($value) && (int) $value === 1;
    }

    /**
     * @return array<int|string,mixed>
     */
    private function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
