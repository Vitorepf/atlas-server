<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Quaternity\LoopIntentDrift;

/** Immutable VO for one intent-drift ledger receipt. */
final class AtlasLoopIntentDriftReceipt
{
    /**
     * @param  array<string,mixed>  $detectorFact
     * @param  array<string,mixed>  $recalibration
     */
    public function __construct(
        public readonly string $receiptId,
        public readonly string $recordedAt,
        public readonly array $detectorFact,
        public readonly array $recalibration,
        public readonly string $contentHash,
    ) {}

    /**
     * @return array{receipt_id:string, recorded_at:string, detector_fact:array<string,mixed>, recalibration:array<string,mixed>, content_hash:string}
     */
    public function toArray(): array
    {
        return [
            'receipt_id' => $this->receiptId,
            'recorded_at' => $this->recordedAt,
            'detector_fact' => $this->detectorFact,
            'recalibration' => $this->recalibration,
            'content_hash' => $this->contentHash,
        ];
    }
}
