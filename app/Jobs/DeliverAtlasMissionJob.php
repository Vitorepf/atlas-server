<?php

namespace App\Jobs;

use App\Models\AtlasMissionDelivery;
use App\Services\Ai\RealExecution\AtlasMissionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * G3 — executa uma mission delivery em background (a cadeia completa do
 * AtlasMissionService: brain context → delivery certificada → branch → outcome
 * de volta no cérebro) e persiste o envelope para polling. O branch NUNCA é
 * mesclado — main intocada por construção (invariante do orchestrator).
 */
class DeliverAtlasMissionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1500;

    public int $tries = 1;

    public function __construct(
        public readonly string $deliveryId,
    ) {
        $this->onConnection('database-long');
        $this->onQueue('missions');
    }

    public function handle(AtlasMissionService $missions): void
    {
        $delivery = AtlasMissionDelivery::query()->find($this->deliveryId);
        if ($delivery === null || $delivery->status !== AtlasMissionDelivery::STATUS_QUEUED) {
            return;
        }

        $delivery->forceFill([
            'status' => AtlasMissionDelivery::STATUS_RUNNING,
            'started_at' => now(),
        ])->save();

        try {
            $result = $missions->run((string) $delivery->request);

            $delivered = (bool) ($result['delivered'] ?? false);
            $delivery->forceFill([
                'status' => $delivered ? AtlasMissionDelivery::STATUS_DELIVERED : AtlasMissionDelivery::STATUS_BLOCKED,
                'mission_id' => (string) ($result['mission_id'] ?? ''),
                'branch' => is_string($result['branch'] ?? null) ? $result['branch'] : null,
                'result' => $result,
                'finished_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $delivery->forceFill([
                'status' => AtlasMissionDelivery::STATUS_FAILED,
                'error' => mb_substr($exception->getMessage(), 0, 2000),
                'finished_at' => now(),
            ])->save();
        }
    }

    public function failed(?Throwable $exception): void
    {
        AtlasMissionDelivery::query()
            ->whereKey($this->deliveryId)
            ->whereIn('status', [AtlasMissionDelivery::STATUS_QUEUED, AtlasMissionDelivery::STATUS_RUNNING])
            ->update([
                'status' => AtlasMissionDelivery::STATUS_FAILED,
                'error' => $exception !== null ? mb_substr($exception->getMessage(), 0, 2000) : 'job_failed',
                'finished_at' => now(),
            ]);
    }
}
