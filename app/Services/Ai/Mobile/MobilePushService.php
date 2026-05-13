<?php

namespace App\Services\Ai\Mobile;

use App\Jobs\SendMobilePushJob;
use App\Models\AiInboxItem;
use App\Models\AtlasMobileDevice;
use App\Models\MobilePushDelivery;
use App\Services\AuditLogService;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class MobilePushService
{
    private const EXPO_ENDPOINT = 'https://exp.host/--/api/v2/push/send';

    public function __construct(
        private readonly AuditLogService $audit,
        private readonly ExpoCircuitBreaker $circuit,
        private readonly MobileNotificationPreferences $preferences,
    ) {}

    public function dispatchForInboxItem(AiInboxItem $item): void
    {
        if (! (bool) config('atlas.mobile.enabled', false)) {
            return;
        }

        if (($item->push_policy['send'] ?? 'auto') === 'none') {
            return;
        }

        if (! $this->pushInfrastructureAvailable()) {
            $this->recordPushUnavailableAudit($item, 'push_infrastructure_unavailable');

            return;
        }

        $devices = AtlasMobileDevice::query()
            ->where('user_id', $item->user_id)
            ->whereNull('revoked_at')
            ->whereNotNull('expo_push_token')
            ->orderByDesc('last_seen_at')
            ->orderByDesc('updated_at')
            ->get()
            ->unique(fn (AtlasMobileDevice $device): string => (string) $device->expo_push_token)
            ->values();

        foreach ($devices as $device) {
            $this->dispatchToDevice($device, $item);
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function replayPendingDispatches(int $limit = 50, bool $dryRun = true): array
    {
        $limit = max(1, min(200, $limit));
        $candidates = AiInboxItem::query()
            ->whereNotIn('status', ['resolved', 'dismissed', 'expired'])
            ->whereDoesntHave('pushDeliveries')
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->filter(fn (AiInboxItem $item): bool => ($item->push_policy['send'] ?? 'auto') !== 'none')
            ->values();
        $dispatched = 0;

        if (! $dryRun) {
            foreach ($candidates as $item) {
                $before = $item->pushDeliveries()->count();
                $this->dispatchForInboxItem($item->refresh());
                $after = $item->pushDeliveries()->count();
                if ($after > $before) {
                    $dispatched++;
                }
            }
        }

        return [
            'schema_version' => 'atlas.mobile.push_replay_pending.v1',
            'dry_run' => $dryRun,
            'limit' => $limit,
            'candidate_count' => $candidates->count(),
            'dispatched_count' => $dispatched,
            'mobile_enabled' => (bool) config('atlas.mobile.enabled', false),
            'items' => $candidates
                ->map(fn (AiInboxItem $item): array => [
                    'id' => $item->id,
                    'type' => $item->type,
                    'category' => $item->category,
                    'severity' => $item->severity,
                    'status' => $item->status,
                    'dedupe_key' => $item->dedupe_key,
                    'push_policy_send' => $item->push_policy['send'] ?? 'auto',
                    'created_at' => $item->created_at?->toJSON(),
                ])
                ->values()
                ->all(),
        ];
    }

    public function dispatchToDevice(AtlasMobileDevice $device, AiInboxItem $item): bool
    {
        if (! $this->pushInfrastructureAvailable()) {
            $this->recordPushUnavailableAudit($item, 'push_infrastructure_unavailable');

            return false;
        }

        if ($device->expo_push_token === null) {
            return false;
        }

        if (($reason = $this->preferences->disabledReason($device, $item)) !== null) {
            $this->recordPushSkipAudit($item, $device, $reason);

            return false;
        }

        $payload = $this->payloadForItem($device, $item);

        if ($this->shouldDeferForQuietHours($item)) {
            $delivery = $this->createDelivery($device, $item, 'deferred_quiet_hours', $payload);
            $this->recordPushAudit('push.deferred', $item, $device, $delivery, ['reason' => 'quiet_hours']);

            return false;
        }

        if ($this->shouldBatch($item)) {
            $delivery = $this->createDelivery($device, $item, 'batched', $payload);
            $this->recordPushAudit('push.deferred', $item, $device, $delivery, ['reason' => 'batched']);

            return false;
        }

        if (! $this->circuit->canAttempt()) {
            $delivery = $this->createDelivery($device, $item, 'deferred_circuit_open', $payload);
            $this->recordPushAudit('push.deferred', $item, $device, $delivery, ['reason' => 'circuit_open']);

            return false;
        }

        if ((bool) config('atlas.mobile.retry.queue_enabled', true)) {
            $delivery = $this->createDelivery($device, $item, 'queued', $payload);
            SendMobilePushJob::dispatch($delivery->id, $device->id, $payload);

            return true;
        }

        return $this->sendToDevice($device, $item, null, $payload);
    }

    public function sendToDevice(AtlasMobileDevice $device, AiInboxItem $item, ?MobilePushDelivery $delivery = null, ?array $payload = null): bool
    {
        if ($device->expo_push_token === null) {
            return false;
        }

        $payload ??= $this->payloadForItem($device, $item);
        $delivery ??= $this->createDelivery($device, $item, 'queued', $payload);

        return $this->sendPayload($device, $delivery, $payload);
    }

    public function flushBatched(?string $deviceId = null): int
    {
        $statuses = ['batched', 'deferred_quiet_hours'];
        if ($this->circuit->canAttempt()) {
            $statuses[] = 'deferred_circuit_open';
        }

        $query = MobilePushDelivery::query()
            ->whereIn('status', $statuses)
            ->orderBy('created_at');

        if ($deviceId) {
            $query->where('device_id', $deviceId);
        }

        /** @var Collection<int, MobilePushDelivery> $deliveries */
        $deliveries = $query->get();
        $sent = 0;

        $deliveries
            ->groupBy('device_id')
            ->each(function (Collection $deviceDeliveries) use (&$sent): void {
                /** @var MobilePushDelivery|null $first */
                $first = $deviceDeliveries->first();
                if (! $first || ! $first->device_id) {
                    return;
                }

                $device = AtlasMobileDevice::query()
                    ->whereKey($first->device_id)
                    ->whereNull('revoked_at')
                    ->first();

                if (! $device || $device->expo_push_token === null) {
                    $deviceDeliveries->each(fn (MobilePushDelivery $delivery) => $delivery->update([
                        'status' => 'failed_permanent',
                        'error_code' => 'device_unavailable',
                        'error_message' => 'Device revoked or missing Expo push token.',
                    ]));

                    return;
                }

                $ready = $deviceDeliveries->filter(function (MobilePushDelivery $delivery): bool {
                    if ($delivery->status !== 'deferred_quiet_hours') {
                        return true;
                    }

                    $item = $delivery->inboxItem;

                    return $item && ! $this->shouldDeferForQuietHours($item);
                })->values();

                if ($ready->isEmpty()) {
                    return;
                }

                $payload = $ready->count() === 1
                    ? $ready->first()->request_payload
                    : $this->payloadForBatch($device, $ready);

                /** @var MobilePushDelivery $representative */
                $representative = $ready->first();
                if ($this->sendPayload($device, $representative, $payload)) {
                    $sent++;
                    $ready->each(function (MobilePushDelivery $delivery) use ($representative, $payload): void {
                        if ($delivery->id === $representative->id) {
                            return;
                        }

                        $delivery->update([
                            'status' => 'sent',
                            'request_payload' => $payload,
                            'response_payload' => $representative->response_payload ?? [],
                            'provider_ticket_id' => $representative->provider_ticket_id,
                            'error_code' => null,
                            'error_message' => null,
                            'attempted_at' => now(),
                        ]);
                    });
                }
            });

        return $sent;
    }

    /**
     * @return array{checked:int,receipt_ok:int,receipt_error:int,missing:int}
     */
    public function fetchReceipts(int $limit = 100): array
    {
        $deliveries = MobilePushDelivery::query()
            ->where('provider', 'expo')
            ->where('status', 'sent')
            ->whereNotNull('provider_ticket_id')
            ->whereNull('provider_receipt_id')
            ->oldest('attempted_at')
            ->limit(max(1, min(100, $limit)))
            ->get();

        if ($deliveries->isEmpty()) {
            return ['checked' => 0, 'receipt_ok' => 0, 'receipt_error' => 0, 'missing' => 0];
        }

        $ids = $deliveries->pluck('provider_ticket_id')->filter()->values()->all();
        $response = Http::timeout(10)->post('https://exp.host/--/api/v2/push/getReceipts', [
            'ids' => $ids,
        ]);
        $body = $response->json() ?: ['body' => $response->body()];
        $counts = ['checked' => 0, 'receipt_ok' => 0, 'receipt_error' => 0, 'missing' => 0];

        foreach ($deliveries as $delivery) {
            $ticketId = $delivery->provider_ticket_id;
            $receipt = is_string($ticketId) ? data_get($body, 'data.'.$ticketId) : null;
            if (! is_array($receipt)) {
                $counts['missing']++;

                continue;
            }

            $counts['checked']++;
            $status = (string) ($receipt['status'] ?? '');
            $errorCode = data_get($receipt, 'details.error') ?: ($receipt['status'] ?? null);
            $errorCode = is_string($errorCode) ? $errorCode : null;
            $message = data_get($receipt, 'message');
            $message = is_string($message) ? $message : null;

            if ($status === 'ok') {
                $delivery->update([
                    'status' => 'receipt_ok',
                    'provider_receipt_id' => $ticketId,
                    'response_payload' => array_merge($delivery->response_payload ?? [], ['receipt' => $receipt]),
                    'error_code' => null,
                    'error_message' => null,
                ]);
                $counts['receipt_ok']++;

                continue;
            }

            $delivery->update([
                'status' => 'receipt_error',
                'provider_receipt_id' => $ticketId,
                'response_payload' => array_merge($delivery->response_payload ?? [], ['receipt' => $receipt]),
                'error_code' => $errorCode,
                'error_message' => $message,
            ]);
            $counts['receipt_error']++;

            if ($errorCode && $this->isPermanentExpoErrorCode($errorCode) && $delivery->device) {
                $delivery->device->update([
                    'expo_push_token' => null,
                    'push_token_hash' => null,
                ]);
            }
        }

        return $counts;
    }

    /**
     * Versao para o caminho do Job: nao retry inline, throw em falhas transient
     * para Laravel re-enfileirar com backoff configurado.
     *
     * @param  array<string,mixed>  $payload
     */
    public function sendPayloadFromJob(AtlasMobileDevice $device, MobilePushDelivery $delivery, array $payload, int $jobAttempt = 1): void
    {
        $item = $delivery->inboxItem;
        if ($item && ($reason = $this->preferences->disabledReason($device, $item)) !== null) {
            $delivery->update([
                'status' => 'skipped_preferences',
                'error_code' => $reason,
                'attempted_at' => now(),
            ]);
            $this->recordPushAudit('push.skipped', $item, $device, $delivery->refresh(), ['reason' => $reason]);

            return;
        }

        if (! $this->circuit->canAttempt()) {
            $delivery->update([
                'status' => 'deferred_circuit_open',
                'error_code' => 'circuit_open',
                'attempted_at' => now(),
            ]);

            throw new RuntimeException('Expo circuit breaker open; deferring push.');
        }

        $delivery->update([
            'status' => 'queued',
            'request_payload' => $payload,
            'attempted_at' => now(),
        ]);

        try {
            $response = Http::timeout(10)->post(self::EXPO_ENDPOINT, $payload);
            $body = $response->json() ?: ['body' => $response->body()];
            $ok = $response->successful();
            $permanent = $this->isPermanentExpoFailure($response);
            $ticketId = data_get($body, 'data.id');

            $finalStatus = $ok && ! $permanent ? 'sent' : ($permanent ? 'failed_permanent' : 'failed_transient');
            $errorCode = $ok && ! $permanent ? null : $this->expoErrorCode($response);

            $delivery->update([
                'status' => $finalStatus,
                'provider_ticket_id' => is_string($ticketId) ? $ticketId : null,
                'response_payload' => is_array($body) ? $body : ['response' => $body],
                'error_code' => $errorCode,
                'error_message' => $ok && ! $permanent ? null : $response->body(),
            ]);

            if ($permanent) {
                $device->update([
                    'expo_push_token' => null,
                    'push_token_hash' => null,
                ]);
                $this->recordPushAudit('push.invalid_token', $delivery->inboxItem ?? null, $device, $delivery->refresh(), ['error_code' => $errorCode]);
                $this->circuit->recordSuccess();
                $this->recordPushAudit('push.failed', $delivery->inboxItem ?? null, $device, $delivery->refresh(), [
                    'attempt' => $jobAttempt,
                    'http_status' => $response->status(),
                    'error_code' => $errorCode,
                    'permanent' => true,
                ]);

                return;
            }

            if ($ok) {
                $this->circuit->recordSuccess();
                $this->recordPushAudit('push.sent', $delivery->inboxItem ?? null, $device, $delivery->refresh(), [
                    'attempt' => $jobAttempt,
                    'http_status' => $response->status(),
                ]);

                return;
            }

            $this->circuit->recordFailure();
            $this->recordPushAudit('push.failed', $delivery->inboxItem ?? null, $device, $delivery->refresh(), [
                'attempt' => $jobAttempt,
                'http_status' => $response->status(),
                'error_code' => $errorCode,
            ]);

            throw new RuntimeException("Expo push transient failure: {$errorCode}");
        } catch (RuntimeException $rethrow) {
            throw $rethrow;
        } catch (Throwable $throwable) {
            report($throwable);
            $delivery->update([
                'status' => 'failed_transient',
                'error_code' => 'exception',
                'error_message' => $throwable->getMessage(),
            ]);
            $this->circuit->recordFailure();
            $this->recordPushAudit('push.failed', $delivery->inboxItem ?? null, $device, $delivery->refresh(), [
                'attempt' => $jobAttempt,
                'error_code' => 'exception',
            ]);

            throw $throwable;
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function sendPayload(AtlasMobileDevice $device, MobilePushDelivery $delivery, array $payload, int $attempt = 1): bool
    {
        if (! $this->circuit->canAttempt()) {
            $delivery->update([
                'status' => 'deferred_circuit_open',
                'error_code' => 'circuit_open',
                'attempted_at' => now(),
            ]);
            $this->recordPushAudit('push.deferred', $delivery->inboxItem ?? null, $device, $delivery->refresh(), ['reason' => 'circuit_open']);

            return false;
        }

        $delivery->update([
            'status' => 'queued',
            'request_payload' => $payload,
            'attempted_at' => now(),
        ]);

        try {
            $response = Http::timeout(10)->post(self::EXPO_ENDPOINT, $payload);
            $body = $response->json() ?: ['body' => $response->body()];
            $ok = $response->successful();
            $permanent = $this->isPermanentExpoFailure($response);
            $ticketId = data_get($body, 'data.id');

            $finalStatus = $ok && ! $permanent ? 'sent' : ($permanent ? 'failed_permanent' : 'failed_transient');
            $errorCode = $ok && ! $permanent ? null : $this->expoErrorCode($response);

            $delivery->update([
                'status' => $finalStatus,
                'provider_ticket_id' => is_string($ticketId) ? $ticketId : null,
                'response_payload' => is_array($body) ? $body : ['response' => $body],
                'error_code' => $errorCode,
                'error_message' => $ok && ! $permanent ? null : $response->body(),
            ]);

            if ($permanent) {
                $device->update([
                    'expo_push_token' => null,
                    'push_token_hash' => null,
                ]);
                $this->recordPushAudit('push.invalid_token', $delivery->inboxItem ?? null, $device, $delivery->refresh(), ['error_code' => $errorCode]);
            }

            if ($ok && ! $permanent) {
                $this->circuit->recordSuccess();
            } elseif (! $permanent) {
                $this->circuit->recordFailure();
            }

            $auditEvent = $finalStatus === 'sent' ? 'push.sent' : 'push.failed';
            $this->recordPushAudit($auditEvent, $delivery->inboxItem ?? null, $device, $delivery->refresh(), [
                'attempt' => $attempt,
                'http_status' => $response->status(),
                'error_code' => $errorCode,
            ]);

            if (! $ok && ! $permanent && $attempt < 2) {
                return $this->sendPayload($device, $delivery, $payload, $attempt + 1);
            }

            return $ok && ! $permanent;
        } catch (Throwable $throwable) {
            report($throwable);
            $delivery->update([
                'status' => 'failed_transient',
                'error_code' => 'exception',
                'error_message' => $throwable->getMessage(),
            ]);
            $this->circuit->recordFailure();

            $this->recordPushAudit('push.failed', $delivery->inboxItem ?? null, $device, $delivery->refresh(), [
                'attempt' => $attempt,
                'error_code' => 'exception',
            ]);

            if ($attempt < 2) {
                return $this->sendPayload($device, $delivery, $payload, $attempt + 1);
            }

            return false;
        }
    }

    /**
     * @param  array<string,mixed>  $extra
     */
    private function recordPushAudit(string $event, ?AiInboxItem $item, AtlasMobileDevice $device, MobilePushDelivery $delivery, array $extra = []): void
    {
        $this->audit->record($event, [
            'subject_type' => 'mobile_push_delivery',
            'subject_id' => $delivery->id,
            'actor_type' => 'system',
            'actor_id' => null,
            'severity' => match ($event) {
                'push.failed', 'push.invalid_token' => 'warning',
                default => 'info',
            },
            'summary' => match ($event) {
                'push.sent' => 'Push enviado.',
                'push.failed' => 'Push falhou.',
                'push.deferred' => 'Push adiado.',
                'push.skipped' => 'Push bloqueado por preferencia do device.',
                'push.invalid_token' => 'Push invalidou token do device.',
                default => $event,
            },
            'evidence' => array_merge([
                'inbox_item_id' => $item?->id,
                'device_id_hash' => hash('sha256', $device->id),
                'raw_device_id_persisted' => false,
                'delivery_status' => $delivery->status,
                'provider' => $delivery->provider,
                'delivery_attempt_contract' => $this->deliveryAttemptContract($item, $device, $delivery),
            ], $extra),
            'privacy' => ['sensitivity' => 'private'],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function deliveryAttemptContract(?AiInboxItem $item, AtlasMobileDevice $device, MobilePushDelivery $delivery): array
    {
        $requestPayload = is_array($delivery->request_payload) ? $delivery->request_payload : [];
        $data = is_array($requestPayload['data'] ?? null) ? $requestPayload['data'] : [];
        $dataKeys = array_values(array_filter(array_keys($data), 'is_string'));
        sort($dataKeys);

        $proactiveContract = is_array(data_get($item?->payload ?? [], 'proactive_delivery_contract'))
            ? data_get($item?->payload ?? [], 'proactive_delivery_contract')
            : [];

        $contract = [
            'schema_version' => 'atlas.proactive.push_delivery_attempt.v1',
            'delivery_id' => $delivery->id,
            'inbox_item_id' => $item?->id,
            'device_id_hash' => hash('sha256', $device->id),
            'provider' => $delivery->provider,
            'status' => $delivery->status,
            'proactive_delivery_contract_schema' => data_get($proactiveContract, 'schema_version'),
            'proactive_delivery_contract_hash' => data_get($proactiveContract, 'contract_hash'),
            'request_payload_hash' => hash('sha256', json_encode($requestPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
            'push_pointer_only' => true,
            'authenticated_fetch_required' => true,
            'deep_link_only_delivery' => true,
            'raw_context_exposed_in_push' => false,
            'raw_payload_exposed_in_push' => false,
            'body_exposed_in_push' => false,
            'raw_device_id_persisted_in_audit' => false,
            'auto_action_allowed' => false,
            'provider_payload_data_keys' => $dataKeys,
        ];

        $contract['contract_hash'] = hash('sha256', json_encode($contract, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');

        return $contract;
    }

    private function recordPushSkipAudit(AiInboxItem $item, AtlasMobileDevice $device, string $reason): void
    {
        $this->audit->record('push.skipped', [
            'subject_type' => 'ai_inbox_item',
            'subject_id' => $item->id,
            'actor_type' => 'system',
            'severity' => 'info',
            'summary' => 'Push bloqueado por preferencia do device.',
            'evidence' => [
                'inbox_item_id' => $item->id,
                'device_id_hash' => hash('sha256', $device->id),
                'raw_device_id_persisted' => false,
                'reason' => $reason,
                'type' => $item->type,
                'category' => $item->category,
                'severity' => $item->severity,
            ],
            'privacy' => ['sensitivity' => 'private'],
        ]);
    }

    private function recordPushUnavailableAudit(AiInboxItem $item, string $reason): void
    {
        $this->audit->record('push.unavailable', [
            'subject_type' => 'ai_inbox_item',
            'subject_id' => $item->id,
            'actor_type' => 'system',
            'severity' => 'warning',
            'summary' => 'Push indisponivel; Inbox item preservado.',
            'evidence' => [
                'inbox_item_id' => $item->id,
                'reason' => $reason,
                'missing_tables' => $this->missingPushTables(),
                'type' => $item->type,
                'category' => $item->category,
                'severity' => $item->severity,
            ],
            'privacy' => ['sensitivity' => 'private'],
        ]);
    }

    private function pushInfrastructureAvailable(): bool
    {
        return $this->missingPushTables() === [];
    }

    /**
     * @return array<int,string>
     */
    private function missingPushTables(): array
    {
        return array_values(array_filter([
            Schema::hasTable('atlas_mobile_devices') ? null : 'atlas_mobile_devices',
            Schema::hasTable('mobile_push_deliveries') ? null : 'mobile_push_deliveries',
        ]));
    }

    private function createDelivery(AtlasMobileDevice $device, AiInboxItem $item, string $status, array $payload): MobilePushDelivery
    {
        return MobilePushDelivery::query()->create([
            'inbox_item_id' => $item->id,
            'device_id' => $device->id,
            'status' => $status,
            'provider' => 'expo',
            'request_payload' => $payload,
            'response_payload' => [],
            'attempted_at' => now(),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function payloadForItem(AtlasMobileDevice $device, AiInboxItem $item): array
    {
        $unreadCount = $this->badgeCount($item->user_id);
        $computedAt = now()->toJSON();

        return [
            'to' => $device->expo_push_token,
            'title' => $this->pushTitle($item),
            'body' => $this->pushBody($item),
            'sound' => 'atlas-bronze.wav',
            'priority' => $item->severity === 'critical' ? 'high' : 'default',
            'badge' => $unreadCount,
            'data' => [
                'inbox_id' => $item->id,
                'thread_id' => $this->string(data_get($item->payload ?? [], 'discussion_thread_id')),
                'deep_link' => $this->pushDeepLinkForItem($item),
                'open_action' => $this->shouldOpenAtlasFromPush($item) ? 'discuss' : null,
                'atlas_mode' => $this->shouldOpenAtlasFromPush($item) ? 'operational' : null,
                'target' => $this->shouldOpenAtlasFromPush($item) ? 'atlas_ai' : 'inbox',
                'type' => $item->type,
                'severity' => $item->severity,
                'unread_count' => $unreadCount,
                'unread_count_at' => $computedAt,
            ],
        ];
    }

    /**
     * @param  Collection<int,MobilePushDelivery>  $deliveries
     * @return array<string,mixed>
     */
    private function payloadForBatch(AtlasMobileDevice $device, Collection $deliveries): array
    {
        $count = $deliveries->count();
        $unreadCount = $this->badgeCount($device->user_id);
        $computedAt = now()->toJSON();

        return [
            'to' => $device->expo_push_token,
            'title' => 'Atlas',
            'body' => "Atlas: {$count} updates no Inbox.",
            'sound' => 'atlas-bronze.wav',
            'priority' => 'default',
            'badge' => $unreadCount,
            'data' => [
                'deep_link' => 'atlas://inbox',
                'type' => 'batch',
                'severity' => 'info',
                'inbox_ids' => $deliveries->pluck('inbox_item_id')->values()->all(),
                'unread_count' => $unreadCount,
                'unread_count_at' => $computedAt,
            ],
        ];
    }

    private function badgeCount(string $userId): int
    {
        return AiInboxItem::query()
            ->where('user_id', $userId)
            ->where('status', 'unread')
            ->count();
    }

    private function shouldDeferForQuietHours(AiInboxItem $item): bool
    {
        if (! (bool) config('atlas.mobile.quiet_hours.enabled', false)) {
            return false;
        }

        if ($this->severityRank($item->severity) >= $this->severityRank((string) config('atlas.mobile.quiet_hours.severity_threshold', 'critical'))) {
            return false;
        }

        $start = (string) config('atlas.mobile.quiet_hours.start', '22:00');
        $end = (string) config('atlas.mobile.quiet_hours.end', '07:00');
        $now = now()->format('H:i');

        if ($start <= $end) {
            return $now >= $start && $now < $end;
        }

        return $now >= $start || $now < $end;
    }

    private function shouldBatch(AiInboxItem $item): bool
    {
        if (! (bool) config('atlas.mobile.batching.enabled', true)) {
            return false;
        }

        if (($item->push_policy['send'] ?? 'auto') === 'immediate' || ($item->push_policy['force'] ?? false) === true) {
            return false;
        }

        if (in_array($item->type, ['approval'], true)) {
            return false;
        }

        return $item->severity !== 'critical';
    }

    private function severityRank(string $severity): int
    {
        return match ($severity) {
            'critical' => 3,
            'warning' => 2,
            'info' => 1,
            default => 0,
        };
    }

    private function isPermanentExpoFailure(Response $response): bool
    {
        return $this->isPermanentExpoErrorCode($this->expoErrorCode($response));
    }

    private function isPermanentExpoErrorCode(string $code): bool
    {
        return in_array($code, ['DeviceNotRegistered', 'InvalidCredentials'], true);
    }

    private function expoErrorCode(Response $response): string
    {
        $body = $response->json() ?: [];
        $code = data_get($body, 'data.details.error')
            ?? data_get($body, 'errors.0.code')
            ?? data_get($body, 'data.status');

        return is_string($code) && $code !== '' ? $code : (string) $response->status();
    }

    private function pushBody(AiInboxItem $item): string
    {
        if ($this->isTelemetryHealthInsight($item)) {
            $score = data_get($item->payload ?? [], 'health.health_score');
            $status = (string) data_get($item->payload ?? [], 'health.status', $item->severity);
            $scoreText = is_numeric($score) ? "score {$score}/100." : '.';

            return $status === 'critical'
                ? 'Saude do Atlas esta critica: '.$scoreText.' Toque para ver causas e proximos passos.'
                : 'Atlas precisa de atencao: '.$scoreText.' Toque para revisar o diagnostico.';
        }

        return match ($item->type) {
            'insight' => 'Atlas encontrou um insight para revisar.',
            'proposal' => 'Atlas preparou uma proposta para sua revisao.',
            'self_diagnostic' => 'Atlas detectou uma mudanca no proprio desempenho.',
            'job_result' => 'Um job importante terminou.',
            'job_status' => 'Um job do Atlas mudou de status.',
            'approval' => 'Atlas precisa de uma aprovacao.',
            'alert' => 'Atlas detectou um alerta importante.',
            'completion' => 'Uma tarefa do Atlas terminou.',
            'capture' => 'Uma captura foi processada.',
            'thread_update' => 'Uma thread do Atlas foi atualizada.',
            default => 'Atlas tem uma atualizacao no Inbox.',
        };
    }

    private function pushTitle(AiInboxItem $item): string
    {
        if ($this->isTelemetryHealthInsight($item)) {
            return 'Atlas precisa de revisao';
        }

        return 'Atlas';
    }

    private function pushDeepLinkForItem(AiInboxItem $item): string
    {
        if ($this->shouldOpenAtlasFromPush($item)) {
            return "atlas://inbox/{$item->id}/discuss";
        }

        return $item->deep_link ?: "atlas://inbox/{$item->id}";
    }

    private function shouldOpenAtlasFromPush(AiInboxItem $item): bool
    {
        $requestedTarget = data_get($item->push_policy ?? [], 'target');
        if ($requestedTarget === 'inbox') {
            return false;
        }

        if (! $this->hasAction($item, 'discuss')) {
            return false;
        }

        if ($requestedTarget === 'atlas_ai') {
            return true;
        }

        return $this->isTelemetryHealthInsight($item)
            || $this->severityRank($item->severity) >= $this->severityRank('warning');
    }

    private function isTelemetryHealthInsight(AiInboxItem $item): bool
    {
        return $item->type === 'insight'
            && (data_get($item->payload ?? [], 'insight_kind') === 'atlas_ai_telemetry_health'
                || str_contains((string) $item->dedupe_key, 'atlas-ai-telemetry-health'));
    }

    private function hasAction(AiInboxItem $item, string $actionId): bool
    {
        foreach ($item->available_actions ?? [] as $action) {
            if (data_get($action, 'id') === $actionId) {
                return true;
            }
        }

        return false;
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
