<?php

declare(strict_types=1);

namespace App\Services\Ai\RuntimeEfficiency;

use App\Models\AtlasRuntimeEfficiencyDecision;
use App\Models\AtlasRuntimeEfficiencyOutcome;
use App\Models\AtlasRuntimeEfficiencyPolicy;
use App\Models\AtlasRuntimeEfficiencyReplay;
use App\Services\Ai\Caching\EfficiencyOutcomeRecorder;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class AtlasRuntimeEfficiencyGovernorService implements EfficiencyOutcomeRecorder
{
    public const SCHEMA_VERSION = 'atlas.runtime_efficiency_governor.v1';

    public const CONTEXT_MINIMUM_SCHEMA = 'atlas.context_minimum_pack.v1';

    public const LAYER_ADMISSION_SCHEMA = 'atlas.layer_admission_decision.v1';

    public const OUTCOME_SCHEMA = 'atlas.runtime_efficiency_outcome.v1';

    public const POLICY_SCHEMA = 'atlas.runtime_efficiency_policy.v1';

    public const COUNTERFACTUAL_REPLAY_SCHEMA = 'atlas.runtime_efficiency_counterfactual_replay.v1';

    public const CONTROL_PLANE_SCHEMA = 'atlas.runtime_efficiency.control_plane.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_WATCH = 'watch';

    public const STATUS_BLOCKED = 'blocked';

    public const PATH_FAST = 'fast_path';

    public const PATH_STANDARD = 'standard_path';

    public const PATH_DEEP = 'deep_path';

    public const PATH_FORGE = 'forge_path';

    public const PATH_BLOCKED = 'blocked_path';

    /** @var list<string> */
    private const HEAVY_LAYER_IDS = ['apcr', 'acie', 'acol', 'teos', 'aemor', 'aseif', 'asre'];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function govern(array $input): array
    {
        $prompt = $this->prompt($input);
        $surfaceId = $this->stringValue($input['surface_id'] ?? null)
            ?? $this->stringValue($input['app_surface'] ?? null)
            ?? 'atlas_ai';
        $domain = $this->normalizeDomain($this->stringValue($input['domain'] ?? null) ?? $this->classifyDomain($prompt));
        $flowId = $this->stringValue($input['flow_id'] ?? null) ?? $this->flowForDomain($domain, $input);
        $evidenceRefs = $this->stringList($input['evidence_refs'] ?? []);
        $contextRefs = $this->stringList($input['context_refs'] ?? []);
        $complexity = $this->complexityScore($prompt, $domain, $flowId, $input);
        $risk = $this->riskScore($prompt, $domain, $flowId, $input, $evidenceRefs);
        $basePath = $this->path($prompt, $domain, $flowId, $complexity, $risk, $input);
        $adaptivePolicy = $this->activePolicyFor($domain, $flowId);
        $qualityPrediction = $this->qualityPrediction($domain, $flowId, $basePath, $complexity, $risk, $adaptivePolicy);
        $path = $this->applyPolicyToPath($basePath, $adaptivePolicy, $risk, $input);
        $status = $path === self::PATH_BLOCKED ? self::STATUS_BLOCKED : ($risk >= 8 || $this->undercontextRisk($domain, $flowId, $risk, $evidenceRefs) ? self::STATUS_WATCH : self::STATUS_READY);
        $budgets = $this->applyPolicyToBudgets($this->budgets($path, $complexity, $risk), $adaptivePolicy, $risk);
        $efficiencyRisks = $this->efficiencyRisks($prompt, $domain, $flowId, $risk, $evidenceRefs, $contextRefs, $input);
        $contextMinimumPack = $this->contextMinimumPack($prompt, $domain, $flowId, $path, $budgets, $evidenceRefs, $contextRefs, $efficiencyRisks);
        $layerAdmissions = $this->layerAdmissions($domain, $flowId, $path, $risk, $evidenceRefs, $contextRefs, $input, $adaptivePolicy);
        $toolPolicy = $this->toolPolicy($domain, $flowId, $path, $risk);
        $providerFit = $this->providerFit($domain, $flowId, $path, $risk);
        $verificationPlan = $this->verificationPlan($domain, $flowId, $path, $risk, $input);
        $counterfactualReplay = $this->counterfactualReplay([
            'prompt' => $prompt,
            'domain' => $domain,
            'flow_id' => $flowId,
            'baseline_path' => $basePath,
            'complexity_score' => $complexity,
            'risk_score' => $risk,
            'evidence_refs' => $evidenceRefs,
            'persist' => false,
        ]);
        $enforcementPolicy = $this->enforcementPolicy($adaptivePolicy, $risk, $status);

        $decisionPayload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'surface_id' => $surfaceId,
            'domain' => $domain,
            'flow_id' => $flowId,
            'runtime_mode' => $this->runtimeMode($path),
            'path' => $path,
            'prompt_hash' => MissionCanonicalHash::sha256(['prompt' => $prompt]),
            'complexity_score' => $complexity,
            'risk_score' => $risk,
            'context_budget_tokens' => $budgets['context_budget_tokens'],
            'tool_budget' => $budgets['tool_budget'],
            'subagent_budget' => $budgets['subagent_budget'],
            'context_minimum_pack' => $contextMinimumPack,
            'layer_admissions' => $layerAdmissions,
            'tool_policy' => $toolPolicy,
            'provider_fit' => $providerFit,
            'verification_plan' => $verificationPlan,
            'adaptive_policy' => $adaptivePolicy,
            'counterfactual_replay' => $counterfactualReplay,
            'enforcement_policy' => $enforcementPolicy,
            'quality_prediction' => $qualityPrediction,
            'efficiency_risks' => $efficiencyRisks,
            'evidence_refs' => $evidenceRefs,
            'claim_policy' => $this->claimPolicy(),
        ];
        $decisionPayload['decision_hash'] = MissionCanonicalHash::sha256($decisionPayload);

        $decision = null;
        if ((bool) ($input['persist'] ?? true) && Schema::hasTable('atlas_runtime_efficiency_decisions')) {
            $decision = AtlasRuntimeEfficiencyDecision::query()->create($decisionPayload);
        }

        return [
            ...$decisionPayload,
            'decision_id' => $decision?->id,
            'writes' => $decision !== null,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function recordOutcome(array $input): array
    {
        $decisionId = $this->stringValue($input['decision_id'] ?? null);
        $qualityScore = $this->numericOrNull($input['quality_score'] ?? null);
        $contextRoi = $this->numericOrNull($input['context_roi_score'] ?? null);
        $status = $this->stringValue($input['status'] ?? null) ?? self::STATUS_READY;
        $signals = is_array($input['signals'] ?? null) ? $input['signals'] : [];
        $evidenceRefs = $this->stringList($input['evidence_refs'] ?? []);
        $learningCandidates = $this->learningCandidates($qualityScore, $contextRoi, $signals);

        $payload = [
            'schema_version' => self::OUTCOME_SCHEMA,
            'status' => $status,
            'decision_id' => $decisionId,
            'outcome_type' => $this->stringValue($input['outcome_type'] ?? null) ?? 'runtime_efficiency_feedback',
            'quality_score' => $qualityScore,
            'context_roi_score' => $contextRoi,
            'signals' => $signals,
            'learning_candidates' => $learningCandidates,
            'evidence_refs' => $evidenceRefs,
        ];
        $payload['outcome_hash'] = MissionCanonicalHash::sha256($payload);

        $outcome = null;
        $persist = ($input['persist'] ?? true) !== false;
        if ($persist && Schema::hasTable('atlas_runtime_efficiency_outcomes')) {
            $outcome = AtlasRuntimeEfficiencyOutcome::query()->create($payload);
        }
        $compiledPolicy = null;
        if ($persist && $decisionId !== null && Schema::hasTable('atlas_runtime_efficiency_decisions')) {
            $decision = AtlasRuntimeEfficiencyDecision::query()->find($decisionId);
            if ($decision instanceof AtlasRuntimeEfficiencyDecision) {
                $compiledPolicy = $this->compilePolicy([
                    'flow_id' => $decision->flow_id,
                    'domain' => $decision->domain,
                    'min_samples' => 2,
                    'hours' => 720,
                    'source' => 'outcome_feedback',
                ]);
            }
        }

        return [
            ...$payload,
            'outcome_id' => $outcome?->id,
            'compiled_policy' => $compiledPolicy,
            'writes' => $outcome !== null,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function compilePolicy(array $input = []): array
    {
        $flowId = $this->stringValue($input['flow_id'] ?? null) ?? 'atlas_conversation';
        $domain = $this->normalizeDomain($this->stringValue($input['domain'] ?? null) ?? 'conversation');
        $hours = max(1, (int) ($input['hours'] ?? 720));
        $minSamples = max(1, (int) ($input['min_samples'] ?? 3));
        $since = CarbonImmutable::now()->subHours($hours);

        $decisions = Schema::hasTable('atlas_runtime_efficiency_decisions')
            ? AtlasRuntimeEfficiencyDecision::query()
                ->with('outcomes')
                ->where('flow_id', $flowId)
                ->where('created_at', '>=', $since)
                ->latest()
                ->limit(200)
                ->get()
            : collect();
        $outcomes = $decisions
            ->flatMap(fn (AtlasRuntimeEfficiencyDecision $decision): Collection => $decision->outcomes->map(function (AtlasRuntimeEfficiencyOutcome $outcome) use ($decision): array {
                return [
                    'path' => (string) $decision->path,
                    'quality_score' => $outcome->quality_score,
                    'context_roi_score' => $outcome->context_roi_score,
                    'status' => (string) $outcome->status,
                    'decision_hash' => (string) $decision->decision_hash,
                    'outcome_hash' => (string) $outcome->outcome_hash,
                ];
            }))
            ->filter(fn (array $row): bool => $row['quality_score'] !== null || $row['context_roi_score'] !== null)
            ->values();

        $sampleSize = $outcomes->count();
        $pathScores = $this->pathScores($outcomes);
        $recommendedPath = $this->recommendedPathFromScores($pathScores, $flowId);
        $qualityAverage = $sampleSize > 0 ? round((float) $outcomes->avg('quality_score'), 2) : null;
        $contextRoiAverage = $sampleSize > 0 ? round((float) $outcomes->avg('context_roi_score'), 2) : null;
        $contextMultiplier = $this->contextMultiplier($qualityAverage, $contextRoiAverage);
        $toolMultiplier = $qualityAverage !== null && $qualityAverage < 0.70 ? 1.25 : 1.0;
        $status = $sampleSize < $minSamples ? self::STATUS_WATCH : self::STATUS_READY;
        $enforcementLevel = match (true) {
            $sampleSize < $minSamples => 'advisory',
            ($qualityAverage ?? 0) >= 0.86 && ($contextRoiAverage ?? 0) >= 0.70 => 'enforced',
            ($qualityAverage ?? 0) >= 0.74 => 'shadow_enforced',
            default => 'advisory',
        };
        $policyRules = [
            'enforcement_level' => $enforcementLevel,
            'min_samples' => $minSamples,
            'hours' => $hours,
            'anti_false_learning_gate' => [
                'requires_min_samples' => true,
                'requires_outcome_evidence' => true,
                'allows_path_downgrade_on_high_risk' => false,
            ],
            'budget_governor' => [
                'context_budget_multiplier' => $contextMultiplier,
                'tool_budget_multiplier' => $toolMultiplier,
            ],
        ];
        $payload = [
            'schema_version' => self::POLICY_SCHEMA,
            'status' => $status,
            'flow_id' => $flowId,
            'domain' => $domain,
            'recommended_path' => $recommendedPath,
            'context_budget_multiplier' => $contextMultiplier,
            'tool_budget_multiplier' => $toolMultiplier,
            'layer_overrides' => $this->layerUtilityFromOutcomes($outcomes),
            'quality_stats' => [
                'sample_size' => $sampleSize,
                'quality_average' => $qualityAverage,
                'context_roi_average' => $contextRoiAverage,
                'path_scores' => $pathScores,
            ],
            'policy_rules' => $policyRules,
            'evidence_refs' => $outcomes->pluck('outcome_hash')->filter()->take(25)->values()->all(),
        ];
        $payload['policy_hash'] = MissionCanonicalHash::sha256($payload);

        $policy = null;
        if (Schema::hasTable('atlas_runtime_efficiency_policies')) {
            $policy = AtlasRuntimeEfficiencyPolicy::query()->create($payload);
        }

        return [
            ...$payload,
            'policy_id' => $policy?->id,
            'writes' => $policy !== null,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function counterfactualReplay(array $input): array
    {
        $prompt = $this->prompt($input);
        $domain = $this->normalizeDomain($this->stringValue($input['domain'] ?? null) ?? $this->classifyDomain($prompt));
        $flowId = $this->stringValue($input['flow_id'] ?? null) ?? $this->flowForDomain($domain, $input);
        $complexity = (int) ($input['complexity_score'] ?? $this->complexityScore($prompt, $domain, $flowId, $input));
        $risk = (int) ($input['risk_score'] ?? $this->riskScore($prompt, $domain, $flowId, $input, $this->stringList($input['evidence_refs'] ?? [])));
        $baselinePath = $this->stringValue($input['baseline_path'] ?? null) ?? $this->path($prompt, $domain, $flowId, $complexity, $risk, $input);
        $candidates = collect([self::PATH_FAST, self::PATH_STANDARD, self::PATH_DEEP, self::PATH_FORGE, self::PATH_BLOCKED])
            ->map(fn (string $path): array => $this->scoreCounterfactualPath($path, $baselinePath, $domain, $flowId, $complexity, $risk))
            ->sortByDesc('utility_score')
            ->values()
            ->all();
        $winningCandidate = $candidates[0] ?? [];
        $payload = [
            'schema_version' => self::COUNTERFACTUAL_REPLAY_SCHEMA,
            'status' => self::STATUS_READY,
            'decision_id' => $this->stringValue($input['decision_id'] ?? null),
            'flow_id' => $flowId,
            'baseline_path' => $baselinePath,
            'recommended_path' => (string) ($winningCandidate['path'] ?? $baselinePath),
            'candidates' => $candidates,
            'winning_candidate' => $winningCandidate,
            'evidence_refs' => $this->stringList($input['evidence_refs'] ?? []),
        ];
        $payload['replay_hash'] = MissionCanonicalHash::sha256($payload);

        $replay = null;
        if (($input['persist'] ?? true) !== false && Schema::hasTable('atlas_runtime_efficiency_replays')) {
            $replay = AtlasRuntimeEfficiencyReplay::query()->create($payload);
        }

        return [
            ...$payload,
            'replay_id' => $replay?->id,
            'writes' => $replay !== null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function controlPlane(int $hours = 24): array
    {
        $since = CarbonImmutable::now()->subHours(max(1, $hours));
        $empty = [
            'schema_version' => self::CONTROL_PLANE_SCHEMA,
            'status' => Schema::hasTable('atlas_runtime_efficiency_decisions') ? self::STATUS_READY : 'missing',
            'summary' => [
                'decisions_total' => 0,
                'blocked' => 0,
                'watch' => 0,
                'ready' => 0,
                'fast_path' => 0,
                'deep_path' => 0,
                'forge_path' => 0,
                'average_context_budget_tokens' => null,
                'average_complexity_score' => null,
                'average_risk_score' => null,
                'outcomes_total' => 0,
                'policies_total' => 0,
                'replays_total' => 0,
            ],
            'by_flow' => [],
            'by_path' => [],
            'recent_decisions' => [],
            'recent_outcomes' => [],
            'recent_policies' => [],
            'recent_replays' => [],
            'blockers' => [],
            'claim_policy' => $this->claimPolicy(),
        ];
        if (! Schema::hasTable('atlas_runtime_efficiency_decisions')) {
            $empty['control_plane_hash'] = MissionCanonicalHash::sha256($empty);

            return $empty;
        }

        $decisions = AtlasRuntimeEfficiencyDecision::query()
            ->where('created_at', '>=', $since)
            ->latest()
            ->limit(300)
            ->get();
        $outcomes = Schema::hasTable('atlas_runtime_efficiency_outcomes')
            ? AtlasRuntimeEfficiencyOutcome::query()->where('created_at', '>=', $since)->latest()->limit(100)->get()
            : collect();
        $policies = Schema::hasTable('atlas_runtime_efficiency_policies')
            ? AtlasRuntimeEfficiencyPolicy::query()->where('created_at', '>=', $since)->latest()->limit(50)->get()
            : collect();
        $replays = Schema::hasTable('atlas_runtime_efficiency_replays')
            ? AtlasRuntimeEfficiencyReplay::query()->where('created_at', '>=', $since)->latest()->limit(50)->get()
            : collect();

        $blockers = $this->controlPlaneBlockers($decisions);
        $payload = [
            ...$empty,
            'status' => $blockers !== [] ? self::STATUS_WATCH : self::STATUS_READY,
            'summary' => [
                'decisions_total' => $decisions->count(),
                'blocked' => $decisions->where('status', self::STATUS_BLOCKED)->count(),
                'watch' => $decisions->where('status', self::STATUS_WATCH)->count(),
                'ready' => $decisions->where('status', self::STATUS_READY)->count(),
                'fast_path' => $decisions->where('path', self::PATH_FAST)->count(),
                'deep_path' => $decisions->where('path', self::PATH_DEEP)->count(),
                'forge_path' => $decisions->where('path', self::PATH_FORGE)->count(),
                'average_context_budget_tokens' => $this->average($decisions, 'context_budget_tokens'),
                'average_complexity_score' => $this->average($decisions, 'complexity_score'),
                'average_risk_score' => $this->average($decisions, 'risk_score'),
                'outcomes_total' => $outcomes->count(),
                'policies_total' => $policies->count(),
                'replays_total' => $replays->count(),
            ],
            'by_flow' => $this->countsBy($decisions, 'flow_id'),
            'by_path' => $this->countsBy($decisions, 'path'),
            'recent_decisions' => $decisions->take(20)->map(fn (AtlasRuntimeEfficiencyDecision $decision): array => [
                'decision_id' => (string) $decision->id,
                'status' => (string) $decision->status,
                'domain' => $decision->domain,
                'flow_id' => $decision->flow_id,
                'path' => (string) $decision->path,
                'prompt_hash' => (string) $decision->prompt_hash,
                'decision_hash' => (string) $decision->decision_hash,
                'context_budget_tokens' => (int) $decision->context_budget_tokens,
                'created_at' => $decision->created_at?->toJSON(),
            ])->values()->all(),
            'recent_outcomes' => $outcomes->take(20)->map(fn (AtlasRuntimeEfficiencyOutcome $outcome): array => [
                'outcome_id' => (string) $outcome->id,
                'decision_id' => $outcome->decision_id,
                'status' => (string) $outcome->status,
                'outcome_type' => (string) $outcome->outcome_type,
                'quality_score' => $outcome->quality_score,
                'context_roi_score' => $outcome->context_roi_score,
                'outcome_hash' => (string) $outcome->outcome_hash,
                'created_at' => $outcome->created_at?->toJSON(),
            ])->values()->all(),
            'recent_policies' => $policies->take(10)->map(fn (AtlasRuntimeEfficiencyPolicy $policy): array => [
                'policy_id' => (string) $policy->id,
                'status' => (string) $policy->status,
                'flow_id' => (string) $policy->flow_id,
                'recommended_path' => (string) $policy->recommended_path,
                'context_budget_multiplier' => $policy->context_budget_multiplier,
                'tool_budget_multiplier' => $policy->tool_budget_multiplier,
                'policy_hash' => (string) $policy->policy_hash,
                'created_at' => $policy->created_at?->toJSON(),
            ])->values()->all(),
            'recent_replays' => $replays->take(10)->map(fn (AtlasRuntimeEfficiencyReplay $replay): array => [
                'replay_id' => (string) $replay->id,
                'status' => (string) $replay->status,
                'flow_id' => $replay->flow_id,
                'baseline_path' => (string) $replay->baseline_path,
                'recommended_path' => (string) $replay->recommended_path,
                'replay_hash' => (string) $replay->replay_hash,
                'created_at' => $replay->created_at?->toJSON(),
            ])->values()->all(),
            'blockers' => $blockers,
            'claim_policy' => $this->claimPolicy(),
        ];
        $payload['control_plane_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function claimPolicy(): array
    {
        return [
            'governs_only' => true,
            'provider_invoked' => false,
            'external_execution_performed' => false,
            'benchmark_not_run' => true,
            'does_not_bypass_policy_or_evidence_gates' => true,
            'does_not_replace_router_runtime' => true,
        ];
    }

    private function prompt(array $input): string
    {
        return $this->stringValue($input['prompt'] ?? $input['input_text'] ?? $input['objective'] ?? null) ?? '';
    }

    private function normalizeDomain(string $domain): string
    {
        return match ($domain) {
            'dev', 'code', 'coding' => 'programming',
            'strategy', 'strategic' => 'strategy',
            default => $domain,
        };
    }

    private function classifyDomain(string $prompt): string
    {
        $lower = Str::lower($prompt);

        return match (true) {
            str_contains($lower, 'debug') || str_contains($lower, 'codigo') || str_contains($lower, 'código') || str_contains($lower, 'teste') || str_contains($lower, 'forge') => 'programming',
            str_contains($lower, 'mercado') || str_contains($lower, 'pesquisa') || str_contains($lower, 'paper') => 'research',
            str_contains($lower, 'finance') || str_contains($lower, 'carteira') || str_contains($lower, 'invest') || str_contains($lower, 'trade') => 'finance',
            str_contains($lower, 'marketing') || str_contains($lower, 'campanha') || str_contains($lower, 'copy') => 'marketing',
            str_contains($lower, 'estrateg') || str_contains($lower, 'decis') || str_contains($lower, 'prioridade') => 'strategy',
            default => 'conversation',
        };
    }

    private function flowForDomain(string $domain, array $input): string
    {
        $task = $this->stringValue($input['task'] ?? $input['routing_task'] ?? null);
        if ($domain === 'programming') {
            return match ($task) {
                'debug', 'repair' => 'atlas_debug',
                'review' => 'atlas_review',
                'forge' => 'atlas_forge',
                default => 'atlas_dev',
            };
        }

        return match ($domain) {
            'research' => 'atlas_research',
            'finance' => 'atlas_finance',
            'marketing' => 'atlas_marketing',
            'strategy' => 'atlas_strategy',
            default => 'atlas_conversation',
        };
    }

    private function complexityScore(string $prompt, string $domain, string $flowId, array $input): int
    {
        $words = str_word_count($prompt);
        $score = match (true) {
            $words <= 6 => 1,
            $words <= 30 => 3,
            $words <= 120 => 5,
            default => 7,
        };
        $lower = Str::lower($prompt.' '.$flowId.' '.$domain);
        foreach (['enterprise', 'completo', 'robusto', 'multi', 'obra', 'forge', 'auditar', 'testes', 'certificar', 'autonom'] as $signal) {
            if (str_contains($lower, $signal)) {
                $score += 1;
            }
        }
        $score += min(2, count((array) data_get($input, 'context_refs', [])) > 8 ? 2 : 0);

        return max(1, min(10, $score));
    }

    private function riskScore(string $prompt, string $domain, string $flowId, array $input, array $evidenceRefs): int
    {
        $lower = Str::lower($prompt.' '.$flowId.' '.$domain);
        $score = match ($domain) {
            'finance', 'cyber', 'security' => 7,
            'programming' => str_contains($flowId, 'forge') ? 7 : 5,
            'strategy' => 6,
            default => 2,
        };
        foreach (['delete', 'apagar', 'extern', 'comprar', 'vender', 'trade', 'producao', 'produção', 'credential', 'secret', 'migration'] as $signal) {
            if (str_contains($lower, $signal)) {
                $score += 1;
            }
        }
        if ($evidenceRefs === [] && in_array($domain, ['finance', 'strategy', 'programming'], true)) {
            $score += 1;
        }
        if ((bool) data_get($input, 'external_execution_requested', false)) {
            $score = 10;
        }

        return max(1, min(10, $score));
    }

    private function path(string $prompt, string $domain, string $flowId, int $complexity, int $risk, array $input): string
    {
        if ((bool) data_get($input, 'external_execution_requested', false) && $risk >= 10) {
            return self::PATH_BLOCKED;
        }
        if ($flowId === 'atlas_forge' || str_contains(Str::lower($prompt), 'obra') || ($complexity >= 8 && $domain === 'programming')) {
            return self::PATH_FORGE;
        }
        if ($risk >= 7 || $complexity >= 7 || in_array($domain, ['finance', 'strategy', 'research'], true)) {
            return self::PATH_DEEP;
        }
        if ($complexity <= 2 && $risk <= 3) {
            return self::PATH_FAST;
        }

        return self::PATH_STANDARD;
    }

    /**
     * @return array{context_budget_tokens:int,tool_budget:int,subagent_budget:int}
     */
    private function budgets(string $path, int $complexity, int $risk): array
    {
        $base = match ($path) {
            self::PATH_FAST => [1200, 0, 0],
            self::PATH_STANDARD => [8000, 4, 0],
            self::PATH_DEEP => [18000, 8, 2],
            self::PATH_FORGE => [36000, 14, 5],
            default => [0, 0, 0],
        };

        return [
            'context_budget_tokens' => $base[0] + ($complexity >= 8 ? 4000 : 0),
            'tool_budget' => $base[1] + ($risk >= 8 ? 2 : 0),
            'subagent_budget' => $base[2],
        ];
    }

    private function runtimeMode(string $path): string
    {
        return match ($path) {
            self::PATH_FAST => 'fast',
            self::PATH_DEEP => 'deep',
            self::PATH_FORGE => 'forge',
            self::PATH_BLOCKED => 'blocked',
            default => 'standard',
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function contextMinimumPack(string $prompt, string $domain, string $flowId, string $path, array $budgets, array $evidenceRefs, array $contextRefs, array $risks): array
    {
        $mustKeep = array_values(array_filter([
            ['kind' => 'goal', 'value_hash' => MissionCanonicalHash::sha256(['goal' => $prompt])],
            ['kind' => 'domain', 'value' => $domain],
            ['kind' => 'flow_id', 'value' => $flowId],
            $path === self::PATH_FORGE ? ['kind' => 'forge_boundary', 'value' => 'long_work_requires_milestones_receipts_and_operator_review'] : null,
            in_array('undercontext', array_column($risks, 'kind'), true) ? ['kind' => 'missing_evidence', 'value' => 'execution_must_retrieve_or_request_context'] : null,
        ]));

        return [
            'schema_version' => self::CONTEXT_MINIMUM_SCHEMA,
            'status' => in_array('undercontext', array_column($risks, 'kind'), true) ? self::STATUS_WATCH : self::STATUS_READY,
            'max_context_tokens' => $budgets['context_budget_tokens'],
            'included_context_refs' => array_slice($contextRefs, 0, 50),
            'evidence_refs' => $evidenceRefs,
            'must_keep' => $mustKeep,
            'forbidden_context' => [
                'raw_tool_manuals_without_admission',
                'full_docs_when_summary_plus_refs_is_sufficient',
                'unbounded_chat_history',
            ],
            'pack_hash' => MissionCanonicalHash::sha256([$domain, $flowId, $path, $mustKeep, $contextRefs, $evidenceRefs]),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function layerAdmissions(string $domain, string $flowId, string $path, int $risk, array $evidenceRefs, array $contextRefs, array $input, ?array $adaptivePolicy = null): array
    {
        $needsStrategy = in_array($domain, ['strategy', 'finance', 'marketing', 'personal_development', 'automation'], true);
        $needsProgramming = $domain === 'programming';
        $needsLongHorizon = $path === self::PATH_FORGE || (bool) data_get($input, 'long_horizon', false);

        $admissions = [
            'apcr' => $path !== self::PATH_FAST || $contextRefs !== [] || $evidenceRefs !== [],
            'acie' => $path !== self::PATH_FAST && in_array($domain, ['programming', 'research', 'finance', 'strategy', 'marketing'], true),
            'acol' => $path !== self::PATH_FAST && ((bool) data_get($input, 'conversation_continuation', false) || $needsLongHorizon),
            'teos' => $needsLongHorizon,
            'aemor' => $path !== self::PATH_FAST,
            'aseif' => $needsProgramming && ($path === self::PATH_DEEP || $path === self::PATH_FORGE || str_contains((string) data_get($input, 'prompt', ''), 'ferramenta')),
            'asre' => $needsStrategy,
        ];

        return collect(self::HEAVY_LAYER_IDS)
            ->map(function (string $layerId) use ($admissions, $path, $risk, $adaptivePolicy): array {
                $admitted = (bool) ($admissions[$layerId] ?? false);
                $utility = (float) data_get($adaptivePolicy, "layer_overrides.$layerId.utility_score", ($admitted ? 0.65 : 0.15));

                return [
                    'schema_version' => self::LAYER_ADMISSION_SCHEMA,
                    'layer_id' => $layerId,
                    'admitted' => $admitted,
                    'mode' => $admitted ? ($risk >= 8 ? 'strict' : 'standard') : 'skip',
                    'utility_score' => round($utility, 2),
                    'reason' => $admitted ? 'utility_exceeds_budget_for_'.$path : 'not_required_for_'.$path,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function toolPolicy(string $domain, string $flowId, string $path, int $risk): array
    {
        $tools = match ($domain) {
            'programming' => ['rg', 'read_file', 'apply_patch', 'focused_tests', 'pint'],
            'research' => ['retrieval', 'source_reader', 'citation_audit'],
            'finance' => ['read_only_analysis', 'freshness_check', 'approval_gate'],
            default => ['none_by_default'],
        };

        return [
            'allowed_tool_groups' => $path === self::PATH_FAST ? [] : $tools,
            'requires_receipts' => $path !== self::PATH_FAST,
            'requires_operator_approval' => $risk >= 8 || $domain === 'finance',
            'external_side_effects_allowed' => false,
            'tool_policy_hash' => MissionCanonicalHash::sha256([$domain, $flowId, $path, $risk, $tools]),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function providerFit(string $domain, string $flowId, string $path, int $risk): array
    {
        return [
            'selection_authority' => 'atlas_decide',
            'recommended_strategy' => match ($path) {
                self::PATH_FAST => 'small_fast_model_or_cached_response',
                self::PATH_FORGE => 'frontier_model_with_replayable_handoff_and_critic',
                self::PATH_DEEP => 'frontier_model_with_optional_critic',
                self::PATH_BLOCKED => 'no_provider_until_policy_context_green',
                default => 'balanced_model',
            },
            'critic_required' => $risk >= 8 || $path === self::PATH_FORGE,
            'provider_invoked' => false,
            'provider_fit_hash' => MissionCanonicalHash::sha256([$domain, $flowId, $path, $risk]),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function verificationPlan(string $domain, string $flowId, string $path, int $risk, array $input): array
    {
        $checks = match ($domain) {
            'programming' => ['focused_tests', 'diff_review', 'receipt_check'],
            'research' => ['source_coverage', 'claim_uncertainty_audit'],
            'finance' => ['freshness_check', 'no_external_action', 'risk_disclosure'],
            'strategy' => ['assumption_check', 'evidence_refs_check', 'operator_review'],
            default => ['response_shape_check'],
        };
        if ($path === self::PATH_FORGE) {
            $checks[] = 'milestone_gate';
            $checks[] = 'long_horizon_continuity_gate';
        }
        if ($risk >= 8) {
            $checks[] = 'policy_gate';
        }

        return [
            'checks' => array_values(array_unique($checks)),
            'minimum_evidence_refs' => $path === self::PATH_FAST ? 0 : 1,
            'completion_allowed_without_evidence' => $path === self::PATH_FAST,
            'verification_hash' => MissionCanonicalHash::sha256([$domain, $flowId, $path, $risk, $checks, $input['trace_id'] ?? null]),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function efficiencyRisks(string $prompt, string $domain, string $flowId, int $risk, array $evidenceRefs, array $contextRefs, array $input): array
    {
        $risks = [];
        if ($this->undercontextRisk($domain, $flowId, $risk, $evidenceRefs)) {
            $risks[] = [
                'kind' => 'undercontext',
                'severity' => 'high',
                'reason' => 'non_trivial_or_risky_task_without_evidence_refs',
                'mitigation' => 'run_retrieval_or_request_context_before_execution',
            ];
        }
        if (count($contextRefs) > 40 || strlen($prompt) > 12000 || count((array) data_get($input, 'tool_specs', [])) > 10) {
            $risks[] = [
                'kind' => 'overcontext',
                'severity' => 'medium',
                'reason' => 'context_or_tool_surface_too_large_for_default_path',
                'mitigation' => 'summarize_rank_and_pack_context_minimum_first',
            ];
        }
        if ($risk >= 9) {
            $risks[] = [
                'kind' => 'policy',
                'severity' => 'critical',
                'reason' => 'high_risk_or_external_side_effect_domain',
                'mitigation' => 'block_or_require_operator_policy_gate',
            ];
        }

        return $risks;
    }

    private function undercontextRisk(string $domain, string $flowId, int $risk, array $evidenceRefs): bool
    {
        if ($evidenceRefs !== []) {
            return false;
        }

        return $risk >= 6 || in_array($domain, ['programming', 'finance', 'strategy', 'research'], true) || $flowId === 'atlas_forge';
    }

    /**
     * @param  Collection<int,AtlasRuntimeEfficiencyDecision>  $decisions
     * @return array<int,array<string,mixed>>
     */
    private function controlPlaneBlockers(Collection $decisions): array
    {
        return $decisions
            ->filter(fn (AtlasRuntimeEfficiencyDecision $decision): bool => (string) $decision->status === self::STATUS_BLOCKED)
            ->take(20)
            ->map(fn (AtlasRuntimeEfficiencyDecision $decision): array => [
                'kind' => 'runtime_efficiency_blocked',
                'severity' => 'critical',
                'decision_id' => (string) $decision->id,
                'flow_id' => $decision->flow_id,
                'decision_hash' => $decision->decision_hash,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int,object>  $rows
     */
    private function average(Collection $rows, string $field): ?float
    {
        if ($rows->isEmpty()) {
            return null;
        }

        return round((float) $rows->avg($field), 2);
    }

    /**
     * @param  Collection<int,object>  $rows
     * @return array<string,int>
     */
    private function countsBy(Collection $rows, string $field): array
    {
        return $rows
            ->groupBy(fn (object $row): string => (string) ($row->{$field} ?? 'unknown'))
            ->map(fn (Collection $group): int => $group->count())
            ->sortKeys()
            ->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function learningCandidates(?float $qualityScore, ?float $contextRoi, array $signals): array
    {
        $candidates = [];
        if ($qualityScore !== null && $qualityScore < 0.60) {
            $candidates[] = ['kind' => 'increase_verification_budget', 'reason' => 'low_quality_score'];
        }
        if ($contextRoi !== null && $contextRoi < 0.40) {
            $candidates[] = ['kind' => 'reduce_context_or_improve_ranking', 'reason' => 'low_context_roi'];
        }
        if (($signals['rework_required'] ?? false) === true) {
            $candidates[] = ['kind' => 'promote_repair_pattern_to_aemor', 'reason' => 'rework_required'];
        }

        return $candidates;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function activePolicyFor(string $domain, string $flowId): ?array
    {
        if (! Schema::hasTable('atlas_runtime_efficiency_policies')) {
            return null;
        }
        $policy = AtlasRuntimeEfficiencyPolicy::query()
            ->where('flow_id', $flowId)
            ->where('status', self::STATUS_READY)
            ->latest()
            ->first();
        if (! $policy instanceof AtlasRuntimeEfficiencyPolicy) {
            return null;
        }

        return [
            'schema_version' => self::POLICY_SCHEMA,
            'policy_id' => (string) $policy->id,
            'status' => (string) $policy->status,
            'domain' => $policy->domain ?: $domain,
            'flow_id' => (string) $policy->flow_id,
            'recommended_path' => (string) $policy->recommended_path,
            'context_budget_multiplier' => (float) $policy->context_budget_multiplier,
            'tool_budget_multiplier' => (float) $policy->tool_budget_multiplier,
            'layer_overrides' => $policy->layer_overrides ?? [],
            'quality_stats' => $policy->quality_stats ?? [],
            'policy_rules' => $policy->policy_rules ?? [],
            'policy_hash' => (string) $policy->policy_hash,
        ];
    }

    private function applyPolicyToPath(string $basePath, ?array $policy, int $risk, array $input): string
    {
        if ($policy === null || (bool) data_get($input, 'ignore_adaptive_policy', false)) {
            return $basePath;
        }
        $enforcement = (string) data_get($policy, 'policy_rules.enforcement_level', 'advisory');
        $recommended = (string) data_get($policy, 'recommended_path', $basePath);
        if (! in_array($recommended, [self::PATH_FAST, self::PATH_STANDARD, self::PATH_DEEP, self::PATH_FORGE, self::PATH_BLOCKED], true)) {
            return $basePath;
        }
        if ($basePath === self::PATH_BLOCKED || $risk >= 8 || $enforcement === 'advisory') {
            return $basePath;
        }
        if ($this->pathRank($recommended) < $this->pathRank($basePath) && $risk >= 6) {
            return $basePath;
        }

        return $recommended;
    }

    /**
     * @param  array{context_budget_tokens:int,tool_budget:int,subagent_budget:int}  $budgets
     * @return array{context_budget_tokens:int,tool_budget:int,subagent_budget:int}
     */
    private function applyPolicyToBudgets(array $budgets, ?array $policy, int $risk): array
    {
        if ($policy === null || $risk >= 9) {
            return $budgets;
        }
        $contextMultiplier = max(0.70, min(1.50, (float) data_get($policy, 'context_budget_multiplier', 1.0)));
        $toolMultiplier = max(0.80, min(1.50, (float) data_get($policy, 'tool_budget_multiplier', 1.0)));

        return [
            'context_budget_tokens' => (int) max(0, round($budgets['context_budget_tokens'] * $contextMultiplier)),
            'tool_budget' => (int) max(0, round($budgets['tool_budget'] * $toolMultiplier)),
            'subagent_budget' => $budgets['subagent_budget'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function qualityPrediction(string $domain, string $flowId, string $basePath, int $complexity, int $risk, ?array $policy): array
    {
        $policyAverage = $this->numericOrNull(data_get($policy, 'quality_stats.quality_average'));
        $base = $policyAverage ?? match ($basePath) {
            self::PATH_FAST => 0.72,
            self::PATH_STANDARD => 0.78,
            self::PATH_DEEP => 0.84,
            self::PATH_FORGE => 0.86,
            default => 0.20,
        };
        $penalty = ($complexity >= 8 ? 0.04 : 0) + ($risk >= 8 ? 0.06 : 0);

        return [
            'schema_version' => 'atlas.runtime_efficiency.quality_prediction.v1',
            'domain' => $domain,
            'flow_id' => $flowId,
            'base_path' => $basePath,
            'predicted_quality_score' => round(max(0.05, min(0.98, $base - $penalty)), 2),
            'source' => $policy === null ? 'static_prior' : 'adaptive_policy',
            'policy_hash' => $policy['policy_hash'] ?? null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function enforcementPolicy(?array $policy, int $risk, string $status): array
    {
        $level = (string) data_get($policy, 'policy_rules.enforcement_level', 'advisory');
        if ($risk >= 8 || $status === self::STATUS_BLOCKED) {
            $level = 'safety_override';
        }

        return [
            'schema_version' => 'atlas.runtime_efficiency.enforcement_policy.v1',
            'level' => $level,
            'policy_hash' => $policy['policy_hash'] ?? null,
            'blocks_downgrade_on_high_risk' => true,
            'requires_evidence_for_non_trivial' => true,
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $outcomes
     * @return array<string,array<string,mixed>>
     */
    private function pathScores(Collection $outcomes): array
    {
        return $outcomes
            ->groupBy('path')
            ->map(function (Collection $rows): array {
                $quality = $rows->avg('quality_score');
                $roi = $rows->avg('context_roi_score');
                $score = (($quality ?? 0.65) * 0.68) + (($roi ?? 0.55) * 0.32);

                return [
                    'sample_size' => $rows->count(),
                    'quality_average' => $quality === null ? null : round((float) $quality, 2),
                    'context_roi_average' => $roi === null ? null : round((float) $roi, 2),
                    'utility_score' => round($score, 3),
                ];
            })
            ->sortKeys()
            ->all();
    }

    /**
     * @param  array<string,array<string,mixed>>  $pathScores
     */
    private function recommendedPathFromScores(array $pathScores, string $flowId): string
    {
        if ($pathScores === []) {
            return $flowId === 'atlas_forge' ? self::PATH_FORGE : self::PATH_STANDARD;
        }
        $bestPath = collect($pathScores)
            ->sortByDesc(fn (array $row): float => (float) ($row['utility_score'] ?? 0))
            ->keys()
            ->first();

        return is_string($bestPath) ? $bestPath : self::PATH_STANDARD;
    }

    private function contextMultiplier(?float $qualityAverage, ?float $contextRoiAverage): float
    {
        if ($qualityAverage !== null && $qualityAverage < 0.65) {
            return 1.25;
        }
        if ($contextRoiAverage !== null && $contextRoiAverage < 0.45) {
            return 0.80;
        }
        if ($qualityAverage !== null && $qualityAverage >= 0.85 && $contextRoiAverage !== null && $contextRoiAverage >= 0.75) {
            return 0.90;
        }

        return 1.0;
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $outcomes
     * @return array<string,array<string,mixed>>
     */
    private function layerUtilityFromOutcomes(Collection $outcomes): array
    {
        $quality = $outcomes->avg('quality_score');
        $roi = $outcomes->avg('context_roi_score');
        $utility = round((float) ((($quality ?? 0.70) * 0.6) + (($roi ?? 0.60) * 0.4)), 2);

        return collect(self::HEAVY_LAYER_IDS)
            ->mapWithKeys(fn (string $layerId): array => [$layerId => [
                'utility_score' => $utility,
                'source' => 'outcome_aggregate',
            ]])
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function scoreCounterfactualPath(string $path, string $baselinePath, string $domain, string $flowId, int $complexity, int $risk): array
    {
        $baseQuality = match ($path) {
            self::PATH_FAST => 0.66,
            self::PATH_STANDARD => 0.76,
            self::PATH_DEEP => 0.84,
            self::PATH_FORGE => 0.88,
            self::PATH_BLOCKED => $risk >= 9 ? 0.92 : 0.25,
            default => 0.50,
        };
        $costPenalty = match ($path) {
            self::PATH_FAST => 0.02,
            self::PATH_STANDARD => 0.10,
            self::PATH_DEEP => 0.20,
            self::PATH_FORGE => 0.32,
            self::PATH_BLOCKED => 0.05,
            default => 0.10,
        };
        $riskPenalty = ($risk >= 8 && in_array($path, [self::PATH_FAST, self::PATH_STANDARD], true)) ? 0.18 : 0.0;
        $complexityPenalty = ($complexity >= 8 && $path === self::PATH_FAST) ? 0.25 : 0.0;
        $flowBonus = ($flowId === 'atlas_forge' && $path === self::PATH_FORGE) ? 0.08 : 0.0;
        $domainBonus = (in_array($domain, ['finance', 'strategy', 'research'], true) && $path === self::PATH_DEEP) ? 0.04 : 0.0;
        $utility = $baseQuality + $flowBonus + $domainBonus - $costPenalty - $riskPenalty - $complexityPenalty;

        return [
            'path' => $path,
            'baseline' => $path === $baselinePath,
            'predicted_quality' => round($baseQuality + $flowBonus + $domainBonus, 2),
            'cost_penalty' => round($costPenalty, 2),
            'risk_penalty' => round($riskPenalty, 2),
            'utility_score' => round(max(0.0, min(1.0, $utility)), 3),
        ];
    }

    private function pathRank(string $path): int
    {
        return match ($path) {
            self::PATH_FAST => 1,
            self::PATH_STANDARD => 2,
            self::PATH_DEEP => 3,
            self::PATH_FORGE => 4,
            self::PATH_BLOCKED => 5,
            default => 2,
        };
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        return collect(is_array($value) ? $value : [])
            ->filter(fn (mixed $item): bool => is_scalar($item) && trim((string) $item) !== '')
            ->map(fn (mixed $item): string => trim((string) $item))
            ->values()
            ->all();
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function numericOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
