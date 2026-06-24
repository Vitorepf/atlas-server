<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Observability;

use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopDeliveryPipeline;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

final class AtlasLoopQueueDryingAlarmDetector
{
    public const SCHEMA_VERSION = 'atlas.loop.queue_drying_alarm.v1';

    private const BUCKET_COUNT = 4;

    private readonly string $signalsDir;

    public function __construct(?string $signalsDir = null)
    {
        $this->signalsDir = rtrim($signalsDir ?? storage_path('app/atlas-loop/signals'), '/');
    }

    /**
     * @return array{schema_version:string,campaign_id:string,window_seconds:int,evaluated_at:string,drying:bool,slope_signal:string,fact_evidence:array<string,mixed>}
     */
    public function evaluate(string $campaignId, int $windowSeconds = 7200): array
    {
        $windowSeconds = max(self::BUCKET_COUNT, $windowSeconds);
        $now = Carbon::now('UTC');

        if (! $this->tableReady()) {
            return $this->result($campaignId, $windowSeconds, $now, false, 'indeterminate', [
                'table_present' => false,
                'required_columns_present' => false,
                'buckets' => [0, 0, 0, 0],
                'decision_signals_in_window' => 0,
            ]);
        }

        $buckets = $this->inFlightBuckets($campaignId, $windowSeconds, $now);
        $slopeSignal = $this->slopeSignal($buckets);
        $decisionSignals = $this->decisionSignalsInWindow($campaignId, $windowSeconds, $now);
        $drying = in_array($slopeSignal, ['empty_terminal', 'decreasing_halved', 'flat_empty'], true)
            && $decisionSignals > 0;

        return $this->result($campaignId, $windowSeconds, $now, $drying, $slopeSignal, [
            'table_present' => true,
            'required_columns_present' => true,
            'buckets' => $buckets,
            'bucket_seconds' => (int) floor($windowSeconds / self::BUCKET_COUNT),
            'decision_signals_in_window' => $decisionSignals,
        ]);
    }

    /**
     * @param  array<string,mixed>  $factEvidence
     * @return array{schema_version:string,campaign_id:string,window_seconds:int,evaluated_at:string,drying:bool,slope_signal:string,fact_evidence:array<string,mixed>}
     */
    private function result(
        string $campaignId,
        int $windowSeconds,
        Carbon $now,
        bool $drying,
        string $slopeSignal,
        array $factEvidence,
    ): array {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'campaign_id' => $campaignId,
            'window_seconds' => $windowSeconds,
            'evaluated_at' => $now->toIso8601String(),
            'drying' => $drying,
            'slope_signal' => $slopeSignal,
            'fact_evidence' => $factEvidence,
        ];
    }

    private function tableReady(): bool
    {
        $table = AtlasLoopDeliveryPipeline::TABLE;
        foreach (['campaign_id', 'claim_owner', 'lease_expires_at', 'updated_at'] as $column) {
            if (! DatabaseTableAvailability::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<int>
     */
    private function inFlightBuckets(string $campaignId, int $windowSeconds, Carbon $now): array
    {
        $bucketSize = $windowSeconds / self::BUCKET_COUNT;
        $windowStart = $now->copy()->subSeconds($windowSeconds);
        $buckets = [];

        for ($index = 0; $index < self::BUCKET_COUNT; $index++) {
            $start = $windowStart->copy()->addSeconds((int) floor($bucketSize * $index));
            $end = $index === self::BUCKET_COUNT - 1
                ? $now->copy()->addSecond()
                : $windowStart->copy()->addSeconds((int) floor($bucketSize * ($index + 1)));

            $buckets[] = $this->countInFlightAt($campaignId, $start, $end, $now);
        }

        return $buckets;
    }

    private function countInFlightAt(string $campaignId, Carbon $start, Carbon $end, Carbon $now): int
    {
        try {
            return (int) DB::table(AtlasLoopDeliveryPipeline::TABLE)
                ->where('campaign_id', $campaignId)
                ->where('updated_at', '>=', $start)
                ->where('updated_at', '<', $end)
                ->whereNotNull('claim_owner')
                ->where('claim_owner', '<>', '')
                ->where('lease_expires_at', '>', $now)
                ->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @param  list<int>  $buckets
     */
    private function slopeSignal(array $buckets): string
    {
        [$oldest, $second, $third, $newest] = $buckets;

        if ($oldest === 0 && $second === 0 && $third === 0 && $newest === 0) {
            return 'flat_empty';
        }

        if ($oldest > $newest && $newest === 0) {
            return 'empty_terminal';
        }

        if ($oldest >= $second && $second >= $third && $third >= $newest && $newest < ($oldest / 2)) {
            return 'decreasing_halved';
        }

        return 'stable_or_growing';
    }

    private function decisionSignalsInWindow(string $campaignId, int $windowSeconds, Carbon $now): int
    {
        $start = $now->copy()->subSeconds($windowSeconds);
        $count = 0;

        foreach ($this->signalPaths() as $path) {
            $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (! is_array($lines)) {
                continue;
            }

            foreach ($lines as $line) {
                $signal = json_decode((string) $line, true);
                if (! is_array($signal) || (string) ($signal['campaign_id'] ?? '') !== $campaignId) {
                    continue;
                }
                if (strtolower(trim((string) ($signal['stage'] ?? ''))) !== 'decision') {
                    continue;
                }
                $emittedAt = $this->parseTime($signal['emitted_at'] ?? null);
                if ($emittedAt === null || $emittedAt->lt($start) || $emittedAt->gt($now)) {
                    continue;
                }

                $count++;
            }
        }

        return $count;
    }

    /**
     * @return list<string>
     */
    private function signalPaths(): array
    {
        if (! is_dir($this->signalsDir)) {
            return [];
        }

        $paths = glob($this->signalsDir.'/*.jsonl') ?: [];
        sort($paths, SORT_STRING);

        return array_values($paths);
    }

    private function parseTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->setTimezone('UTC');
        } catch (Throwable) {
            return null;
        }
    }
}
