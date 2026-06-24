<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Observability;

use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopDeliveryPipeline;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

final class AtlasLoopStagnationAlarmDetector
{
    public const SCHEMA_VERSION = 'atlas.loop.stagnation_alarm.v1';

    /**
     * @return array{schema_version:string,campaign_id:string,window_seconds:int,evaluated_at:string,stagnated:bool,reason_code:string,fact_evidence:array<string,int>}
     */
    public function evaluate(string $campaignId, ?int $windowSeconds = 3600): array
    {
        $windowSeconds = max(1, (int) ($windowSeconds ?? 3600));
        $now = Carbon::now('UTC');
        $start = $now->copy()->subSeconds($windowSeconds);
        $signals = $this->countSignalsInWindow($campaignId, $start, $now);
        $pipeline = $this->pipelineFactsForWindow($campaignId, $windowSeconds);
        $reason = $this->reasonCode($signals, $pipeline);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'campaign_id' => $campaignId,
            'window_seconds' => $windowSeconds,
            'evaluated_at' => $now->toIso8601String(),
            'stagnated' => $reason === 'merging_dry',
            'reason_code' => $reason,
            'fact_evidence' => [
                'decision_count' => (int) $signals['decision_count'],
                'merge_count' => (int) $signals['merge_count'],
                'certification_count' => (int) $signals['certification_count'],
                'pipeline_rows' => (int) $pipeline['pipeline_rows'],
                'max_stage_age_seconds' => (int) $pipeline['max_stage_age_seconds'],
            ],
        ];
    }

    /**
     * @return array{present:bool,decision_count:int,merge_count:int,certification_count:int}
     */
    private function countSignalsInWindow(string $campaignId, Carbon $start, Carbon $now): array
    {
        $facts = [
            'present' => false,
            'decision_count' => 0,
            'merge_count' => 0,
            'certification_count' => 0,
        ];

        foreach ($this->signalPaths($start, $now) as $path) {
            if (! is_file($path) || ! is_readable($path)) {
                continue;
            }
            $facts['present'] = true;
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (! is_array($lines)) {
                continue;
            }
            foreach ($lines as $line) {
                $row = json_decode((string) $line, true);
                if (! is_array($row) || (string) ($row['campaign_id'] ?? '') !== $campaignId) {
                    continue;
                }
                $emittedAt = $this->parseTime($row['emitted_at'] ?? null);
                if ($emittedAt === null || $emittedAt->lt($start) || $emittedAt->gt($now)) {
                    continue;
                }
                $stage = strtoupper(trim((string) ($row['stage'] ?? '')));
                if ($stage === 'DECISION') {
                    $facts['decision_count']++;
                } elseif ($stage === 'MERGE') {
                    $facts['merge_count']++;
                } elseif ($stage === 'CERTIFICATION') {
                    $facts['certification_count']++;
                }
            }
        }

        return $facts;
    }

    /**
     * @return list<string>
     */
    private function signalPaths(Carbon $start, Carbon $now): array
    {
        $paths = [];
        $cursor = $start->copy()->startOfDay();
        $endDay = $now->copy()->startOfDay();
        while ($cursor->lte($endDay)) {
            $paths[] = storage_path('app/atlas-loop/signals/'.$cursor->format('Y-m-d').'.jsonl');
            $cursor->addDay();
        }

        return $paths;
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

    /**
     * @return array{present:bool,pipeline_rows:int,max_stage_age_seconds:int,stage_static:bool}
     */
    private function pipelineFactsForWindow(string $campaignId, int $windowSeconds): array
    {
        if (! DatabaseTableAvailability::has(AtlasLoopDeliveryPipeline::TABLE)) {
            return ['present' => false, 'pipeline_rows' => 0, 'max_stage_age_seconds' => 0, 'stage_static' => true];
        }

        try {
            $rows = DB::table(AtlasLoopDeliveryPipeline::TABLE)
                ->where('campaign_id', $campaignId)
                ->select('updated_at')
                ->get();
        } catch (Throwable) {
            return ['present' => false, 'pipeline_rows' => 0, 'max_stage_age_seconds' => 0, 'stage_static' => true];
        }

        $min = null;
        $max = null;
        foreach ($rows as $row) {
            $time = $this->parseTime((string) ($row->updated_at ?? ''));
            if ($time === null) {
                continue;
            }
            $min = $min === null || $time->lt($min) ? $time : $min;
            $max = $max === null || $time->gt($max) ? $time : $max;
        }

        $span = $min !== null && $max !== null ? $min->diffInSeconds($max) : 0;

        return [
            'present' => true,
            'pipeline_rows' => $rows->count(),
            'max_stage_age_seconds' => $span,
            'stage_static' => $rows->isEmpty() || $span < max(1, intdiv($windowSeconds, 4)),
        ];
    }

    /**
     * @param  array{present:bool,decision_count:int,merge_count:int,certification_count:int}  $signals
     * @param  array{present:bool,pipeline_rows:int,max_stage_age_seconds:int,stage_static:bool}  $pipeline
     */
    private function reasonCode(array $signals, array $pipeline): string
    {
        if (! $signals['present'] || ! $pipeline['present']) {
            return 'indeterminate';
        }
        if ($signals['merge_count'] > 0) {
            return 'healthy';
        }
        if (($signals['decision_count'] + $signals['certification_count']) <= 0) {
            return 'idle';
        }

        return $pipeline['stage_static'] ? 'merging_dry' : 'healthy';
    }
}
