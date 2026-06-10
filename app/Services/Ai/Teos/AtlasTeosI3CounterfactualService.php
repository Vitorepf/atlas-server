<?php

declare(strict_types=1);

namespace App\Services\Ai\Teos;

use App\Services\Ai\Reality\AtlasUnifiedRealityGraphTemporalService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use InvalidArgumentException;

/**
 * TEOS-I3 · Counterfactual Planning Runtime.
 *
 * Builds counterfactual branches anchored on real decisions, projects
 * causal paths, scores divergence and produces replan recommendations.
 * Read/computational only — no provider call.
 *
 * Authority doc:
 *   docs/engineering-knowledge-base/atlas-teos-i3-counterfactual.md
 *
 * Schemas:
 *   - atlas.teos_i3.counterfactual_branch.v1
 *   - atlas.teos_i3.replan_recommendation.v1
 *
 * Invariantes:
 *   - is_counterfactual=true sempre (nunca confunde com fato);
 *   - depth máx = 6;
 *   - divergence_score em [0,1];
 *   - branches append-only;
 *   - recommendation requires_human_approval=true.
 */
class AtlasTeosI3CounterfactualService
{
    public const BRANCH_SCHEMA = 'atlas.teos_i3.counterfactual_branch.v1';

    public const RECOMMENDATION_SCHEMA = 'atlas.teos_i3.replan_recommendation.v1';

    public const KIND_POLICY_SWAP = 'policy_swap';

    public const KIND_PROVIDER_SWAP = 'provider_swap';

    public const KIND_ESCALATION = 'escalation';

    public const KIND_ABORT = 'abort';

    public const KIND_REPLAN = 'replan';

    public const VALID_ALTERNATIVE_KINDS = [
        self::KIND_POLICY_SWAP, self::KIND_PROVIDER_SWAP,
        self::KIND_ESCALATION, self::KIND_ABORT, self::KIND_REPLAN,
    ];

    public const TRIGGER_BELOW_THRESHOLD = 'below_threshold_outcome';

    public const TRIGGER_OPERATOR_REQUEST = 'operator_request';

    public const TRIGGER_REGRESSION = 'regression_detected';

    public const VALID_TRIGGERS = [
        self::TRIGGER_BELOW_THRESHOLD, self::TRIGGER_OPERATOR_REQUEST, self::TRIGGER_REGRESSION,
    ];

    public const MAX_BRANCH_DEPTH = 6;

    public const OUTCOME_IMPROVEMENT_MEDIUM_DELTA = 0.15;

    public const OUTCOME_IMPROVEMENT_HIGH_DELTA = 0.30;

    private ?string $branchesLogOverride = null;

    private ?string $recommendationsLogOverride = null;

    private ?AtlasUnifiedRealityGraphTemporalService $aurg = null;

    /**
     * Wire AURG-4D so every branch emits a temporal tick automatically.
     * Optional — call separately so existing constructors stay back-compat.
     */
    public function setAurgForChaining(?AtlasUnifiedRealityGraphTemporalService $aurg): void
    {
        $this->aurg = $aurg;
    }

    public function setBranchesLogPathForTesting(?string $path): void
    {
        $this->branchesLogOverride = $path;
    }

    public function setRecommendationsLogPathForTesting(?string $path): void
    {
        $this->recommendationsLogOverride = $path;
    }

