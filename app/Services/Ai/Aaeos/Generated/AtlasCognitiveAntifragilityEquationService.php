<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

use InvalidArgumentException;

/**
 * Pure, deterministic decider for the Atlas Cognitive Antifragility Equation.
 *
 * This is NOT a measurement collector and it never reads runtime state. It
 * encodes the concrete formulas the doc states over plain typed arrays — no
 * database, no models, no side effects — so the equation contract can be
 * pinned and reused independently of any live telemetry pipeline.
 *
 * The doc formalizes the composed thesis:
 *
 *     Atlas_total(t) = N(provider, t) x M(atlas, t)
 *
 *   - N (provider capacity) is EXOGENOUS: a weighted sum of the current
 *     capacities of the external providers in scope. When a provider ships a
 *     leap, its score rises and N rises — Atlas captures the leap via the
 *     wrapper without changing code.
 *
 *   - M (Atlas wrapper multiplier) is ENDOGENOUS: a weighted sum over eight
 *     documented components whose weights total exactly 1.0:
 *       memory_governed        0.20  retention_quality_score          0..1
 *       evidence_receipts      0.15  replay_success_rate              0..1
 *       compounding_learning   0.15  obras_quality_trend             -1..+1
 *       self_construction      0.10  self_improvements_per_quarter    0..N
 *       multi_agent_topology   0.10  parallel_efficiency_factor       1..8
 *       multi_provider         0.10  provider_diversity_score         0..1
 *       sovereignty            0.10  local_run_rate                   0..1
 *       governance_gates       0.10  gate_pass_rate                   0..1
 *
 *       M = sum(weight_i x metric_i)
 *
 *     Note that `obras_quality_trend` is the ONLY signed metric (a negative
 *     compounding trend can drag M down), and `self_improvements_per_quarter`
 *     and `parallel_efficiency_factor` are NOT in [0,1]; each metric is
 *     clamped to its documented range before weighting.
 *
 *   - "Regras para IA": a new proposal multiplies only if it raises M OR
 *     raises Atlas's capacity to capture N. N is exogenous; operational focus
 *     is on M.
 *
 * Stateless and DB-free: every method is a pure function of its arguments.
 *
 * @see docs/engineering-knowledge-base/atlas-cognitive-antifragility-equation.md
 */
final class AtlasCognitiveAntifragilityEquationService
{
    public const SCHEMA_VERSION = 'atlas.antifragility.measurement.v1';

    public const COMPONENT_MEMORY = 'memory_governed';
    public const COMPONENT_EVIDENCE = 'evidence_receipts';
    public const COMPONENT_COMPOUNDING = 'compounding_learning';
    public const COMPONENT_SELF_CONSTRUCTION = 'self_construction';
    public const COMPONENT_MULTI_AGENT = 'multi_agent_topology';
    public const COMPONENT_MULTI_PROVIDER = 'multi_provider';
    public const COMPONENT_SOVEREIGNTY = 'sovereignty';
    public const COMPONENT_GOVERNANCE = 'governance_gates';

    /**
     * The eight M components, each with the doc's exact weight, metric name and
     * inclusive [min,max] documented range. Order is the doc's table order.
     *
     * @var array<string,array{weight:float,metric:string,min:float,max:float}>
     */
    public const M_COMPONENTS = [
        self::COMPONENT_MEMORY => ['weight' => 0.20, 'metric' => 'retention_quality_score', 'min' => 0.0, 'max' => 1.0],
        self::COMPONENT_EVIDENCE => ['weight' => 0.15, 'metric' => 'replay_success_rate', 'min' => 0.0, 'max' => 1.0],
        self::COMPONENT_COMPOUNDING => ['weight' => 0.15, 'metric' => 'obras_quality_trend', 'min' => -1.0, 'max' => 1.0],
        self::COMPONENT_SELF_CONSTRUCTION => ['weight' => 0.10, 'metric' => 'self_improvements_per_quarter', 'min' => 0.0, 'max' => INF],
        self::COMPONENT_MULTI_AGENT => ['weight' => 0.10, 'metric' => 'parallel_efficiency_factor', 'min' => 1.0, 'max' => 8.0],
        self::COMPONENT_MULTI_PROVIDER => ['weight' => 0.10, 'metric' => 'provider_diversity_score', 'min' => 0.0, 'max' => 1.0],
        self::COMPONENT_SOVEREIGNTY => ['weight' => 0.10, 'metric' => 'local_run_rate', 'min' => 0.0, 'max' => 1.0],
        self::COMPONENT_GOVERNANCE => ['weight' => 0.10, 'metric' => 'gate_pass_rate', 'min' => 0.0, 'max' => 1.0],
    ];

