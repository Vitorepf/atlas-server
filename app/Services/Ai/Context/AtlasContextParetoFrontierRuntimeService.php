<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Carbon;

final class AtlasContextParetoFrontierRuntimeService
{
    public const SCHEMA_VERSION = 'atlas.context.pareto_frontier.v1';

    public function __construct(
        private readonly AtlasAucriTokenQualityCanarySetService $canarySet,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function report(): array
    {
        $candidates = $this->candidatesFromCanaries();
        $frontier = $this->paretoFrontier($candidates);
        $selected = $this->selectCandidates($frontier);

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
}
