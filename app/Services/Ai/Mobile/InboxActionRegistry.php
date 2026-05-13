<?php

namespace App\Services\Ai\Mobile;

use App\Models\AiInboxItem;
use App\Models\AiMessage;
use App\Models\AiPerformanceRecommendation;
use App\Models\AiThread;
use App\Models\AtlasMobileDevice;
use App\Services\Ai\Kernel\Architecture\AtlasRivalsStrategyReadModel;
use App\Services\Ai\Kernel\Architecture\AtlasRivalsStrategyReviewRecorder;
use App\Services\Ai\Kernel\Decision\DecisionReceiptHash;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Kernel\Evidence\LedgerProjectionWorker;
use App\Services\Ai\Telemetry\AiProviderCostRateService;
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
        private readonly AtlasRivalsStrategyReviewRecorder $rivalsStrategyReviewRecorder,
        private readonly AtlasRivalsStrategyReadModel $rivalsStrategy,
        private readonly AiProviderCostRateService $providerCostRates,
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
                'review_retrieval_regression' => $this->reviewRetrievalRegression($locked, $input),
                'review_retrieval_shadow_scope' => $this->reviewRetrievalShadowScope($locked, $input),
                'record_rivals_review' => $this->recordRivalsReview($locked, $input),
                'configure_provider_cost_rates' => $this->configureProviderCostRates($locked, $input),
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
     * @param  array<string,mixed>  $input
     * @return array{item:AiInboxItem,retrieval_regression_review_action:array<string,mixed>,reviewed:bool,no_external_action:bool}
     */
    private function reviewRetrievalRegression(AiInboxItem $item, array $input): array
    {
        $payload = $item->payload ?? [];
        $retrievalRivals = $this->array(data_get($payload, 'retrieval_rivals'));
        if ($retrievalRivals === []) {
            throw ValidationException::withMessages(['action' => 'Item nao contem retrieval_rivals para revisao.']);
        }

        $decision = $this->string($input['decision'] ?? null) ?? 'reviewed';
        if (! in_array($decision, ['reviewed', 'accepted_regression', 'false_positive', 'needs_more_evidence'], true)) {
            throw ValidationException::withMessages(['decision' => 'Decision invalida para review_retrieval_regression.']);
        }

        $decisionReceipt = $this->retrievalRegressionDecisionReceipt($retrievalRivals, $decision, $input);
        $reviewAction = [
            'schema_version' => 'atlas.inbox_action.memory_retrieval_regression_review.v1',
            'decision' => $decision,
            'reviewed' => true,
            'no_external_action' => true,
            'no_runtime_execution' => true,
            'no_policy_patch' => true,
            'report_hash' => $this->string(data_get($retrievalRivals, 'report_hash')),
            'latest_snapshot_id' => $this->string(data_get($retrievalRivals, 'comparison.latest.id')),
            'previous_snapshot_id' => $this->string(data_get($retrievalRivals, 'comparison.previous.id')),
            'latest_snapshot_hash' => $decisionReceipt['latest_snapshot_hash'],
            'previous_snapshot_hash' => $decisionReceipt['previous_snapshot_hash'],
            'decision_receipt' => $decisionReceipt,
            'decision_receipt_hash' => $decisionReceipt['receipt_hash'],
            'review_signal' => $this->array(data_get($payload, 'proposal_contract.review_signal')),
            'operator_note' => Str::limit($this->string($input['note'] ?? $input['operator_note'] ?? null) ?? '', 500, ''),
            'completed_at' => now()->toJSON(),
        ];

        $payload['retrieval_regression_review_action'] = $reviewAction;
        $item->update([
            'payload' => $payload,
            'status' => $item->status === 'unread' ? 'read' : $item->status,
            'read_at' => $item->read_at ?? now(),
        ]);

        return [
            'item' => $item->refresh(),
            'retrieval_regression_review_action' => $reviewAction,
            'reviewed' => true,
            'no_external_action' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $retrievalRivals
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function retrievalRegressionDecisionReceipt(array $retrievalRivals, string $decision, array $input): array
    {
        $reportHash = $this->string(data_get($retrievalRivals, 'report_hash')) ?? 'unknown_report_hash';
        $latestSnapshotId = $this->string(data_get($retrievalRivals, 'comparison.latest.id')) ?? 'unknown_latest_snapshot';
        $previousSnapshotId = $this->string(data_get($retrievalRivals, 'comparison.previous.id')) ?? 'unknown_previous_snapshot';
        $latestSnapshotHash = hash('sha256', $latestSnapshotId);
        $previousSnapshotHash = hash('sha256', $previousSnapshotId);
        $inputsHash = DecisionReceiptHash::hash([
            'decision' => $decision,
            'report_hash' => $reportHash,
            'latest_snapshot_hash' => $latestSnapshotHash,
            'previous_snapshot_hash' => $previousSnapshotHash,
            'outcome' => data_get($retrievalRivals, 'comparison.outcome'),
            'review_packet_schema_version' => data_get($retrievalRivals, 'review_packet.schema_version'),
        ]);
        $receipt = [
            'schema_version' => 'atlas.memory_retrieval_regression_decision_receipt.v1',
            'receipt_id' => 'retrieval_regression:'.substr($inputsHash, 0, 32),
            'issued_at' => now()->toJSON(),
            'dry_run' => true,
            'signed_by' => 'atlas.inbox.review_retrieval_regression',
            'decision' => $decision,
            'report_hash' => $reportHash,
            'latest_snapshot_hash' => $latestSnapshotHash,
            'previous_snapshot_hash' => $previousSnapshotHash,
            'inputs_hash' => $inputsHash,
            'receipt_hash_fields' => [
                'schema_version',
                'receipt_id',
                'dry_run',
                'signed_by',
                'decision',
                'report_hash',
                'latest_snapshot_hash',
                'previous_snapshot_hash',
                'inputs_hash',
                'no_external_action',
                'no_runtime_execution',
                'no_policy_patch',
                'memory_write_allowed_now',
            ],
            'no_external_action' => true,
            'no_runtime_execution' => true,
            'no_policy_patch' => true,
            'memory_write_allowed_now' => false,
            'future_change_requires' => [
                'human_reviewed_policy_patch',
                'evidence_ledger_event_contract',
                'rollback_plan',
                'fresh_retrieval_benchmark_snapshot',
            ],
            'forbidden_actions' => [
                'enable_python_runtime',
                'persist_raw_query',
                'persist_raw_context',
                'auto_apply_policy_patch',
                'write_memory_without_curator_review',
            ],
            'operator_note_hash' => ($note = $this->string($input['note'] ?? $input['operator_note'] ?? null))
                ? hash('sha256', $note)
                : null,
        ];
        $receipt['receipt_hash'] = DecisionReceiptHash::hash([
            'schema_version' => $receipt['schema_version'],
            'receipt_id' => $receipt['receipt_id'],
            'dry_run' => $receipt['dry_run'],
            'signed_by' => $receipt['signed_by'],
            'decision' => $receipt['decision'],
            'report_hash' => $receipt['report_hash'],
            'latest_snapshot_hash' => $receipt['latest_snapshot_hash'],
            'previous_snapshot_hash' => $receipt['previous_snapshot_hash'],
            'inputs_hash' => $receipt['inputs_hash'],
            'no_external_action' => $receipt['no_external_action'],
            'no_runtime_execution' => $receipt['no_runtime_execution'],
            'no_policy_patch' => $receipt['no_policy_patch'],
            'memory_write_allowed_now' => $receipt['memory_write_allowed_now'],
        ]);

        return $receipt;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{item:AiInboxItem,retrieval_shadow_scope_review_action:array<string,mixed>,reviewed:bool,no_external_action:bool}
     */
    private function reviewRetrievalShadowScope(AiInboxItem $item, array $input): array
    {
        $payload = $item->payload ?? [];
        $shadowPlan = $this->array(data_get($payload, 'retrieval_rivals_shadow_plan'));
        if ($shadowPlan === []) {
            throw ValidationException::withMessages(['action' => 'Item nao contem retrieval_rivals_shadow_plan para revisao.']);
        }

        $decision = $this->string($input['decision'] ?? null) ?? 'needs_more_evidence';
        if (! in_array($decision, ['approved_scope', 'rejected_scope', 'needs_more_evidence', 'reviewed'], true)) {
            throw ValidationException::withMessages(['decision' => 'Decision invalida para review_retrieval_shadow_scope.']);
        }

        $decisionReceipt = $this->retrievalShadowScopeDecisionReceipt($shadowPlan, $decision, $input);
        $reviewAction = [
            'schema_version' => 'atlas.inbox_action.memory_retrieval_shadow_scope_review.v1',
            'decision' => $decision,
            'reviewed' => true,
            'no_external_action' => true,
            'no_runtime_execution' => true,
            'no_policy_patch' => true,
            'no_provider_call' => true,
            'plan_hash' => $this->string(data_get($shadowPlan, 'plan_hash')),
            'review_ap' => $this->string(data_get($shadowPlan, 'review_ap')),
            'decision_receipt' => $decisionReceipt,
            'decision_receipt_hash' => $decisionReceipt['receipt_hash'],
            'review_signal' => $this->array(data_get($payload, 'proposal_contract.review_signal')),
            'operator_note' => Str::limit($this->string($input['note'] ?? $input['operator_note'] ?? null) ?? '', 500, ''),
            'completed_at' => now()->toJSON(),
        ];

        $payload['retrieval_shadow_scope_review_action'] = $reviewAction;
        $item->update([
            'payload' => $payload,
            'status' => $item->status === 'unread' ? 'read' : $item->status,
            'read_at' => $item->read_at ?? now(),
        ]);

        return [
            'item' => $item->refresh(),
            'retrieval_shadow_scope_review_action' => $reviewAction,
            'reviewed' => true,
            'no_external_action' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $shadowPlan
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function retrievalShadowScopeDecisionReceipt(array $shadowPlan, string $decision, array $input): array
    {
        $planHash = $this->string(data_get($shadowPlan, 'plan_hash')) ?? 'unknown_plan_hash';
        $reviewAp = $this->string(data_get($shadowPlan, 'review_ap')) ?? 'docs/ap/AP-693-retrieval-rivals-shadow-comparison-contract.md';
        $inputsHash = DecisionReceiptHash::hash([
            'decision' => $decision,
            'plan_hash' => $planHash,
            'review_ap' => $reviewAp,
            'required_human_decision' => data_get($shadowPlan, 'review_packet.required_human_decision'),
        ]);
        $receipt = [
            'schema_version' => 'atlas.memory_retrieval_shadow_scope_decision_receipt.v1',
            'receipt_id' => 'retrieval_shadow_scope:'.substr($inputsHash, 0, 32),
            'issued_at' => now()->toJSON(),
            'dry_run' => true,
            'signed_by' => 'atlas.inbox.review_retrieval_shadow_scope',
            'decision' => $decision,
            'plan_hash' => $planHash,
            'review_ap' => $reviewAp,
            'inputs_hash' => $inputsHash,
            'receipt_hash_fields' => [
                'schema_version',
                'receipt_id',
                'dry_run',
                'signed_by',
                'decision',
                'plan_hash',
                'review_ap',
                'inputs_hash',
                'shadow_execution_allowed_now',
                'no_runtime_execution',
                'no_policy_patch',
                'no_provider_call',
            ],
            'scope_approved' => $decision === 'approved_scope',
            'shadow_execution_allowed_now' => false,
            'no_runtime_execution' => true,
            'no_policy_patch' => true,
            'no_provider_call' => true,
            'future_shadow_run_requires' => [
                'shadow_case_contract',
                'evidence_ledger_event_contract',
                'privacy_provider_safety_review',
                'rollback_plan',
                'runtime_invocation_contract',
            ],
            'forbidden_actions' => [
                'execute_python_graph_rag',
                'run_unreviewed_lexical_rival',
                'persist_raw_query',
                'persist_raw_context',
                'send_raw_capture_to_provider',
                'auto_apply_policy_patch',
                'promote_rival_strategy',
            ],
            'operator_note_hash' => ($note = $this->string($input['note'] ?? $input['operator_note'] ?? null))
                ? hash('sha256', $note)
                : null,
        ];
        $receipt['receipt_hash'] = DecisionReceiptHash::hash([
            'schema_version' => $receipt['schema_version'],
            'receipt_id' => $receipt['receipt_id'],
            'dry_run' => $receipt['dry_run'],
            'signed_by' => $receipt['signed_by'],
            'decision' => $receipt['decision'],
            'plan_hash' => $receipt['plan_hash'],
            'review_ap' => $receipt['review_ap'],
            'inputs_hash' => $receipt['inputs_hash'],
            'shadow_execution_allowed_now' => $receipt['shadow_execution_allowed_now'],
            'no_runtime_execution' => $receipt['no_runtime_execution'],
            'no_policy_patch' => $receipt['no_policy_patch'],
            'no_provider_call' => $receipt['no_provider_call'],
        ]);

        return $receipt;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{item:AiInboxItem,recorded_review:array<string,mixed>,rivals_strategy:array<string,mixed>,rivals_review_action:array<string,mixed>,command:string,remaining_due_review_count:int}
     */
    private function recordRivalsReview(AiInboxItem $item, array $input): array
    {
        $payload = $item->payload ?? [];
        $dueReviews = collect($this->array(data_get($payload, 'due_reviews')))
            ->filter(fn (mixed $review): bool => is_array($review))
            ->values();

        $reviewId = $this->string($input['review_id'] ?? null);
        $caseId = $this->string($input['case_id'] ?? null);
        $horizonDays = $this->positiveInt($input['horizon_days'] ?? $input['review_horizon'] ?? null);

        if ($reviewId === null && $caseId === null) {
            $selected = $dueReviews->first();
            if (is_array($selected)) {
                $reviewId = $this->string($selected['review_id'] ?? null);
                $caseId = $this->string($selected['case_id'] ?? null);
                $horizonDays = $horizonDays ?? $this->positiveInt($selected['horizon_days'] ?? null);
            }
        }

        try {
            $recorded = $this->rivalsStrategyReviewRecorder->record([
                'review_id' => $reviewId,
                'case_id' => $caseId,
                'review_horizon' => $horizonDays,
                'regret_score' => $input['regret_score'] ?? $input['regret'] ?? null,
                'alignment_score' => $input['alignment_score'] ?? $input['alignment'] ?? null,
                'agency_score' => $input['agency_score'] ?? $input['agency'] ?? null,
                'outcome_summary' => $this->string($input['outcome_summary'] ?? $input['outcome'] ?? null),
                'recorded_by' => 'atlas.inbox.record_rivals_review',
            ]);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['action' => $exception->getMessage()]);
        }

        $recordedReviewId = $this->string($recorded['review_id'] ?? null);
        $remainingDueReviews = $dueReviews
            ->reject(fn (array $review): bool => $recordedReviewId !== null && $this->string($review['review_id'] ?? null) === $recordedReviewId)
            ->values()
            ->all();

        $payload['due_reviews'] = $remainingDueReviews;
        $payload['rivals_review_action'] = [
            'schema_version' => 'atlas.inbox_action.rivals_review.v1',
            'recorded_review_id' => $recordedReviewId,
            'case_id' => $recorded['case_id'] ?? null,
            'horizon_days' => $recorded['horizon_days'] ?? null,
            'scores' => $recorded['scores'] ?? [],
            'remaining_due_review_count' => count($remainingDueReviews),
            'operator_scored' => true,
            'no_external_action' => true,
            'completed_at' => now()->toJSON(),
        ];

        data_set($payload, 'rivals_strategy.due_review_count', count($remainingDueReviews));

        $resolved = count($remainingDueReviews) === 0;
        $item->update([
            'payload' => $payload,
            'status' => $resolved ? 'resolved' : ($item->status === 'unread' ? 'read' : $item->status),
            'read_at' => $item->read_at ?? now(),
            'resolved_at' => $resolved ? now() : $item->resolved_at,
        ]);

        return [
            'item' => $item->refresh(),
            'recorded_review' => $recorded,
            'rivals_strategy' => $this->rivalsStrategy->report(now()->subDays(365), now()->addDays(365)),
            'rivals_review_action' => $payload['rivals_review_action'],
            'command' => 'atlas:ai:rivals-strategy record-review --review-id='.($recordedReviewId ?? '<review-id>').' --regret=<0-100> --alignment=<0-100> --agency=<0-100> --json',
            'remaining_due_review_count' => count($remainingDueReviews),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{item:AiInboxItem,provider_cost_rate_action:array<string,mixed>,upserted_rate:?array<string,mixed>,rate_template:array<string,mixed>,applied:bool,command:string}
     */
    private function configureProviderCostRates(AiInboxItem $item, array $input): array
    {
        $payload = $item->payload ?? [];
        $sourceRefs = collect($this->array(data_get($payload, 'proposal_contract.source_refs')))
            ->filter(fn (mixed $ref): bool => is_array($ref))
            ->values();
        $firstProviderRef = $sourceRefs->first(fn (array $ref): bool => $this->string($ref['provider_cli'] ?? null) !== null);

        $provider = $this->string($input['provider'] ?? $input['provider_cli'] ?? null)
            ?? (is_array($firstProviderRef) ? $this->string($firstProviderRef['provider_cli'] ?? null) : null);
        $model = $this->string($input['model'] ?? null)
            ?? (is_array($firstProviderRef) ? $this->string($firstProviderRef['model'] ?? null) : null);
        $inputRate = $this->nonNegativeInt($input['input_microusd_per_1k'] ?? $input['input_microusd'] ?? null);
        $outputRate = $this->nonNegativeInt($input['output_microusd_per_1k'] ?? $input['output_microusd'] ?? null);
        $currency = strtoupper($this->string($input['currency'] ?? null) ?? 'USD');
        $effectiveFrom = $this->string($input['effective_from'] ?? null);
        $effectiveUntil = $this->string($input['effective_until'] ?? null);

        if ($provider === null || $model === null) {
            throw ValidationException::withMessages(['action' => 'Informe provider/model ou mantenha source_refs com provider_cli/model.']);
        }

        $rateTemplate = [
            'provider' => $provider,
            'model' => $model,
            'input_microusd_per_1k' => $inputRate ?? '<fill_current_input_microusd_per_1k>',
            'output_microusd_per_1k' => $outputRate ?? '<fill_current_output_microusd_per_1k>',
            'currency' => $currency,
            'effective_from' => $effectiveFrom ?? now()->toJSON(),
            'effective_until' => $effectiveUntil,
            'metadata' => [
                'source' => 'inbox_action',
                'inbox_item_id' => $item->id,
                'schema_version' => 'atlas.inbox_action.provider_cost_rates.v1',
            ],
        ];

        $applied = $inputRate !== null && $outputRate !== null;
        $rate = null;
        if ($applied) {
            try {
                $rate = $this->providerCostRates->upsert($rateTemplate);
            } catch (\Throwable $exception) {
                throw ValidationException::withMessages(['action' => $exception->getMessage()]);
            }
        }

        $payload['provider_cost_rate_action'] = [
            'schema_version' => 'atlas.inbox_action.provider_cost_rates.v1',
            'provider' => $provider,
            'model' => $model,
            'applied' => $applied,
            'rate_id' => $rate?->id,
            'currency' => $currency,
            'input_microusd_per_1k' => $inputRate,
            'output_microusd_per_1k' => $outputRate,
            'completed_at' => now()->toJSON(),
            'no_external_action' => true,
        ];

        $item->update([
            'payload' => $payload,
            'status' => $applied ? 'resolved' : ($item->status === 'unread' ? 'read' : $item->status),
            'read_at' => $item->read_at ?? now(),
            'resolved_at' => $applied ? now() : $item->resolved_at,
        ]);

        return [
            'item' => $item->refresh(),
            'provider_cost_rate_action' => $payload['provider_cost_rate_action'],
            'upserted_rate' => $rate ? [
                'id' => $rate->id,
                'provider' => $rate->provider,
                'model' => $rate->model,
                'input_microusd_per_1k' => $rate->input_microusd_per_1k,
                'output_microusd_per_1k' => $rate->output_microusd_per_1k,
                'currency' => $rate->currency,
                'effective_from' => $rate->effective_from?->toJSON(),
                'effective_until' => $rate->effective_until?->toJSON(),
                'metadata' => $rate->metadata ?? [],
            ] : null,
            'rate_template' => $rateTemplate,
            'applied' => $applied,
            'command' => 'atlas:ai:telemetry:cost-rates --provider='.$provider.' --model='.$model.' --input-microusd=<input> --output-microusd=<output> --json',
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

    private function nonNegativeInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || ! preg_match('/^\d+$/', $value)) {
            return null;
        }

        return (int) $value;
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
