<?php

namespace App\Services\Engineering;

use App\Models\AtlasEngineeringBenchmarkRun;
use App\Models\AtlasTask;
use App\Services\Ai\Policy\AtlasAiRuntimeSettings;
use App\Services\Ai\Cli\AtlasCliModelCatalogService;
use Illuminate\Support\Collection;

class EngineeringModelPolicyService
{
    private const POLICIES = ['fixed', 'off', 'auto', 'balanced', 'best_quality', 'fastest', 'cheapest'];

    public function __construct(
        private readonly AtlasCliModelCatalogService $catalog,
        private readonly AtlasAiRuntimeSettings $settings,
    ) {}

    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function select(AtlasTask $task, array $context, array $options): array
    {
        $requestedProvider = $this->cleanString($options['provider'] ?? null);
        $requestedModel = $this->cleanString($options['model'] ?? null);
        $requestedPolicy = $this->normalizePolicy($options['model_policy'] ?? null);

        if ($requestedModel !== null) {
            $selection = $this->catalog->select($requestedModel, $requestedProvider);
            if ($selection === null) {
                return [
                    'status' => 'skipped',
                    'source' => 'operator_default_model',
                    'requested_policy' => $requestedPolicy,
                    'effective_policy' => 'fixed',
                    'requested_provider' => $requestedProvider,
                    'requested_model' => $requestedModel,
                    'selected_provider' => $requestedProvider,
                    'selected_model' => null,
                    'selected_alias' => null,
                    'selected_label' => null,
                    'selected_tier' => null,
                    'reason' => 'Modelo explicitamente definido como default/auto; mantendo escolha padrao do provider.',
                    'confidence' => 0.0,
                    'candidates' => [],
                    'risk_profile' => $this->riskProfile($task, $context),
                ];
            }
            $selectedProvider = $requestedProvider ?: $this->cleanString($selection['provider'] ?? null);

            return [
                'status' => 'selected',
                'source' => 'operator_model_override',
                'requested_policy' => $requestedPolicy,
                'effective_policy' => 'fixed',
                'requested_provider' => $requestedProvider,
                'requested_model' => $requestedModel,
                'selected_provider' => $selectedProvider,
                'selected_model' => $this->cleanString($selection['model'] ?? null) ?: $requestedModel,
                'selected_alias' => $this->cleanString($selection['alias'] ?? null) ?: $requestedModel,
                'selected_label' => $this->cleanString($selection['label'] ?? null) ?: $requestedModel,
                'selected_tier' => $this->cleanString($selection['tier'] ?? null) ?: 'manual',
                'reason' => 'Modelo definido explicitamente pelo operador.',
                'confidence' => 1.0,
                'candidates' => [],
                'risk_profile' => $this->riskProfile($task, $context),
            ];
        }

        if (in_array($requestedPolicy, ['fixed', 'off'], true)) {
            return [
                'status' => 'skipped',
                'source' => 'fixed_default',
                'requested_policy' => $requestedPolicy,
                'effective_policy' => $requestedPolicy,
                'requested_provider' => $requestedProvider,
                'requested_model' => null,
                'selected_provider' => $requestedProvider,
                'selected_model' => null,
                'selected_alias' => null,
                'selected_label' => null,
                'selected_tier' => null,
                'reason' => 'Politica fixa: sem override de modelo e sem selecao automatica.',
                'confidence' => 0.0,
                'candidates' => [],
                'risk_profile' => $this->riskProfile($task, $context),
            ];
        }

        $riskProfile = $this->riskProfile($task, $context);
        $candidates = $this->rankCandidates($requestedPolicy, $requestedProvider, $riskProfile);
        $best = $candidates->first();

        if (! is_array($best)) {
            return [
                'status' => 'skipped',
                'source' => 'catalog_empty',
                'requested_policy' => $requestedPolicy,
                'effective_policy' => $requestedPolicy,
                'requested_provider' => $requestedProvider,
                'requested_model' => null,
                'selected_provider' => $requestedProvider,
                'selected_model' => null,
                'selected_alias' => null,
                'selected_label' => null,
                'selected_tier' => null,
                'reason' => 'Nenhum modelo elegivel no catalogo para a politica solicitada.',
                'confidence' => 0.0,
                'candidates' => [],
                'risk_profile' => $riskProfile,
            ];
        }

        return [
            'status' => 'selected',
            'source' => ((int) ($best['sample_count'] ?? 0)) > 0 ? 'benchmark_history' : 'catalog_prior',
            'requested_policy' => $requestedPolicy,
            'effective_policy' => $requestedPolicy === 'auto' ? 'balanced' : $requestedPolicy,
            'requested_provider' => $requestedProvider,
            'requested_model' => null,
            'selected_provider' => $best['provider'],
            'selected_model' => $best['model'],
            'selected_alias' => $best['alias'],
            'selected_label' => $best['label'],
            'selected_tier' => $best['tier'],
            'reason' => $this->selectionReason($requestedPolicy, $best, $riskProfile),
            'confidence' => $this->confidence($best, $candidates->count()),
            'candidates' => $candidates->take(8)->values()->all(),
            'risk_profile' => $riskProfile,
        ];
    }

