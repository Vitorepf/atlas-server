<?php

declare(strict_types=1);

namespace App\Services\Ai\AtlasDecide;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class AtlasDecideRouteRegretService
{
    public const SCHEMA_VERSION = 'atlas.decide.route_regret_report.v2';

    public const MEASURE_ID = 'atlas.decide.route_regret.v2';

    public const FORMULA_VERSION = 'route_regret_v2.gross_scope';

    public const MIN_EVIDENCE = 3;

    public const MIN_CALLS = AtlasDecideLiveOutcomeFeedbackService::MIN_CALLS_FOR_SIGNAL;

    public const TTL_DAYS = 30;

    public function __construct(private readonly AtlasDecideLiveOutcomeFeedbackService $liveOutcomes) {}

    /** @return array<string,mixed> */
    public static function freezePayload(): array
    {
        return [
            'kind' => 'measure_freeze',
            'measure_id' => self::MEASURE_ID,
            'formula_version' => self::FORMULA_VERSION,
            'formula' => 'gross_scope(role x task_category) route regret proxy = delta(counterfactual.proven_success_rate - chosen.proven_success_rate) - normalized_cost_benefit(chosen_vs_counterfactual); framework intentionally dropped until ASI-06.',
            'thresholds' => [
                'min_evidence_pairs' => self::MIN_EVIDENCE,
                'min_calls_per_route' => self::MIN_CALLS,
                'framework_dimension' => 'dropped_until_ASI_06',
                'exploration_counterfactual' => 'would_have_been_greedy_provider',
            ],
            'denominator_min' => self::MIN_CALLS,
            'ttl_days' => self::TTL_DAYS,
            'author_engine_id' => 'cursor-acos-max-maxk-01',
            'judge_engine_id' => 'codex-independent-regret-judge',
            'reader_command' => 'atlas:atlas-decide:live-feedback --regret --json',
            'record_usage' => false,
            'dual_read_required' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function report(): array
    {
        $outcomes = $this->liveOutcomes->listOutcomes();
        $generatedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
        $scopes = $this->scopeReports($outcomes);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'measure_id' => self::MEASURE_ID,
            'formula_version' => self::FORMULA_VERSION,
            'generated_at' => $generatedAt,
            'record_usage' => false,
            'mutates_v1_series' => false,
            'aggregation' => [
                'scope' => 'task_category x role',
                'framework' => 'dropped_until_ASI_06',
            ],
            'thresholds' => [
                'min_evidence' => self::MIN_EVIDENCE,
                'min_calls' => self::MIN_CALLS,
            ],
            'status' => $scopes === [] ? 'insufficient_signal' : 'ok',
            'scopes' => $scopes,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $outcomes
     * @return list<array<string,mixed>>
     */
    private function scopeReports(array $outcomes): array
    {
        $routed = [];
        foreach ($outcomes as $entry) {
            $counterfactual = $this->counterfactualFor($entry);
            if ($counterfactual === null) {
                continue;
            }

            $task = $this->label($entry['task_category'] ?? null);
            $role = $this->label($entry['role'] ?? null);
            $chosen = $this->label($entry['provider'] ?? null);
            if ($task === '' || $role === '' || $chosen === '') {
                continue;
            }

            $key = $task.'|'.$role.'|'.$chosen.'|'.$counterfactual['provider'];
            $routed[$key] ??= [
                'task_category' => $task,
                'role' => $role,
                'chosen_provider' => $chosen,
                'chosen_model' => $this->optionalLabel($entry['model'] ?? null),
                'counterfactual_provider' => $counterfactual['provider'],
                'counterfactual_model' => $counterfactual['model'],
                'counterfactual_kind' => $counterfactual['kind'],
                'entries' => [],
            ];
            $routed[$key]['entries'][] = $entry;
        }

        $reports = [];
        foreach ($routed as $group) {
            $chosenStats = $this->providerStats($outcomes, (string) $group['task_category'], (string) $group['role'], (string) $group['chosen_provider']);
            $counterfactualStats = $this->providerStats($outcomes, (string) $group['task_category'], (string) $group['role'], (string) $group['counterfactual_provider']);
            $pairN = count((array) $group['entries']);

            $base = [
                'scope' => [
                    'task_category' => $group['task_category'],
                    'role' => $group['role'],
                ],
                'counterfactual_kind' => $group['counterfactual_kind'],
                'chosen' => [
                    'provider' => $group['chosen_provider'],
                    'model' => $group['chosen_model'],
                    'proven_success_rate' => $chosenStats['proven_success_rate'],
                    'avg_cost_usd' => $chosenStats['avg_cost_usd'],
                    'avg_latency_ms' => $chosenStats['avg_latency_ms'],
                ],
                'counterfactual' => [
                    'provider' => $group['counterfactual_provider'],
                    'model' => $group['counterfactual_model'],
                    'proven_success_rate' => $counterfactualStats['proven_success_rate'],
                    'avg_cost_usd' => $counterfactualStats['avg_cost_usd'],
                    'avg_latency_ms' => $counterfactualStats['avg_latency_ms'],
                ],
                'denominators' => [
                    'pair_n' => $pairN,
                    'chosen_n' => $chosenStats['n'],
                    'counterfactual_n' => $counterfactualStats['n'],
                ],
                'thresholds' => [
                    'min_evidence' => self::MIN_EVIDENCE,
                    'min_calls' => self::MIN_CALLS,
                ],
            ];

            $insufficient = [];
            if ($pairN < self::MIN_EVIDENCE) {
                $insufficient[] = 'pair_n_below_min_evidence';
            }
            if ((int) $chosenStats['n'] < self::MIN_CALLS) {
                $insufficient[] = 'chosen_n_below_min_calls';
            }
            if ((int) $counterfactualStats['n'] < self::MIN_CALLS) {
                $insufficient[] = 'counterfactual_n_below_min_calls';
            }

            if ($insufficient !== []) {
                $reports[] = $base + [
                    'status' => 'insufficient_n',
                    'insufficient_reasons' => $insufficient,
                ];
                continue;
            }

            $deltaProvenSuccess = round((float) $counterfactualStats['proven_success_rate'] - (float) $chosenStats['proven_success_rate'], 4);
            $costBenefit = $this->normalizedCostBenefit($chosenStats['avg_cost_usd'], $counterfactualStats['avg_cost_usd']);
            $reports[] = $base + [
                'status' => 'ok',
                'delta_proven_success_rate' => $deltaProvenSuccess,
                'normalized_cost_benefit' => $costBenefit,
                'regret_proxy' => round($deltaProvenSuccess - $costBenefit, 4),
            ];
        }

        usort($reports, static fn (array $a, array $b): int => strcmp(
            (string) data_get($a, 'scope.task_category').(string) data_get($a, 'scope.role').(string) data_get($a, 'chosen.provider'),
            (string) data_get($b, 'scope.task_category').(string) data_get($b, 'scope.role').(string) data_get($b, 'chosen.provider'),
        ));

        return $reports;
    }

    /**
     * @param  array<string,mixed>  $entry
     * @return array{provider:string,model:?string,kind:string}|null
     */
    private function counterfactualFor(array $entry): ?array
    {
        $routingBasis = $this->label($entry['routing_basis'] ?? null);
        $greedyProvider = $this->optionalLabel($entry['would_have_been_greedy_provider'] ?? null);
        if ($routingBasis === 'exploration' && $greedyProvider !== null) {
            return [
                'provider' => $greedyProvider,
                'model' => $this->optionalLabel($entry['would_have_been_greedy_model'] ?? null),
                'kind' => 'would_have_been_greedy',
            ];
        }

        $fallbackProvider = $this->optionalLabel($entry['fallback_provider'] ?? null);
        if ($fallbackProvider !== null) {
            return [
                'provider' => $fallbackProvider,
                'model' => $this->optionalLabel($entry['fallback_model'] ?? null),
                'kind' => 'fallback',
            ];
        }

        return null;
    }

    /**
     * @param  list<array<string,mixed>>  $outcomes
     * @return array{n:int,proven_success_rate:?float,avg_cost_usd:?float,avg_latency_ms:?int}
     */
    private function providerStats(array $outcomes, string $taskCategory, string $role, string $provider): array
    {
        $n = 0;
        $provenSuccess = 0;
        $costSum = 0.0;
        $costN = 0;
        $latencySum = 0;
        $latencyN = 0;

        foreach ($outcomes as $entry) {
            if ($this->label($entry['task_category'] ?? null) !== $taskCategory
                || $this->label($entry['role'] ?? null) !== $role
                || $this->label($entry['provider'] ?? null) !== $provider) {
                continue;
            }

            $n++;
            if (($entry['result'] ?? null) === AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS
                && ($entry['proven_real'] ?? false) === true) {
                $provenSuccess++;
            }
            if (isset($entry['cost_usd']) && is_numeric($entry['cost_usd']) && (float) $entry['cost_usd'] >= 0.0) {
                $costSum += (float) $entry['cost_usd'];
                $costN++;
            }
            if (isset($entry['latency_ms']) && is_numeric($entry['latency_ms']) && (int) $entry['latency_ms'] >= 0) {
                $latencySum += (int) $entry['latency_ms'];
                $latencyN++;
            }
        }

        return [
            'n' => $n,
            'proven_success_rate' => $n > 0 ? round($provenSuccess / $n, 4) : null,
            'avg_cost_usd' => $costN > 0 ? round($costSum / $costN, 6) : null,
            'avg_latency_ms' => $latencyN > 0 ? (int) round($latencySum / $latencyN) : null,
        ];
    }

    private function normalizedCostBenefit(?float $chosenCost, ?float $counterfactualCost): float
    {
        if ($chosenCost === null || $counterfactualCost === null || $counterfactualCost <= 0.0) {
            return 0.0;
        }

        return round(max(-1.0, min(1.0, ($counterfactualCost - $chosenCost) / $counterfactualCost)), 4);
    }

    private function optionalLabel(mixed $value): ?string
    {
        $label = $this->label($value);

        return $label !== '' ? $label : null;
    }

    private function label(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        return strtolower(trim((string) $value));
    }
}
