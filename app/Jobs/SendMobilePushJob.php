<?php

namespace App\Jobs;

use App\Models\AtlasMobileDevice;
use App\Models\MobilePushDelivery;
use App\Services\Ai\Mobile\MobilePushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendMobilePushJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 30;

    /**
     * @param  array<string,mixed>  $payload
     */
    public function __construct(
        public readonly string $deliveryId,
        public readonly string $deviceId,
        public readonly array $payload,
    ) {
    }

    /**
     * @return array<int,int>
     */
    public function backoff(): array
    {
        $configured = config('atlas.mobile.retry.backoff_seconds', [30, 120, 600, 1800]);
        $values = is_array($configured) ? array_values($configured) : [30, 120, 600, 1800];

        return array_map(fn ($v): int => max(5, (int) $v), $values);
    }

    public function handle(MobilePushService $push): void
    {
        $delivery = MobilePushDelivery::query()->find($this->deliveryId);
        if (! $delivery) {
            return;
        }

        if (in_array($delivery->status, ['sent', 'receipt_ok', 'failed_permanent'], true)) {
            return;
        }

        $device = AtlasMobileDevice::query()->find($this->deviceId);
        if (! $device || $device->revoked_at !== null || $device->expo_push_token === null) {
            $delivery->update([
                'status' => 'failed_permanent',
                'error_code' => 'device_unavailable',
                'error_message' => 'Device revoked or token missing.',
                'attempted_at' => now(),
            ]);

            return;
        }

        $push->sendPayloadFromJob($device, $delivery, $this->payload, $this->attempts());
    }

    public function failed(\Throwable $throwable): void
    {
        $delivery = MobilePushDelivery::query()->find($this->deliveryId);
        if (! $delivery) {
            return;
        }

        if (in_array($delivery->status, ['sent', 'receipt_ok'], true)) {
            return;
        }

        $delivery->update([
            'status' => 'failed_permanent',
            'error_code' => $delivery->error_code ?? 'job_exhausted',
            'error_message' => $delivery->error_message ?? $throwable->getMessage(),
        ]);
    }
}
