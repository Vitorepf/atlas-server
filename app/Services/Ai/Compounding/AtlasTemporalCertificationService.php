<?php

namespace App\Services\Ai\Compounding;

use App\Models\AiRunOutcome;
use App\Models\AiTemporalCertification;

class AtlasTemporalCertificationService
{
    public const SCHEMA_VERSION = 'atlas.ai.compounding.temporal_certification.v1';

    public function certify(): AiTemporalCertification
    {
        $now = now();
        $currentStart = $now->copy()->subDays(7);
        $previousStart = $now->copy()->subDays(14);
        $current = $this->flowScores($currentStart, $now);
        $previous = $this->flowScores($previousStart, $currentStart);
        $flowDeltas = [];

        foreach (array_unique(array_merge(array_keys($current), array_keys($previous))) as $flowId) {
            $currentScore = $current[$flowId]['average'] ?? null;
            $previousScore = $previous[$flowId]['average'] ?? null;
            $delta = $currentScore !== null && $previousScore !== null ? $currentScore - $previousScore : null;
            $flowDeltas[$flowId] = [
                'current_average' => $currentScore,
                'previous_average' => $previousScore,
                'delta' => $delta,
                'status' => $delta === null ? 'inconclusive' : ($delta > 0 ? 'improved' : ($delta < 0 ? 'regressed' : 'flat')),
            ];
        }

        $blockers = [];
        if (AiRunOutcome::query()->count() === 0) {
            $blockers[] = 'no_outcome_receipts';
        }
        if ($flowDeltas === []) {
            $blockers[] = 'no_temporal_flow_delta';
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $blockers === [] ? 'passed' : 'blocked',
            'window_started_at' => $currentStart,
            'window_ended_at' => $now,
            'flow_deltas' => $flowDeltas,
            'rival_deltas' => [
                'claude_code' => ['status' => 'requires_external_battery'],
                'codex' => ['status' => 'requires_external_battery'],
            ],
            'claim_policy' => [
                'ready_to_claim_100x' => false,
                'ready_to_replace_claude_code_codex' => false,
                'requires_external_rival_battery' => true,
                'allows_flow_local_improvement_claim' => $blockers === [],
            ],
            'blockers' => $blockers,
            'certified_at' => $now,
        ];
        $payload['certification_hash'] = CompoundingHash::make([
            'schema' => self::SCHEMA_VERSION,
            'flow_deltas' => $flowDeltas,
            'blockers' => $blockers,
            'certified_at' => $now->toISOString(),
        ]);

        return AiTemporalCertification::query()->create($payload);
    }

    /**
     * @return array<string,array{average:float,count:int}>
     */
    private function flowScores(mixed $start, mixed $end): array
    {
        return AiRunOutcome::query()
            ->whereBetween('created_at', [$start, $end])
            ->get()
            ->groupBy('flow_id')
            ->map(function ($items): array {
                $scores = $items->map(fn (AiRunOutcome $outcome): float => (
                    $outcome->flow_quality
                    + $outcome->retrieval_quality
                    + $outcome->execution_quality
                    + $outcome->evidence_quality
                ) / 4);

                return [
                    'average' => round((float) $scores->avg(), 2),
                    'count' => $items->count(),
                ];
            })
            ->all();
    }
}
