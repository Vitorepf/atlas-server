<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class AtlasContextParetoFrontierRuntimeService
{
    public const SCHEMA_VERSION = 'atlas.context.pareto_frontier.v1';

    public function __construct(
        private readonly AtlasAucriTokenQualityCanarySetService $canarySet,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function report(int $hours = 24): array
    {
        $candidates = $this->candidatesFromCanaries();
        $frontier = $this->paretoFrontier($candidates);
        $selected = $this->selectCandidates($frontier);
        $realTraceShadow = $this->realTraceShadow($hours);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ready',
            'generated_at' => Carbon::now()->toIso8601String(),
            'summary' => [
                'total_candidates' => count($candidates),
                'frontier_candidates' => count($frontier),
                'selected_candidates' => count($selected),
                'blocked_candidates' => count(array_filter($candidates, static fn (array $candidate): bool => $candidate['promotion_status'] === 'blocked')),
            ],
            'utility_function' => [
                'schema_version' => 'atlas.context.utility_function.v1',
                'version' => 'aucri-pareto-shadow-v1',
                'hard_constraints' => [
                    'must_keep_coverage >= 1.0',
                    'privacy_status = pass',
                    'sufficiency_status = pass',
                ],
                'objective_order' => ['quality_score', 'input_tokens', 'cost_units', 'latency_ms'],
            ],
            'candidates' => $candidates,
            'frontier' => array_values($frontier),
            'selected' => array_values($selected),
            'real_trace_shadow' => $realTraceShadow,
            'claims' => [
                'shadow_only' => true,
                'providers_invoked' => false,
                'rivals_run' => false,
                'benchmark_run' => false,
                'promotes_runtime_change' => false,
            ],
            'writes' => false,
        ];

        $hashPayload = $payload;
        unset($hashPayload['generated_at']);
        $payload['frontier_hash'] = MissionCanonicalHash::sha256($hashPayload);

        return $payload;
    }

    /**
     * @param  array<int,array<string,mixed>>  $candidates
     * @return array<int,array<string,mixed>>
     */
    public function paretoFrontier(array $candidates): array
    {
        return array_values(array_filter(
            $this->markDominated($candidates),
            static fn (array $candidate): bool => $candidate['pareto_dominated'] === false
                && $candidate['promotion_status'] !== 'blocked',
        ));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function candidatesFromCanaries(): array
    {
        $cases = $this->canarySet->report()['cases'];
        $candidates = [];

        foreach ($cases as $index => $case) {
            $baseTokens = 12000 + ($index * 900);
            $risk = (string) $case['risk_level'];

            $candidates[] = $this->candidate($case, 'baseline_safe', 0.94, $baseTokens, 1.0, 900, 1.0);
            $candidates[] = $this->candidate($case, 'compiled_delta', $risk === 'high' ? 0.94 : 0.93, (int) round($baseTokens * 0.58), 0.72, 760, 1.0);
            $candidates[] = $this->candidate($case, 'aggressive_compression', 0.88, (int) round($baseTokens * 0.32), 0.48, 620, $risk === 'high' ? 0.92 : 1.0);
        }

        return $this->markDominated($candidates);
    }

    /**
     * @return array<string,mixed>
     */
    private function realTraceShadow(int $hours): array
    {
        if (! DatabaseTableAvailability::has('ai_trace_metric_summaries')) {
            return [
                'status' => 'unavailable',
                'reason' => 'missing_table:ai_trace_metric_summaries',
                'window_hours' => max(1, $hours),
                'summary' => [
                    'source_traces' => 0,
                    'total_candidates' => 0,
                    'frontier_candidates' => 0,
                    'selected_candidates' => 0,
                    'blocked_candidates' => 0,
                    'estimated_token_savings' => 0,
                ],
                'candidates' => [],
                'frontier' => [],
                'selected' => [],
            ];
        }

        $windowHours = max(1, min(168, $hours));
        $since = Carbon::now()->subHours($windowHours);
        $rows = DB::table('ai_trace_metric_summaries')
            ->where('computed_at', '>=', $since)
            ->orderByDesc('computed_at')
            ->limit(50)
            ->get();

        if ($rows->isEmpty()) {
            return [
                'status' => 'empty',
                'reason' => 'no_trace_metric_summaries_in_window',
                'window_hours' => $windowHours,
                'summary' => [
                    'source_traces' => 0,
                    'total_candidates' => 0,
                    'frontier_candidates' => 0,
                    'selected_candidates' => 0,
                    'blocked_candidates' => 0,
                    'estimated_token_savings' => 0,
                ],
                'candidates' => [],
                'frontier' => [],
                'selected' => [],
            ];
        }

        $candidates = [];
        foreach ($rows as $row) {
            $candidates[] = $this->candidateFromMetricSummary($row, 'observed_current');
            $candidates[] = $this->candidateFromMetricSummary($row, 'compiled_context_shadow');
        }

        $candidates = $this->markDominated($candidates);
        $frontier = $this->paretoFrontier($candidates);
        $selected = $this->selectCandidates($frontier);
        $estimatedSavings = array_sum(array_map(
            static fn (array $candidate): int => (int) ($candidate['estimated_token_savings'] ?? 0),
            $selected,
        ));

        return [
            'status' => 'ready',
            'reason' => null,
            'window_hours' => $windowHours,
            'summary' => [
                'source_traces' => $rows->count(),
                'total_candidates' => count($candidates),
                'frontier_candidates' => count($frontier),
                'selected_candidates' => count($selected),
                'blocked_candidates' => count(array_filter($candidates, static fn (array $candidate): bool => $candidate['promotion_status'] === 'blocked')),
                'estimated_token_savings' => $estimatedSavings,
            ],
            'candidates' => $candidates,
            'frontier' => array_values($frontier),
            'selected' => array_values($selected),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function candidateFromMetricSummary(object $row, string $strategy): array
    {
        $traceId = (string) ($row->trace_id ?? 'unknown');
        $caseId = 'trace:'.substr($traceId, 0, 12);
        $baseInputTokens = $this->positiveInt($row->prompt_tokens ?? null)
            ?? $this->positiveInt($row->context_tokens ?? null)
            ?? $this->positiveInt($row->estimated_tokens ?? null)
            ?? $this->positiveInt($row->total_tokens ?? null)
            ?? 0;
        $contextTokens = $this->positiveInt($row->context_tokens ?? null) ?? 0;
        $completionTokens = $this->positiveInt($row->completion_tokens ?? null) ?? 0;
        $quality = $this->scoreToFloat($row->final_quality_score ?? null)
            ?? $this->scoreToFloat($row->auto_quality_score ?? null)
            ?? 0.70;
        $latency = $this->positiveInt($row->total_latency_ms ?? null)
            ?? $this->positiveInt($row->provider_latency_ms ?? null)
            ?? 0;
        $cost = max(0.01, ((float) ($row->cost_microusd ?? 0)) / 1000.0);

        $firstPass = (bool) ($row->first_pass_success ?? false);
        $neededRemediation = (bool) ($row->needed_remediation ?? false);
        $safeObserved = $quality >= 0.80 && ($firstPass || ! $neededRemediation);

        if ($strategy === 'compiled_context_shadow') {
            $reducibleContext = $contextTokens > 0 ? (int) round($contextTokens * 0.45) : (int) round($baseInputTokens * 0.30);
            $inputTokens = max(1, $baseInputTokens - $reducibleContext);
            $quality = max(0.0, $quality - ($safeObserved ? 0.00 : 0.04));
            $latency = $latency > 0 ? (int) round($latency * 0.90) : 0;
            $cost = round($cost * 0.82, 4);
            $mustKeep = $safeObserved ? 1.0 : 0.95;
            $blockers = $mustKeep < 1.0 ? ['real_trace_quality_or_remediation_requires_review'] : [];
        } else {
            $inputTokens = max(1, $baseInputTokens);
            $mustKeep = $safeObserved ? 1.0 : 0.98;
            $blockers = $mustKeep < 1.0 ? ['observed_trace_missing_quality_confidence'] : [];
        }

        $blocked = $mustKeep < 1.0;

        return [
            'candidate_id' => $caseId.'::'.$strategy,
            'case_id' => $caseId,
            'domain' => (string) ($row->runtime ?: $row->task_type ?: $row->surface ?: 'unknown'),
            'strategy' => $strategy,
            'risk_level' => $neededRemediation ? 'high' : 'medium',
            'quality_score' => round($quality, 4),
            'input_tokens' => $inputTokens,
            'output_tokens' => $completionTokens,
            'cost_units' => $cost,
            'latency_ms' => $latency,
            'must_keep_coverage' => $mustKeep,
            'privacy_status' => 'pass',
            'sufficiency_status' => $blocked ? 'blocked' : 'pass',
            'pareto_dominated' => false,
            'promotion_status' => $blocked ? 'blocked' : 'shadow_candidate',
            'estimated_token_savings' => max(0, $baseInputTokens - $inputTokens),
            'trace_ref' => [
                'trace_id' => $traceId,
                'metric_summary_id' => (string) ($row->id ?? ''),
                'status' => (string) ($row->status ?? 'unknown'),
                'computed_at' => (string) ($row->computed_at ?? ''),
            ],
            'blockers' => $blockers,
        ];
    }

    /**
     * @param  array<string,mixed>  $case
     * @return array<string,mixed>
     */
    private function candidate(array $case, string $strategy, float $quality, int $tokens, float $cost, int $latency, float $mustKeep): array
    {
        $blocked = $mustKeep < 1.0;

        return [
            'candidate_id' => $case['case_id'].'::'.$strategy,
            'case_id' => $case['case_id'],
            'domain' => $case['domain'],
            'strategy' => $strategy,
            'risk_level' => $case['risk_level'],
            'quality_score' => $quality,
            'input_tokens' => $tokens,
            'output_tokens' => $strategy === 'baseline_safe' ? 1800 : 1200,
            'cost_units' => $cost,
            'latency_ms' => $latency,
            'must_keep_coverage' => $mustKeep,
            'privacy_status' => 'pass',
            'sufficiency_status' => $blocked ? 'blocked' : 'pass',
            'pareto_dominated' => false,
            'promotion_status' => $blocked ? 'blocked' : 'shadow_candidate',
            'blockers' => $blocked ? ['must_keep_coverage_below_1_0'] : [],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $candidates
     * @return array<int,array<string,mixed>>
     */
    private function markDominated(array $candidates): array
    {
        return array_map(function (array $candidate) use ($candidates): array {
            $candidate['pareto_dominated'] = $candidate['promotion_status'] !== 'blocked'
                && $this->isDominated($candidate, $candidates);

            if ($candidate['pareto_dominated']) {
                $candidate['promotion_status'] = 'dominated';
            }

            return $candidate;
        }, $candidates);
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @param  array<int,array<string,mixed>>  $candidates
     */
    private function isDominated(array $candidate, array $candidates): bool
    {
        foreach ($candidates as $other) {
            if ($other['case_id'] !== $candidate['case_id']
                || $other['candidate_id'] === $candidate['candidate_id']
                || $other['promotion_status'] === 'blocked') {
                continue;
            }

            $atLeastAsGood = $other['quality_score'] >= $candidate['quality_score']
                && $other['input_tokens'] <= $candidate['input_tokens']
                && $other['cost_units'] <= $candidate['cost_units']
                && $other['latency_ms'] <= $candidate['latency_ms']
                && $other['must_keep_coverage'] >= $candidate['must_keep_coverage'];

            $strictlyBetter = $other['quality_score'] > $candidate['quality_score']
                || $other['input_tokens'] < $candidate['input_tokens']
                || $other['cost_units'] < $candidate['cost_units']
                || $other['latency_ms'] < $candidate['latency_ms'];

            if ($atLeastAsGood && $strictlyBetter) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int,array<string,mixed>>  $frontier
     * @return array<int,array<string,mixed>>
     */
    private function selectCandidates(array $frontier): array
    {
        $byCase = [];

        foreach ($frontier as $candidate) {
            $current = $byCase[$candidate['case_id']] ?? null;
            if ($current === null || $this->utility($candidate) > $this->utility($current)) {
                $candidate['promotion_status'] = 'selected_shadow';
                $byCase[$candidate['case_id']] = $candidate;
            }
        }

        return array_values($byCase);
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    private function utility(array $candidate): float
    {
        return (float) $candidate['quality_score'] * 100
            - ((int) $candidate['input_tokens'] / 1000)
            - ((float) $candidate['cost_units'] * 5)
            - ((int) $candidate['latency_ms'] / 1000);
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private function scoreToFloat(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $score = (float) $value;

        return $score > 1.0 ? min(1.0, $score / 100.0) : max(0.0, min(1.0, $score));
    }
}