    public function branchesLogPath(): string
    {
        if ($this->branchesLogOverride !== null) {
            return $this->branchesLogOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/teos_i3')
            : sys_get_temp_dir().'/atlas/teos_i3';

        return $base.DIRECTORY_SEPARATOR.'branches.jsonl';
    }

    public function recommendationsLogPath(): string
    {
        if ($this->recommendationsLogOverride !== null) {
            return $this->recommendationsLogOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/teos_i3')
            : sys_get_temp_dir().'/atlas/teos_i3';

        return $base.DIRECTORY_SEPARATOR.'recommendations.jsonl';
    }

    /**
     * Build one counterfactual branch and append it.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function branch(array $input): array
    {
        $scope = (array) ($input['scope'] ?? []);
        $anchor = (string) ($input['anchor_decision_id'] ?? '');
        if ($anchor === '') {
            throw new InvalidArgumentException('anchor_decision_id is required.');
        }

        $alternative = (array) ($input['alternative'] ?? []);
        $altKind = (string) ($alternative['decision_kind'] ?? '');
        if (! in_array($altKind, self::VALID_ALTERNATIVE_KINDS, true)) {
            throw new InvalidArgumentException("Unknown alternative.decision_kind '{$altKind}'.");
        }
        $altValue = $alternative['value'] ?? null;

        $factual = $this->clampScore((float) ($input['factual_outcome_score'] ?? 0.5));
        $projected = $this->clampScore((float) ($input['projected_outcome_score'] ?? $factual));
        $divergence = abs($projected - $factual);
        $divergence = $divergence > 1.0 ? 1.0 : $divergence;

        $depth = (int) ($input['depth'] ?? 1);
        if ($depth < 1) {
            $depth = 1;
        }
        if ($depth > self::MAX_BRANCH_DEPTH) {
            $depth = self::MAX_BRANCH_DEPTH;
        }

        $projectedPath = $this->projectPath($altKind, $altValue, $depth);

        $generatedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
        $branchId = 'cf_'.substr(hash('sha256', $anchor.'|'.$altKind.'|'.json_encode($altValue).'|'.$generatedAt), 0, 12);

        $branch = [
            'schema_version' => self::BRANCH_SCHEMA,
            'branch_id' => $branchId,
            'generated_at' => $generatedAt,
            'scope' => [
                'mission_id' => $scope['mission_id'] ?? null,
                'work_order_id' => $scope['work_order_id'] ?? null,
                'obra_id' => $scope['obra_id'] ?? null,
            ],
            'anchor_decision_id' => $anchor,
            'alternative' => [
                'decision_kind' => $altKind,
                'value' => $altValue,
            ],
            'projected_path' => $projectedPath,
            'divergence_score' => round($divergence, 4),
            'is_counterfactual' => true,
            'factual_outcome_score' => round($factual, 4),
            'projected_outcome_score' => round($projected, 4),
            'depth' => $depth,
        ];
        $branch['branch_hash'] = $this->branchHash($branch);

        AppendOnlyJsonlStore::append($this->branchesLogPath(), $branch);

        // AURG-4D integration: every counterfactual branch records a tick on the temporal chain.
        // This closes Patamar 4 hook: TEOS-I3 × AURG-4D. The tick is rationale_event kind
        // since the branch is hypothetical (is_counterfactual=true).
        if ($this->aurg !== null) {
            try {
                $aurgTick = $this->aurg->recordTick([
                    'kind' => AtlasUnifiedRealityGraphTemporalService::KIND_RATIONALE_EVENT,
                    'actor' => 'teos',
                    'rationale' => sprintf(
                        'counterfactual_branch %s anchor=%s alt=%s divergence=%.4f',
                        $branchId,
                        $anchor,
                        $altKind,
                        $divergence
                    ),
                ]);
                $branch['aurg_tick_id'] = (string) ($aurgTick['tick_id'] ?? '');
                $branch['aurg_tick_hash'] = (string) ($aurgTick['tick_hash'] ?? '');
            } catch (\Throwable $e) {
                // Defensive: do not corrupt the branch envelope if AURG write fails.
                $branch['aurg_tick_id'] = null;
                $branch['aurg_tick_hash'] = 'sha256:aurg_chain_failed';
            }
        }

        return $branch;
    }

    /**
     * Recommend a replan based on the best branch in scope.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function recommendReplan(array $input): array
    {
        $scope = (array) ($input['scope'] ?? []);
        $trigger = (string) ($input['trigger'] ?? self::TRIGGER_OPERATOR_REQUEST);
        if (! in_array($trigger, self::VALID_TRIGGERS, true)) {
            throw new InvalidArgumentException("Unknown trigger '{$trigger}'.");
        }

        $branches = $this->branchesInScope($scope);
        if ($branches === []) {
            $envelope = $this->buildRecommendationEnvelope($scope, $trigger, null, 0.0, 'low', ['no_branches_in_scope']);
            AppendOnlyJsonlStore::append($this->recommendationsLogPath(), $envelope);

            return $envelope;
        }

        // Best branch = max improvement_delta (projected - factual).
        usort($branches, static function (array $a, array $b): int {
            $da = (float) ($a['projected_outcome_score'] ?? 0) - (float) ($a['factual_outcome_score'] ?? 0);
            $db = (float) ($b['projected_outcome_score'] ?? 0) - (float) ($b['factual_outcome_score'] ?? 0);

            return $da === $db ? 0 : ($da > $db ? -1 : 1);
        });
        $best = $branches[0];
        $improvement = (float) ($best['projected_outcome_score'] ?? 0) - (float) ($best['factual_outcome_score'] ?? 0);

        $confidence = 'low';
        if ($improvement >= self::OUTCOME_IMPROVEMENT_HIGH_DELTA) {
            $confidence = 'high';
        } elseif ($improvement >= self::OUTCOME_IMPROVEMENT_MEDIUM_DELTA) {
            $confidence = 'medium';
        }

        $reason = [];
        if ($improvement <= 0) {
            $reason[] = 'no_improvement_over_factual';
        } else {
            $reason[] = sprintf('single_step_change_unlocks_%.0fpct', $improvement * 100);
        }
        if ($trigger === self::TRIGGER_BELOW_THRESHOLD) {
            $reason[] = 'below_threshold';
        }

        $envelope = $this->buildRecommendationEnvelope($scope, $trigger, $best, $improvement, $confidence, $reason);
        AppendOnlyJsonlStore::append($this->recommendationsLogPath(), $envelope);

        return $envelope;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listBranches(): array
    {
        return AppendOnlyJsonlStore::read($this->branchesLogPath());
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listRecommendations(): array
    {
        return AppendOnlyJsonlStore::read($this->recommendationsLogPath());
    }

    // ---------- internals ----------

    /**
     * @return list<array<string,mixed>>
     */
    private function branchesInScope(array $scope): array
    {
        $out = [];
        foreach ($this->listBranches() as $b) {
            $bScope = (array) ($b['scope'] ?? []);
            $matchAny = false;
            foreach (['mission_id', 'work_order_id', 'obra_id'] as $k) {
                $needle = $scope[$k] ?? null;
                if ($needle !== null && ($bScope[$k] ?? null) === $needle) {
                    $matchAny = true;
                    break;
                }
            }
            // If scope is empty -> all branches.
            $scopeNone = ($scope['mission_id'] ?? null) === null
                && ($scope['work_order_id'] ?? null) === null
                && ($scope['obra_id'] ?? null) === null;
            if ($matchAny || $scopeNone) {
                $out[] = $b;
            }
        }

        return $out;
    }

    private function buildRecommendationEnvelope(array $scope, string $trigger, ?array $best, float $improvement, string $confidence, array $reason): array
    {
        $env = [
            'schema_version' => self::RECOMMENDATION_SCHEMA,
            'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'scope' => [
                'mission_id' => $scope['mission_id'] ?? null,
                'work_order_id' => $scope['work_order_id'] ?? null,
                'obra_id' => $scope['obra_id'] ?? null,
            ],
            'trigger' => $trigger,
            'best_branch_id' => $best['branch_id'] ?? null,
            'best_branch_summary' => $best !== null
                ? sprintf(
                    '%s → projected %.2f vs factual %.2f',
                    (string) ($best['alternative']['decision_kind'] ?? ''),
                    (float) ($best['projected_outcome_score'] ?? 0),
                    (float) ($best['factual_outcome_score'] ?? 0)
                )
                : 'no branch available',
            'improvement_delta' => round($improvement, 4),
            'confidence' => $confidence,
            'actionable' => $improvement > 0 && $confidence !== 'low',
            'requires_human_approval' => true,
            'reason' => array_values(array_unique($reason)),
        ];
        $env['recommendation_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => self::RECOMMENDATION_SCHEMA,
            'scope' => $env['scope'],
            'best_branch_id' => $env['best_branch_id'],
            'improvement_delta' => $env['improvement_delta'],
        ], JSON_THROW_ON_ERROR));

