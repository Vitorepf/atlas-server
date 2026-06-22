<?php

namespace App\Jobs;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\VslAssetIngestionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class ProcessVslAssetIngestionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 7200; // headroom for a ~2h VSL transcription

    public function __construct(
        public readonly string $assetId,
    ) {}

    /**
     * @return array<int,object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('atlas-vsl-ingestion:'.$this->assetId))->expireAfter($this->timeout),
        ];
    }

    public function handle(VslAssetIngestionService $service): void
    {
        $asset = AiMarketingVslAsset::query()->find($this->assetId);
        if ($asset === null) {
            return;
        }

        $service->transcribe($asset);
        // The structure-extraction pass is dispatched by the extractor slice.
    }

    public function failed(Throwable $exception): void
    {
        $asset = AiMarketingVslAsset::query()->find($this->assetId);
        if ($asset !== null && $asset->status !== 'structured') {
            $asset->forceFill([
                'status' => 'failed',
                'reason' => 'Background VSL ingestion failed: '.$exception->getMessage(),
                'last_ingested_at' => now(),
            ])->save();
        }
    }
}