    /**
     * Documented snapshot defaults (2026-Q2): N=8.5, M=1.4, Total=11.9.
     * Used by the command as safe demonstration inputs.
     */
    public const SNAPSHOT_N = 8.5;
    public const SNAPSHOT_M = 1.4;
    public const SNAPSHOT_TREND_30D = 0.05;

    /**
     * Sum of all component weights, asserted to equal 1.0 by the doc's table.
     */
    public function totalWeight(): float
    {
        $sum = 0.0;
        foreach (self::M_COMPONENTS as $component) {
            $sum += $component['weight'];
        }

        return round($sum, 6);
    }

    /**
     * Compute M = sum(weight_i x clamped_metric_i) over the eight components.
     *
     * Each supplied metric is clamped to the component's documented range
     * BEFORE weighting (so an out-of-range input cannot inflate or deflate M
     * beyond the contract). A missing metric defaults to the component range
     * minimum, except signed metrics default to neutral 0.0. The signed
     * `obras_quality_trend` can be negative, which is the
     * only way a component can subtract from M.
     *
     * @param  array<string,int|float>  $metrics  keyed by component id
     * @return array{
     *   schema_version:string,
     *   value:float,
     *   components:list<array{id:string,weight:float,metric:string,raw:float,clamped:float,clamped_flag:bool,contribution:float}>
     * }
     */
    public function computeM(array $metrics): array
    {
        $components = [];
        $value = 0.0;

        foreach (self::M_COMPONENTS as $id => $spec) {
            $hasInput = array_key_exists($id, $metrics);
            $raw = $hasInput ? (float) $metrics[$id] : max(0.0, $spec['min']);
            $clamped = max($spec['min'], min($spec['max'], $raw));
            $contribution = $spec['weight'] * $clamped;
            $value += $contribution;

            $components[] = [
                'id' => $id,
                'weight' => $spec['weight'],
                'metric' => $spec['metric'],
                'raw' => round($raw, 6),
                'clamped' => round($clamped, 6),
                'clamped_flag' => $clamped !== $raw,
                'contribution' => round($contribution, 6),
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'value' => round($value, 6),
            'components' => $components,
        ];
    }

    /**
     * Compute N = weighted sum of provider capacities.
     *
     * When no weights are supplied, the providers are weighted equally so N is
     * the simple mean of provider scores (a single provider at score S yields
     * N=S, matching "claude_code: N=8.5"). Weights are normalized to sum to 1.0
     * so N stays on the same scale as individual provider scores.
     *
     * @param  list<array{id:string,score:int|float,weight?:int|float}>  $providers
     * @return array{
     *   schema_version:string,
     *   value:float,
     *   providers:list<array{id:string,score:float,weight:float}>
     * }
     */
    public function computeN(array $providers): array
    {
        if ($providers === []) {
            throw new InvalidArgumentException('At least one provider is required to compute N.');
        }

        $rawWeights = [];
        $weightSum = 0.0;
        foreach ($providers as $i => $provider) {
            $w = array_key_exists('weight', $provider) ? (float) $provider['weight'] : 1.0;
            if ($w < 0.0) {
                throw new InvalidArgumentException('Provider weight must not be negative.');
            }
            $rawWeights[$i] = $w;
            $weightSum += $w;
        }

        if ($weightSum <= 0.0) {
            throw new InvalidArgumentException('Provider weights must sum to a positive value.');
        }

        $value = 0.0;
        $normalized = [];
        foreach ($providers as $i => $provider) {
            $weight = $rawWeights[$i] / $weightSum;
            $score = (float) $provider['score'];
            $value += $weight * $score;
            $normalized[] = [
                'id' => (string) $provider['id'],
                'score' => round($score, 6),
                'weight' => round($weight, 6),
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'value' => round($value, 6),
            'providers' => $normalized,
        ];
    }

    /**
     * Compose the full measurement: Total = N x M.
     *
     * Emits the `atlas.antifragility.measurement.v1` schema with N, M, the
     * product, and a 30-day trend passthrough. This is the equation's primary
     * method.
     *
     * @param  list<array{id:string,score:int|float,weight?:int|float}>  $providers
     * @param  array<string,int|float>  $metrics
     * @return array{
     *   schema:string,
     *   measured_at:string,
     *   n:array{value:float,providers:list<array{id:string,score:float,weight:float}>},
     *   m:array{value:float,components:list<array<string,mixed>>},
     *   total:float,
     *   trend_30d:float
     * }
     */
    public function measure(array $providers, array $metrics, string $measuredAt, float $trend30d = 0.0): array
    {
        $n = $this->computeN($providers);
        $m = $this->computeM($metrics);
        $total = round($n['value'] * $m['value'], 6);

        return [
            'schema' => self::SCHEMA_VERSION,
            'measured_at' => $measuredAt,
            'n' => [
                'value' => $n['value'],
                'providers' => $n['providers'],
            ],
            'm' => [
                'value' => $m['value'],
                'components' => $m['components'],
            ],
            'total' => $total,
            'trend_30d' => round($trend30d, 6),
        ];
    }

    /**
     * The antifragility window: when a provider leaps by `leapFactor`, N scales
     * by that factor while M is captured without code change (M is unchanged by
     * the provider leap). Total = (N x leap) x M.
     *
     * This proves the doc's window: a provider leap multiplies Total purely
     * through N; M keeps growing on its own, independently of the leap.
     *
     * @return array{
     *   schema_version:string,
     *   leap_factor:float,
     *   n_before:float,
     *   n_after:float,
     *   m:float,
     *   total_before:float,
     *   total_after:float,
     *   captured_without_code_change:bool
     * }
     */
    public function applyProviderLeap(float $nBefore, float $m, float $leapFactor): array
    {
        if ($leapFactor <= 0.0) {
            throw new InvalidArgumentException('Leap factor must be positive.');
        }

        $nAfter = round($nBefore * $leapFactor, 6);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'leap_factor' => round($leapFactor, 6),
            'n_before' => round($nBefore, 6),
            'n_after' => $nAfter,
            'm' => round($m, 6),
            'total_before' => round($nBefore * $m, 6),
            'total_after' => round($nAfter * $m, 6),
            // Doc: Atlas captures the leap via Multi-Provider Profile + Atlas
            // Decide WITHOUT changing code; the leap flows entirely through N.
            'captured_without_code_change' => true,
        ];
    }

    /**
     * "Regras para IA": a proposal multiplies the composed wrapper only if it
     * raises M OR raises Atlas's capacity to capture N. A proposal that does
     * neither does not multiply and is rejected by the filter — even if it is a
     * local point optimization.
     *
     * @return array{
     *   schema_version:string,
     *   raises_m:bool,
     *   raises_n_capture:bool,
     *   multiplies:bool,
     *   reason:string
     * }
     */
    public function evaluateProposal(bool $raisesM, bool $raisesNCapture): array
    {
        $multiplies = $raisesM || $raisesNCapture;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'raises_m' => $raisesM,
            'raises_n_capture' => $raisesNCapture,
            'multiplies' => $multiplies,
            'reason' => $multiplies
                ? 'Proposal raises M and/or capacity to capture N: it multiplies the composed wrapper.'
                : 'Proposal raises neither M nor N-capture: it is a point optimization, not a multiplier.',
        ];
    }
}
