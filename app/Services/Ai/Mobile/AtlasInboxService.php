<?php

namespace App\Services\Ai\Mobile;

use App\Models\AiInboxItem;
use App\Models\AtlasMobileDevice;
use App\Services\AuditLogService;
use Illuminate\Support\Carbon;
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

        if ($dedupeKey) {
            $existing = AiInboxItem::query()
                ->where('user_id', $this->userId($data))
                ->where('dedupe_key', $dedupeKey)
                ->whereNotIn('status', ['resolved', 'dismissed', 'expired'])
                ->latest('created_at')
                ->first();

            if ($existing) {
                $payload = $existing->payload ?? [];
                $payload['occurrence_count'] = ((int) ($payload['occurrence_count'] ?? 1)) + 1;
                $payload['last_occurrence_at'] = now()->toJSON();

                $existing->update([
                    'title' => $this->title($data),
                    'summary' => $this->nullableString($data['summary'] ?? null),
                    'body' => $this->nullableString($data['body'] ?? null),
                    'severity' => $severity,
                    'status' => $status,
                    'payload' => array_replace_recursive($payload, $this->array($data['payload'] ?? [])),
                    'available_actions' => $this->actions($type, $data),
                    'deep_link' => $this->deepLink($data, $existing->id),
                ]);

                $this->audit->record('inbox.deduped', [
                    'subject_type' => 'ai_inbox_item',
                    'subject_id' => $existing->id,
                    'summary' => 'Inbox item deduplicated.',
                    'evidence' => ['type' => $type, 'dedupe_key' => $dedupeKey],
                    'privacy' => ['sensitivity' => 'private'],
                ]);

                return $existing->refresh();
            }
        }

        $item = AiInboxItem::query()->create([
            'user_id' => $this->userId($data),
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
            'available_actions' => $this->actions($type, $data),
            'response' => null,
            'payload' => $this->array($data['payload'] ?? []),
            'deep_link' => null,
            'push_policy' => $this->array($data['push_policy'] ?? []),
            'priority_score' => max(0, min(100, (int) ($data['priority_score'] ?? 50))),
            'confidence_score' => isset($data['confidence_score']) ? (float) $data['confidence_score'] : null,
            'expires_at' => $data['expires_at'] ?? $this->defaultExpiresAt($type),
        ]);

        $item->update(['deep_link' => $this->deepLink($data, $item->id)]);

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

    public function list(string $userId = 'vitor', ?string $status = 'unread', ?string $type = null, int $limit = 50)
    {
        $query = AiInboxItem::query()->where('user_id', $userId);

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($type) {
            $query->where('type', $type);
        }

        return $query->latest('created_at')->limit(max(1, min(100, $limit)))->get();
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

    private function defaultExpiresAt(string $type): mixed
    {
        return match ($type) {
            'proposal' => now()->addDays(7),
            'self_diagnostic' => now()->addDays(14),
            'job_result' => now()->addDays(30),
            default => null,
        };
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