        return $env;
    }

    /**
     * Build a synthetic projected path. Deterministic for the same input.
     * No content claims — labels reference the alternative kind only.
     *
     * @return list<array<string,string>>
     */
    private function projectPath(string $altKind, mixed $altValue, int $depth): array
    {
        $path = [];
        for ($i = 0; $i < $depth; $i++) {
            $path[] = [
                'node_kind' => $i === 0 ? 'decision' : ($i === $depth - 1 ? 'outcome' : 'signal'),
                'label' => sprintf('%s step %d', $altKind, $i + 1),
                'delta_vs_factual' => $i === 0 ? 'alternative chosen' : 'projected',
            ];
        }

        return $path;
    }

    private function clampScore(float $v): float
    {
        if ($v < 0.0) {
            return 0.0;
        }
        if ($v > 1.0) {
            return 1.0;
        }

        return $v;
    }

    private function branchHash(array $branch): string
    {
        $canonical = [
            'schema' => self::BRANCH_SCHEMA,
            'anchor' => $branch['anchor_decision_id'],
            'alternative' => $branch['alternative'],
            'depth' => $branch['depth'],
            'factual' => $branch['factual_outcome_score'],
            'projected' => $branch['projected_outcome_score'],
        ];

        return 'sha256:'.hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }

}