    private function normalizePolicy(mixed $value): string
    {
        $policy = strtolower(str_replace('-', '_', trim((string) ($value ?: 'fixed'))));

        return in_array($policy, self::POLICIES, true) ? $policy : 'fixed';
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function rankCandidates(string $policy, ?string $requestedProvider, string $riskProfile): Collection
    {
        $rows = collect($this->catalog->catalog())
            ->filter(fn (array $row): bool => $requestedProvider === null || $row['provider'] === $requestedProvider)
            ->filter(fn (array $row): bool => $this->providerAllowsAuto((string) $row['provider']))
            ->map(function (array $row) use ($policy, $riskProfile): array {
                $history = $this->historyFor((string) $row['provider'], (string) $row['model']);
                $priors = $this->tierPriors((string) $row['tier']);
                $quality = $this->qualityScore($history, $priors, $riskProfile);
                $latency = $this->latencyScore($history, $priors);
                $cost = $this->costScore($history, $priors);
                $score = $this->weightedScore($policy, $riskProfile, $quality, $latency, $cost, (string) $row['tier']);

                return [
                    'alias' => $row['alias'],
                    'provider' => $row['provider'],
                    'model' => $row['model'],
                    'label' => $row['label'],
                    'tier' => $row['tier'],
                    'source' => $row['source'],
                    'score' => round($score, 4),
                    'quality_score' => round($quality, 4),
                    'latency_score' => round($latency, 4),
                    'cost_score' => round($cost, 4),
                    'sample_count' => $history['sample_count'],
                    'average_pass_rate' => $history['average_pass_rate'],
                    'average_score' => $history['average_score'],
                    'average_duration_ms' => $history['average_duration_ms'],
                    'average_cost_microusd' => $history['average_cost_microusd'],
                    'bad_outcome_count' => $history['bad_outcome_count'],
                    'quality_debt_rate' => $history['quality_debt_rate'],
                ];
            })
            ->sortByDesc('score')
            ->values();

        return $rows;
    }

    /**
     * @return array{sample_count:int,average_pass_rate:?float,average_score:?float,average_duration_ms:?float,average_cost_microusd:?float,bad_outcome_count:int,quality_debt_rate:?float}
     */
    private function historyFor(string $provider, string $model): array
    {
        $runs = AtlasEngineeringBenchmarkRun::query()
            ->where('provider', $provider)
            ->where('model', $model)
            ->whereNotIn('status', ['running', 'empty'])
            ->latest('finished_at')
            ->latest('created_at')
            ->limit(30)
            ->get();

        $sampleCount = $runs->count();
        $badOutcomeCount = $runs->whereIn('outcome_status', ['degraded', 'incident', 'rolled_back'])->count();
        $qualityDebtCount = $runs->filter(function (AtlasEngineeringBenchmarkRun $run): bool {
            return ((int) $run->failed_control_count) > 0
                || ((int) $run->blocked_control_count) > 0
                || ((int) $run->skipped_required_control_count) > 0
                || ((int) $run->failed_test_count) > 0
                || ((int) $run->blocking_review_finding_count) > 0;
        })->count();

        return [
            'sample_count' => $sampleCount,
            'average_pass_rate' => $sampleCount > 0 ? round((float) $runs->avg('pass_rate'), 4) : null,
            'average_score' => $sampleCount > 0 ? round((float) $runs->avg('average_score'), 4) : null,
            'average_duration_ms' => $sampleCount > 0 ? round((float) $runs->avg('duration_ms'), 4) : null,
            'average_cost_microusd' => $sampleCount > 0 ? round((float) $runs->avg('cost_microusd'), 4) : null,
            'bad_outcome_count' => $badOutcomeCount,
            'quality_debt_rate' => $sampleCount > 0 ? round($qualityDebtCount / $sampleCount, 4) : null,
        ];
    }

    /**
     * @return array{quality:float,latency:float,cost:float}
     */
    private function tierPriors(string $tier): array
    {
        return match ($tier) {
            'premium' => ['quality' => 86.0, 'latency' => 0.46, 'cost' => 0.28],
            'fallback' => ['quality' => 68.0, 'latency' => 0.78, 'cost' => 0.88],
            default => ['quality' => 76.0, 'latency' => 0.64, 'cost' => 0.62],
        };
    }

    /**
     * @param  array<string,mixed>  $history
     * @param  array<string,float>  $priors
     */
    private function qualityScore(array $history, array $priors, string $riskProfile): float
    {
        $averageScore = is_numeric($history['average_score'] ?? null) ? (float) $history['average_score'] : $priors['quality'];
        $passRate = is_numeric($history['average_pass_rate'] ?? null) ? (float) $history['average_pass_rate'] : $priors['quality'];
        $debtPenalty = is_numeric($history['quality_debt_rate'] ?? null) ? ((float) $history['quality_debt_rate'] * 18.0) : 0.0;
        $badOutcomePenalty = ((int) ($history['bad_outcome_count'] ?? 0)) * 8.0;
        $riskBoost = in_array($riskProfile, ['high', 'critical'], true) ? 4.0 : 0.0;

        return max(0.0, min(100.0, (($averageScore * 0.65) + ($passRate * 0.35)) - $debtPenalty - $badOutcomePenalty + $riskBoost));
    }

    /**
     * @param  array<string,mixed>  $history
     * @param  array<string,float>  $priors
     */
    private function latencyScore(array $history, array $priors): float
    {
        $duration = is_numeric($history['average_duration_ms'] ?? null) && (float) $history['average_duration_ms'] > 0
            ? (float) $history['average_duration_ms']
            : null;

        if ($duration === null) {
            return $priors['latency'] * 100.0;
        }

        return max(5.0, min(100.0, 100.0 - min(95.0, $duration / 1800.0)));
    }

    /**
     * @param  array<string,mixed>  $history
     * @param  array<string,float>  $priors
     */
    private function costScore(array $history, array $priors): float
    {
        $cost = is_numeric($history['average_cost_microusd'] ?? null) && (float) $history['average_cost_microusd'] > 0
            ? (float) $history['average_cost_microusd']
            : null;

        if ($cost === null) {
            return $priors['cost'] * 100.0;
        }

        return max(2.0, min(100.0, 100.0 - min(98.0, $cost / 2000.0)));
    }

    private function weightedScore(string $policy, string $riskProfile, float $quality, float $latency, float $cost, string $tier): float
    {
        $policy = $policy === 'auto' ? 'balanced' : $policy;
        [$qualityWeight, $latencyWeight, $costWeight] = match ($policy) {
            'best_quality' => [0.86, 0.08, 0.06],
            'fastest' => [0.34, 0.56, 0.10],
            'cheapest' => [0.34, 0.10, 0.56],
            default => [0.62, 0.22, 0.16],
        };

        if (in_array($riskProfile, ['high', 'critical'], true)) {
            $qualityWeight += 0.12;
            $latencyWeight = max(0.04, $latencyWeight - 0.06);
            $costWeight = max(0.04, $costWeight - 0.06);
        }

        $score = ($quality * $qualityWeight) + ($latency * $latencyWeight) + ($cost * $costWeight);
        if (in_array($riskProfile, ['high', 'critical'], true) && $tier === 'premium') {
            $score += 3.0;
        }

        return $score;
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function riskProfile(AtlasTask $task, array $context): string
    {
        $haystack = strtolower(json_encode([
            $task->title,
            $task->description,
            $task->priority,
            $task->domain,
            $context['contract'] ?? [],
            $context['blueprint'] ?? [],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');

        return match (true) {
            str_contains($haystack, 'critical') || str_contains($haystack, 'p0') || str_contains($haystack, 'pagamento') || str_contains($haystack, 'payment') || str_contains($haystack, 'security') || str_contains($haystack, 'migration') => 'critical',
            str_contains($haystack, 'database') || str_contains($haystack, 'auth') || str_contains($haystack, 'infra') || str_contains($haystack, 'release') || $task->priority === 'high' => 'high',
            str_contains($haystack, 'ui') || str_contains($haystack, 'frontend') || str_contains($haystack, 'api') => 'medium',
            default => 'low',
        };
    }

    private function providerAllowsAuto(string $provider): bool
    {
        $config = $this->settings->providerConfig($provider);

        return (bool) ($config['allow_auto'] ?? false);
    }

    /**
     * @param  array<string,mixed>  $best
     */
    private function selectionReason(string $policy, array $best, string $riskProfile): string
    {
        $sampleCount = (int) ($best['sample_count'] ?? 0);
        $source = $sampleCount > 0
            ? "historico de {$sampleCount} benchmark run(s)"
            : 'prior do catalogo';

        return "Politica {$policy} selecionou {$best['alias']} para risco {$riskProfile} usando {$source}.";
    }

    /**
     * @param  array<string,mixed>  $best
     */
    private function confidence(array $best, int $candidateCount): float
    {
        $samples = (int) ($best['sample_count'] ?? 0);
        if ($samples <= 0) {
            return $candidateCount > 0 ? 0.35 : 0.0;
        }

        return min(0.92, 0.48 + ($samples * 0.04));
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
