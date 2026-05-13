<?php

namespace App\Services\Ai\Mobile;

use App\Models\AiInboxItem;
use App\Models\AtlasMobileDevice;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AtlasInboxService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly MobilePushService $push,
    ) {
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function create(array $data): AiInboxItem
    {
        $type = $this->validType((string) ($data['type'] ?? 'alert'));
        $severity = $this->validSeverity((string) ($data['severity'] ?? 'info'));
        $status = $this->validStatus((string) ($data['status'] ?? 'unread'));
        $dedupeKey = $this->nullableString($data['dedupe_key'] ?? null);
        $userId = $this->userId($data);

        if ($dedupeKey) {
            $this->expirePastDedupeItems($userId, $dedupeKey);

            $existing = $this->activeDedupeQuery($userId, $dedupeKey)
                ->latest('created_at')
                ->first();

            if ($existing) {
                return $this->dedupeExisting($existing, $data, $type, $severity, $status, $dedupeKey);
            }
        }

        $availableActions = $this->actions($type, $data);
        $pushPolicy = $this->array($data['push_policy'] ?? []);
        $payload = $this->array($data['payload'] ?? []);

        try {
            $item = AiInboxItem::query()->create([
                'user_id' => $userId,
                'type' => $type,
                'category' => $this->nullableString($data['category'] ?? null),
                'severity' => $severity,
                'status' => $status,
                'title' => $this->title($data),
                'summary' => $this->nullableString($data['summary'] ?? null),
                'body' => $this->nullableString($data['body'] ?? null),
                'source_type' => $this->nullableString($data['source_type'] ?? null),
                'source_id' => $this->nullableString($data['source_id'] ?? null),
                'initiator' => $this->initiator((string) ($data['initiator'] ?? 'system')),
                'context_bundle_id' => $this->nullableString($data['context_bundle_id'] ?? null),
                'dedupe_key' => $dedupeKey,
                'available_actions' => $availableActions,
                'response' => null,
                'payload' => $payload,
                'deep_link' => null,
                'push_policy' => $pushPolicy,
                'priority_score' => max(0, min(100, (int) ($data['priority_score'] ?? 50))),
                'confidence_score' => isset($data['confidence_score']) ? (float) $data['confidence_score'] : null,
                'expires_at' => $data['expires_at'] ?? $this->defaultExpiresAt($type),
            ]);
        } catch (QueryException $exception) {
            if (! $dedupeKey || ! $this->isDedupeUniqueViolation($exception)) {
                throw $exception;
            }

            $existing = $this->activeDedupeQuery($userId, $dedupeKey)
                ->latest('created_at')
                ->first();

            if (! $existing) {
                throw $exception;
            }

            return $this->dedupeExisting($existing, $data, $type, $severity, $status, $dedupeKey);
        }

        $deepLink = $this->deepLink($data, $item->id);
        $item->update([
            'deep_link' => $deepLink,
            'payload' => $this->payloadWithProactiveDeliveryContract(
                payload: $payload,
                itemId: $item->id,
                type: $type,
                severity: $severity,
                status: $status,
                dedupeKey: $dedupeKey,
                contextBundleId: $this->nullableString($data['context_bundle_id'] ?? null),
                availableActions: $availableActions,
                pushPolicy: $pushPolicy,
                deepLink: $deepLink,
            ),
        ]);

        $this->audit->record('inbox.created', [
            'subject_type' => 'ai_inbox_item',
            'subject_id' => $item->id,
            'actor_type' => $item->initiator,
            'severity' => $severity,
            'summary' => 'Inbox item created.',
            'evidence' => [
                'type' => $type,
                'title' => $item->title,
                'source_type' => $item->source_type,
                'source_id' => $item->source_id,
            ],
            'privacy' => ['sensitivity' => 'private'],
        ]);

        $this->push->dispatchForInboxItem($item->refresh());

        return $item->refresh();
    }

    public function markRead(AiInboxItem $item): AiInboxItem
    {
        if ($item->read_at === null) {
            $item->update([
                'status' => $item->status === 'unread' ? 'read' : $item->status,
                'read_at' => now(),
            ]);
        }

        return $item->refresh();
    }

    public function dismiss(AiInboxItem $item, ?string $reason = null): AiInboxItem
    {
        $item->update([
            'status' => 'dismissed',
            'dismissed_at' => now(),
            'resolved_at' => $item->resolved_at ?? now(),
            'response' => [
                'action' => 'dismiss',
                'reason' => $reason,
                'responded_at' => now()->toJSON(),
            ],
        ]);

        $this->audit->record('inbox.action.completed', [
            'subject_type' => 'ai_inbox_item',
            'subject_id' => $item->id,
            'summary' => 'Inbox item dismissed.',
            'evidence' => ['reason' => $reason],
            'privacy' => ['sensitivity' => 'private'],
        ]);

        return $item->refresh();
    }

    public function snooze(AiInboxItem $item, Carbon $until, ?string $reason = null): AiInboxItem
    {
        if ($until->isPast()) {
            throw ValidationException::withMessages(['snoozed_until' => 'A data de adiamento precisa estar no futuro.']);
        }

        $item->update([
            'status' => 'snoozed',
            'snoozed_until' => $until,
            'response' => [
                'action' => 'snooze',
                'reason' => $reason,
                'snoozed_until' => $until->toJSON(),
                'responded_at' => now()->toJSON(),
            ],
        ]);

        return $item->refresh();
    }

    /**
     * @return Collection<int,AiInboxItem>
     */
    public function list(string $userId = 'vitor', ?string $status = 'unread', ?string $type = null, int $limit = 50, ?string $severity = null, ?string $cursor = null): Collection
    {
        return $this->listPage($userId, $status, $type, $limit, $severity, $cursor)['items'];
    }

    /**
     * @return array{items:Collection<int,AiInboxItem>,next_cursor:?string}
     */
    public function listPage(string $userId = 'vitor', ?string $status = 'unread', ?string $type = null, int $limit = 50, ?string $severity = null, ?string $cursor = null): array
    {
        $limit = max(1, min(100, $limit));
        $items = $this
            ->query($userId, $status, $type, $severity, $cursor)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        $pageItems = $items->take($limit)->values();

        return [
            'items' => $pageItems,
            'next_cursor' => $items->count() > $limit ? $this->encodeCursor($pageItems->last()) : null,
        ];
    }

    private function query(string $userId, ?string $status, ?string $type, ?string $severity, ?string $cursor): Builder
    {
        $query = AiInboxItem::query()->where('user_id', $userId);

        if ($status === 'active') {
            $query
                ->whereNotIn('status', ['resolved', 'dismissed', 'expired'])
                ->where(function ($nested): void {
                    $nested
                        ->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now());
                })
                ->where(function ($nested): void {
                    $nested
                        ->where('status', '!=', 'snoozed')
                        ->orWhereNull('snoozed_until')
                        ->orWhere('snoozed_until', '<=', now());
                });
        } elseif ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($type) {
            $query->where('type', $type);
        }

        if ($severity) {
            $query->where('severity', $severity);
        }

        if ($decoded = $this->decodeCursor($cursor)) {
            $query->where(function (Builder $nested) use ($decoded): void {
                $nested
                    ->where('created_at', '<', $decoded['created_at'])
                    ->orWhere(function (Builder $sameTimestamp) use ($decoded): void {
                        $sameTimestamp
                            ->where('created_at', '=', $decoded['created_at'])
                            ->where('id', '<', $decoded['id']);
                    });
            });
        }

        return $query;
    }

    private function activeDedupeQuery(string $userId, string $dedupeKey): Builder
    {
        return AiInboxItem::query()
            ->where('user_id', $userId)
            ->where('dedupe_key', $dedupeKey)
            ->whereNotIn('status', ['resolved', 'dismissed', 'expired'])
            ->where(function (Builder $nested): void {
                $nested
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    private function expirePastDedupeItems(string $userId, string $dedupeKey): void
    {
        AiInboxItem::query()
            ->where('user_id', $userId)
            ->where('dedupe_key', $dedupeKey)
            ->whereNotIn('status', ['resolved', 'dismissed', 'expired'])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function dedupeExisting(AiInboxItem $existing, array $data, string $type, string $severity, string $status, string $dedupeKey): AiInboxItem
    {
        $payload = $existing->payload ?? [];
        $payload['occurrence_count'] = ((int) ($payload['occurrence_count'] ?? 1)) + 1;
        $payload['last_occurrence_at'] = now()->toJSON();
        unset($payload['proactive_delivery_contract']);

        $availableActions = $this->actions($type, $data);
        $pushPolicy = array_key_exists('push_policy', $data)
            ? $this->array($data['push_policy'] ?? [])
            : $this->array($existing->push_policy ?? []);
        $contextBundleId = array_key_exists('context_bundle_id', $data)
            ? $this->nullableString($data['context_bundle_id'] ?? null)
            : $this->nullableString($existing->context_bundle_id ?? null);
        $deepLink = $this->deepLink($data, $existing->id);

        $updates = [
            'title' => $this->title($data),
            'summary' => $this->nullableString($data['summary'] ?? null),
            'body' => $this->nullableString($data['body'] ?? null),
            'category' => $this->nullableString($data['category'] ?? null),
            'severity' => $severity,
            'status' => $status,
            'source_type' => $this->nullableString($data['source_type'] ?? null),
            'source_id' => $this->nullableString($data['source_id'] ?? null),
            'payload' => $this->payloadWithProactiveDeliveryContract(
                payload: array_replace_recursive($payload, $this->array($data['payload'] ?? [])),
                itemId: $existing->id,
                type: $type,
                severity: $severity,
                status: $status,
                dedupeKey: $dedupeKey,
                contextBundleId: $contextBundleId,
                availableActions: $availableActions,
                pushPolicy: $pushPolicy,
                deepLink: $deepLink,
            ),
            'available_actions' => $availableActions,
            'deep_link' => $deepLink,
        ];

        if (array_key_exists('context_bundle_id', $data)) {
            $updates['context_bundle_id'] = $contextBundleId;
        }

        if (array_key_exists('push_policy', $data)) {
            $updates['push_policy'] = $pushPolicy;
        }

        if (array_key_exists('priority_score', $data)) {
            $updates['priority_score'] = max(0, min(100, (int) ($data['priority_score'] ?? 50)));
        }

        if (array_key_exists('confidence_score', $data)) {
            $updates['confidence_score'] = isset($data['confidence_score']) ? (float) $data['confidence_score'] : null;
        }

        if (array_key_exists('expires_at', $data)) {
            $updates['expires_at'] = $data['expires_at'];
        }

        $existing->update($updates);

        $this->audit->record('inbox.deduped', [
            'subject_type' => 'ai_inbox_item',
            'subject_id' => $existing->id,
            'summary' => 'Inbox item deduplicated.',
            'evidence' => ['type' => $type, 'dedupe_key' => $dedupeKey],
            'privacy' => ['sensitivity' => 'private'],
        ]);

        return $existing->refresh();
    }

    private function isDedupeUniqueViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        $message = $exception->getMessage();

        return in_array($sqlState, ['23000', '23505'], true)
            && str_contains($message, 'ai_inbox_items_active_dedupe_unique');
    }

    private function userId(array $data): string
    {
        return $this->nullableString($data['user_id'] ?? null) ?: 'vitor';
    }

    private function validType(string $type): string
    {
        if (! in_array($type, AiInboxItem::TYPES, true)) {
            throw ValidationException::withMessages(['type' => 'Tipo de inbox invalido.']);
        }

        return $type;
    }

    private function validSeverity(string $severity): string
    {
        if (! in_array($severity, ['debug', 'info', 'warning', 'critical'], true)) {
            return 'info';
        }

        return $severity;
    }

    private function validStatus(string $status): string
    {
        if (! in_array($status, AiInboxItem::STATUSES, true)) {
            return 'unread';
        }

        return $status;
    }

    private function initiator(string $initiator): string
    {
        return in_array($initiator, ['operator', 'atlas', 'system', 'job'], true) ? $initiator : 'system';
    }

    private function title(array $data): string
    {
        return Str::limit($this->nullableString($data['title'] ?? null) ?: 'Atlas', 180, '');
    }

    private function deepLink(array $data, string $id): string
    {
        return $this->nullableString($data['deep_link'] ?? null) ?: "atlas://inbox/{$id}";
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<int,array<string,mixed>>  $availableActions
     * @param  array<string,mixed>  $pushPolicy
     * @return array<string,mixed>
     */
    private function payloadWithProactiveDeliveryContract(
        array $payload,
        string $itemId,
        string $type,
        string $severity,
        string $status,
        ?string $dedupeKey,
        ?string $contextBundleId,
        array $availableActions,
        array $pushPolicy,
        string $deepLink,
    ): array {
        $contract = [
            'schema_version' => 'atlas.proactive.delivery_contract.v1',
            'surface' => 'mobile_inbox',
            'inbox_item_id' => $itemId,
            'push_send_mode' => (string) ($pushPolicy['send'] ?? 'auto'),
            'push_pointer_only' => true,
            'authenticated_fetch_required' => true,
            'deep_link_only_delivery' => true,
            'context_bundle_api_only' => $contextBundleId !== null,
            'raw_context_exposed_in_push' => false,
            'raw_payload_exposed_in_push' => false,
            'body_exposed_in_push' => false,
            'auto_action_allowed' => false,
            'action_execution_requires_registry' => true,
            'operator_review_required' => $availableActions !== [],
            'presence_eclipse_governance' => [
                'schema_version' => 'atlas.proactive.presence_eclipse.v1',
                'explicit_opt_out_supported' => true,
                'manual_eclipse_supported' => true,
                'quiet_hours_supported' => true,
                'critical_bypass_policy' => 'critical_can_bypass_eclipse_unless_critical_push_disabled',
                'non_critical_interruption_requires_proactive_push_enabled' => true,
                'push_channel_is_not_source_of_truth' => true,
                'no_surveillance_default' => true,
                'retention_anchor' => 'inbox_item_expires_at_or_mobile_cleanup_policy',
            ],
            'type' => $type,
            'severity' => $severity,
            'status' => $status,
            'available_action_count' => count($availableActions),
            'dedupe_key_hash' => $dedupeKey ? hash('sha256', $dedupeKey) : null,
            'context_bundle_id_hash' => $contextBundleId ? hash('sha256', $contextBundleId) : null,
            'deep_link_hash' => hash('sha256', $deepLink),
            'push_data_fields' => [
                'inbox_id',
                'thread_id',
                'deep_link',
                'open_action',
                'atlas_mode',
                'target',
                'type',
                'severity',
                'unread_count',
                'unread_count_at',
            ],
        ];

        $contract['contract_hash'] = hash('sha256', json_encode($contract, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
        $payload['proactive_delivery_contract'] = $contract;

        return $payload;
    }

    private function defaultExpiresAt(string $type): mixed
    {
        return match ($type) {
            'approval' => now()->addMinutes(max(1, (int) config('atlas.mobile.approval_ttl_minutes', 30))),
            'proposal' => now()->addDays(7),
            'self_diagnostic' => now()->addDays(14),
            'job_result' => now()->addDays(30),
            default => null,
        };
    }

    /**
     * @return array{created_at:Carbon,id:string}|null
     */
    private function decodeCursor(?string $cursor): ?array
    {
        if (! is_string($cursor) || trim($cursor) === '') {
            return null;
        }

        $normalized = strtr($cursor, '-_', '+/');
        $normalized = str_pad($normalized, strlen($normalized) + ((4 - strlen($normalized) % 4) % 4), '=', STR_PAD_RIGHT);
        $raw = base64_decode($normalized, true);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        $createdAt = is_array($data) ? ($data['created_at'] ?? null) : null;
        $id = is_array($data) ? ($data['id'] ?? null) : null;

        if (! is_string($createdAt) || ! is_string($id) || trim($id) === '') {
            throw ValidationException::withMessages(['cursor' => 'Cursor invalido.']);
        }

        try {
            return [
                'created_at' => Carbon::parse($createdAt),
                'id' => $id,
            ];
        } catch (\Throwable) {
            throw ValidationException::withMessages(['cursor' => 'Cursor invalido.']);
        }
    }

    private function encodeCursor(?AiInboxItem $item): ?string
    {
        if (! $item || ! $item->created_at) {
            return null;
        }

        $raw = json_encode([
            'created_at' => $item->created_at->format('Y-m-d\TH:i:s.uP'),
            'id' => $item->id,
        ], JSON_UNESCAPED_SLASHES);

        return rtrim(strtr(base64_encode((string) $raw), '+/', '-_'), '=');
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<int,array<string,mixed>>
     */
    private function actions(string $type, array $data): array
    {
        $provided = $this->array($data['available_actions'] ?? []);
        if ($provided !== []) {
            return array_values($provided);
        }

        return match ($type) {
            'approval' => [
                ['id' => 'approve_once', 'label' => 'Approve once', 'style' => 'primary', 'requires_confirm' => true],
                ['id' => 'approve_session', 'label' => 'Approve session', 'style' => 'default', 'requires_confirm' => true],
                ['id' => 'approve_workspace_1h', 'label' => 'Approve workspace 1h', 'style' => 'default', 'requires_confirm' => true],
                ['id' => 'deny', 'label' => 'Deny', 'style' => 'destructive', 'requires_confirm' => false],
            ],
            'insight' => [
                ['id' => 'discuss', 'label' => 'Discutir com Atlas', 'style' => 'primary'],
                ['id' => 'snooze', 'label' => 'Adiar', 'style' => 'default'],
                ['id' => 'dismiss', 'label' => 'Descartar', 'style' => 'default'],
            ],
            'proposal' => [
                ['id' => 'review_patch', 'label' => 'Revisar proposta', 'style' => 'primary'],
                ['id' => 'discuss', 'label' => 'Discutir com Atlas', 'style' => 'default'],
                ['id' => 'discard', 'label' => 'Descartar', 'style' => 'destructive', 'requires_confirm' => true],
            ],
            'job_result', 'job_status', 'completion' => [
                ['id' => 'view_trace', 'label' => 'Ver trace', 'style' => 'primary'],
                ['id' => 'dismiss', 'label' => 'Descartar', 'style' => 'default'],
            ],
            'self_diagnostic' => [
                ['id' => 'discuss', 'label' => 'Discutir com Atlas', 'style' => 'primary'],
                ['id' => 'create_proposal', 'label' => 'Criar proposta', 'style' => 'default'],
                ['id' => 'ignore_30d', 'label' => 'Ignorar 30 dias', 'style' => 'default'],
                ['id' => 'dismiss', 'label' => 'Descartar', 'style' => 'default'],
            ],
            default => [
                ['id' => 'dismiss', 'label' => 'Descartar', 'style' => 'default'],
            ],
        };
    }

    /**
     * @return array<int|string,mixed>
     */
    private function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
