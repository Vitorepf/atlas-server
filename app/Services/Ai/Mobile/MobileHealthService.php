<?php

namespace App\Services\Ai\Mobile;

use App\Models\AiInboxItem;
use App\Models\AtlasMobileDevice;
use App\Models\MobilePushDelivery;
use App\Services\Ai\Support\DatabaseTableAvailability;

class MobileHealthService
{
    public function __construct(private readonly ExpoCircuitBreaker $circuit) {}

    /**
     * @return array<string,mixed>
     */
    public function snapshot(string $userId): array
    {
        $hasDeliveries = DatabaseTableAvailability::has('mobile_push_deliveries');
        $hasInbox = DatabaseTableAvailability::has('ai_inbox_items');
        $hasDevices = DatabaseTableAvailability::has('atlas_mobile_devices');

        $now = now();
        $since = $now->copy()->subDay();

        $deliveries24h = $hasDeliveries
            ? MobilePushDelivery::query()
                ->where('attempted_at', '>=', $since)
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->all()
            : [];

        $lastSent = $hasDeliveries
            ? MobilePushDelivery::query()
                ->whereIn('status', ['sent', 'receipt_ok'])
                ->latest('attempted_at')
                ->value('attempted_at')
            : null;
        $lastFailed = $hasDeliveries
            ? MobilePushDelivery::query()
                ->whereIn('status', ['failed_permanent', 'failed_transient', 'receipt_error'])
                ->latest('attempted_at')
                ->value('attempted_at')
            : null;

        $pendingReceipts = $hasDeliveries
            ? MobilePushDelivery::query()
                ->where('provider', 'expo')
                ->where('status', 'sent')
                ->whereNotNull('provider_ticket_id')
                ->whereNull('provider_receipt_id')
                ->count()
            : 0;

        $inboxCounts = $hasInbox
            ? AiInboxItem::query()
                ->where('user_id', $userId)
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->all()
            : [];

        $inboxUnread = (int) ($inboxCounts['unread'] ?? 0);
        $inboxActive = $hasInbox
            ? AiInboxItem::query()
                ->where('user_id', $userId)
                ->whereNotIn('status', ['resolved', 'dismissed', 'expired'])
                ->where(function ($q) use ($now): void {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', $now);
                })
                ->where(function ($q) use ($now): void {
                    $q
                        ->where('status', '!=', 'snoozed')
                        ->orWhereNull('snoozed_until')
                        ->orWhere('snoozed_until', '<=', $now);
                })
                ->count()
            : 0;

        $activeDevices = $hasDevices
            ? AtlasMobileDevice::query()
                ->where('user_id', $userId)
                ->whereNull('revoked_at')
                ->count()
            : 0;
        $devicesWithPush = $hasDevices
            ? AtlasMobileDevice::query()
                ->where('user_id', $userId)
                ->whereNull('revoked_at')
                ->whereNotNull('expo_push_token')
                ->count()
            : 0;
        $lastSeen = $hasDevices
            ? AtlasMobileDevice::query()
                ->where('user_id', $userId)
                ->whereNull('revoked_at')
                ->max('last_seen_at')
            : null;

        $pushSent24h = (int) ($deliveries24h['sent'] ?? 0);
        $pushFailed24h = (int) (($deliveries24h['failed_permanent'] ?? 0) + ($deliveries24h['failed_transient'] ?? 0));
        $pushDeferred24h = (int) (($deliveries24h['deferred_quiet_hours'] ?? 0) + ($deliveries24h['batched'] ?? 0) + ($deliveries24h['deferred_circuit_open'] ?? 0));
        $pushTotal24h = array_sum(array_map('intval', $deliveries24h));
        $pushSuccessRate = $pushTotal24h > 0 ? round($pushSent24h / $pushTotal24h, 4) : null;
        $mobileEnabled = (bool) config('atlas.mobile.enabled', false);

        return [
            'generated_at' => $now->toJSON(),
            'status' => $this->overallStatus($mobileEnabled, $lastSent, $lastFailed, $pendingReceipts, $pushSuccessRate),
            'configuration' => [
                'mobile_enabled' => $mobileEnabled,
                'push_dispatch_enabled' => $mobileEnabled,
                'push_dispatch_blocked_reason' => $mobileEnabled ? null : 'atlas_mobile_disabled',
            ],
            'expo' => [
                'last_success_at' => $this->toJson($lastSent),
                'last_failure_at' => $this->toJson($lastFailed),
                'pending_receipts' => $pendingReceipts,
                'circuit' => $this->circuit->state(),
            ],
            'push_24h' => [
                'total' => $pushTotal24h,
                'sent' => $pushSent24h,
                'failed' => $pushFailed24h,
                'deferred' => $pushDeferred24h,
                'success_rate' => $pushSuccessRate,
                'by_status' => $deliveries24h,
            ],
            'devices' => [
                'active' => $activeDevices,
                'with_push_token' => $devicesWithPush,
                'last_seen_at' => $this->toJson($lastSeen),
            ],
            'inbox' => [
                'unread' => $inboxUnread,
                'active' => $inboxActive,
                'by_status' => $inboxCounts,
            ],
        ];
    }

    private function overallStatus(bool $mobileEnabled, mixed $lastSent, mixed $lastFailed, int $pendingReceipts, ?float $successRate): string
    {
        if (! $mobileEnabled) {
            return 'disabled';
        }

        if ($this->circuit->isOpen()) {
            return 'degraded';
        }

        if ($successRate !== null && $successRate < 0.6) {
            return 'degraded';
        }

        if ($pendingReceipts > 50) {
            return 'degraded';
        }

        return 'healthy';
    }

    private function toJson(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        if (method_exists($value, 'toJSON')) {
            return $value->toJSON();
        }

        return (string) $value;
    }
}
