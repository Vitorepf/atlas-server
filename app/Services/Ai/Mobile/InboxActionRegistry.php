<?php

namespace App\Services\Ai\Mobile;

use App\Models\AiInboxItem;
use App\Models\AiMessage;
use App\Models\AiThread;
use App\Models\AtlasMobileDevice;
use App\Services\AuditLogService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
    ) {
    }

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
                return [
                    'ok' => true,
                    'idempotent' => true,
                    'result' => $existing['result'] ?? [],
                    'item' => $locked,
                ];
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
                'dismiss', 'discard' => ['item' => $this->inbox->dismiss($locked, $this->string($input['reason'] ?? null))],
                'snooze' => ['item' => $this->inbox->snooze($locked, Carbon::parse((string) ($input['snoozed_until'] ?? '')), $this->string($input['reason'] ?? null))],
                'discuss' => $this->discuss($locked),
                'approve_once', 'approve_session', 'approve_workspace_1h', 'deny' => $this->resolveApproval($locked, $actionId, $input),
                'view_trace', 'review_patch' => $this->readOnlyResult($locked, $actionId),
                'create_proposal' => $this->createProposal($locked),
                'ignore_30d' => $this->ignoreThirtyDays($locked),
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
     * @return array{item:AiInboxItem,thread_id:string,deep_link:string}
     */
    private function discuss(AiInboxItem $item): array
    {
        if (! Schema::hasTable('ai_threads') || ! Schema::hasTable('ai_messages')) {
            throw ValidationException::withMessages(['action' => 'Tabelas de threads ainda nao existem.']);
        }

        $payload = $item->payload ?? [];
        $existingThreadId = $payload['discussion_thread_id'] ?? null;
        if (is_string($existingThreadId) && AiThread::query()->whereKey($existingThreadId)->exists()) {
            $this->inbox->markRead($item);

            return [
                'item' => $item->refresh(),
                'thread_id' => $existingThreadId,
                'deep_link' => "atlas://thread/{$existingThreadId}",
            ];
        }

        return DB::transaction(function () use ($item, $payload): array {
            $bundle = $item->contextBundle;
            $thread = AiThread::query()->create([
                'title' => $item->title,
                'summary' => $bundle?->summary ?? $item->summary,
                'status' => 'active',
                'surface' => 'mobile',
                'source_type' => 'inbox_item',
                'source_id' => $item->id,
                'message_count' => 0,
                'metadata' => [
                    'inbox_item_id' => $item->id,
                    'context_bundle_id' => $item->context_bundle_id,
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
                'content' => 'Vamos discutir este item do Inbox.',
                'token_estimate' => 10,
                'occurred_at' => now(),
                'metadata' => ['source' => 'mobile_thread_seed'],
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

            return [
                'item' => $item->refresh(),
                'thread_id' => $thread->id,
                'deep_link' => "atlas://thread/{$thread->id}",
            ];
        });
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
        return [
            'item' => $this->inbox->markRead($item),
            'payload' => [
                'action' => $actionId,
                'source_type' => $item->source_type,
                'source_id' => $item->source_id,
                'payload' => $item->payload ?? [],
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
}
