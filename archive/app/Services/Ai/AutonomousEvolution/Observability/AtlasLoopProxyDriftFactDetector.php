<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Observability;

use Illuminate\Support\Carbon;

/**
 * Deterministic proxy-drift detector for operator memories `loop-not-proxy-cleanup-feedback` and
 * `loop-material-fuel-gap`.
 *
 * The detector is deliberately table-bound: closed work-type maps, fixed threshold, JSONL facts only. It never
 * adapts the tables from its own output and never emits a generic quality scalar; it returns the factual ratio
 * that crossed (or did not cross) the fixed proxy threshold.
 */
final class AtlasLoopProxyDriftFactDetector
{
    public const SCHEMA_VERSION = 'atlas.loop.proxy_drift_fact.v1';

    public const DRIFT_THRESHOLD = 0.75;

    /** @var array<string,true> */
    private const PROXY_WORK_TYPES = [
        'dead_code_removal' => true,
        'unused_import' => true,
        'whitespace' => true,
        'refactor_preserve' => true,
        'deduplication_only' => true,
    ];

    /** @var array<string,true> */
    private const MATERIAL_WORK_TYPES = [
        'origination' => true,
        'capability_leap' => true,
        'feature_add' => true,
        'bugfix' => true,
        'wiring_new' => true,
    ];

    private readonly string $signalsDir;

    public function __construct(?string $signalsDir = null)
    {
        $this->signalsDir = rtrim($signalsDir ?? storage_path('app/atlas-loop/signals'), '/');
    }

    /**
     * @return array{schema_version:string,campaign_id:string,sample_size:int,evaluated_at:string,drifting:bool,drift_ratio:float,fact_evidence:array<string,mixed>}
     */
    public function evaluate(string $campaignId, int $sampleSize = 20): array
    {
        $sampleSize = max(1, $sampleSize);
        $signals = $this->readLastDecisions($campaignId, $sampleSize);

        $histogram = [];
        $proxyCount = 0;
        $materialCount = 0;
        $neutralCount = 0;

        foreach ($signals as $signal) {
            $workType = $this->workType($signal['payload'] ?? []);
            $histogram[$workType] = ($histogram[$workType] ?? 0) + 1;

            $bucket = $this->classifyWorkType($workType);
            if ($bucket === 'proxy') {
                $proxyCount++;
            } elseif ($bucket === 'material') {
                $materialCount++;
            } else {
                $neutralCount++;
            }
        }

        ksort($histogram, SORT_STRING);
        $available = count($signals);
        $insufficient = $available < max(1, (int) ceil($sampleSize / 4));
        $ratio = round($proxyCount / max(1, $available), 3);
        $drifting = ! $insufficient && $ratio >= self::DRIFT_THRESHOLD;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'campaign_id' => $campaignId,
            'sample_size' => $sampleSize,
            'evaluated_at' => Carbon::now('UTC')->toIso8601String(),
            'drifting' => $drifting,
            'drift_ratio' => $ratio,
            'fact_evidence' => [
                'available_sample' => $available,
                'insufficient_sample' => $insufficient,
                'threshold' => self::DRIFT_THRESHOLD,
                'classified' => [
                    'material' => $materialCount,
                    'proxy' => $proxyCount,
                    'neutral' => $neutralCount,
                ],
                'histogram' => $histogram,
            ],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readLastDecisions(string $campaignId, int $sampleSize): array
    {
        $decisions = [];

        foreach ($this->signalPathsNewestFirst() as $path) {
            $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (! is_array($lines)) {
                continue;
            }

            foreach (array_reverse($lines) as $line) {
                $signal = json_decode((string) $line, true);
                if (! is_array($signal)) {
                    continue;
                }
                if ((string) ($signal['campaign_id'] ?? '') !== $campaignId) {
                    continue;
                }
                if (strtolower(trim((string) ($signal['stage'] ?? ''))) !== 'decision') {
                    continue;
                }

                $decisions[] = $signal;
                if (count($decisions) >= $sampleSize) {
                    return $decisions;
                }
            }
        }

        return $decisions;
    }

    /**
     * @return list<string>
     */
    private function signalPathsNewestFirst(): array
    {
        if (! is_dir($this->signalsDir)) {
            return [];
        }

        $paths = glob($this->signalsDir.'/*.jsonl') ?: [];
        rsort($paths, SORT_STRING);

        return array_values($paths);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function workType(array $payload): string
    {
        foreach (['work_type', 'reason_code', 'verdict'] as $key) {
            $value = $payload[$key] ?? null;
            if (! is_scalar($value)) {
                continue;
            }
            $normalised = $this->normalise((string) $value);
            if ($normalised !== '') {
                return $normalised;
            }
        }

        return 'unknown';
    }

    private function classifyWorkType(string $workType): string
    {
        if (isset(self::PROXY_WORK_TYPES[$workType])) {
            return 'proxy';
        }
        if (isset(self::MATERIAL_WORK_TYPES[$workType])) {
            return 'material';
        }

        return 'neutral';
    }

    private function normalise(string $value): string
    {
        $value = strtolower(trim($value));
        $value = (string) preg_replace('/[^a-z0-9]+/', '_', $value);

        return trim($value, '_');
    }
}
