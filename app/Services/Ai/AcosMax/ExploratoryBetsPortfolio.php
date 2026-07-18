<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainCausalEffectGate;
use App\Services\Ai\Support\AiValueNormalizer;

/**
 * MULTN17-05 — exploratory bets inside the originated slice.
 *
 * MULTK-06 owns allocation BETWEEN classes. This pure policy only evaluates k
 * task-rung bets WITHIN the originated class and returns the next-window hint.
 */
final class ExploratoryBetsPortfolio
{
    public const SCHEMA_VERSION = 'atlas.originator.exploratory_bets_portfolio.v1';

    public const DEFAULT_K = 3;

    public const DEFAULT_WINDOW_DAYS = 7;

    public const MIN_N = 5;

    public const DOUBLE_DOWN_MULTIPLIER = 2.0;

    public const STATUS_FLAG_DISABLED = 'flag_disabled';

    public const FIELD_ENABLED = 'enabled';
    public const FIELD_PATH = 'path';
    public const FIELD_BASIS = 'basis';
    public const FIELD_STATE = 'state';
    public const FIELD_ACTION = 'action';
    public const FIELD_STATUS = 'status';
    public const FIELD_BETS = 'bets';
    public const FIELD_K = 'k';
    public const FIELD_WINDOW_DAYS = 'window_days';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_PATH_WEIGHT_MULTIPLIER = 'path_weight_multiplier';
    public const FIELD_ORIGINATED_CANDIDATES = 'originated_candidates';
    public const FIELD_EVALUATED_BETS = 'evaluated_bets';
    public const FIELD_DECISIONS = 'decisions';
    public const FIELD_RECEIPTS = 'receipts';
    public const FIELD_SUSPENSION_UPDATES = 'suspension_updates';
    public const FIELD_SOURCE = 'source';
    public const FIELD_CAUSAL_EFFECT = 'causal_effect';
    public const FIELD_FROM_STATE = 'from_state';
    public const FIELD_TO_STATE = 'to_state';
    public const FIELD_CANDIDATE_ID = 'candidate_id';
    public const FIELD_ADMIT_COMPOUNDING = 'admit_compounding';
    public const FIELD_ALLOCATION_BOUNDARY = 'allocation_boundary';
    public const FIELD_CI_HIGH = 'ci_high';
    public const FIELD_COUNTS_LANDING_OR_ACCEPTANCE = 'counts_landing_or_acceptance';
    public const FIELD_COUNTS_PROVEN_REAL_ONLY = 'counts_proven_real_only';
    public const FIELD_DECISION_KIND = 'decision_kind';
    public const FIELD_SUSPENSION_UPDATE = 'suspension_update';
    public const FIELD_WINDOW_ID = 'window_id';
    public const FIELD_DELETES_SUSPENDED_FAMILY = 'deletes_suspended_family';
    public const FIELD_OBJECTIVE_CLASS = 'objective_class';
    public const FIELD_SUSPENDED_PATHS = 'suspended_paths';
    public const FIELD_WRITES_CLASS_ALLOCATION_WEIGHTS = 'writes_class_allocation_weights';
    public const FIELD_USES_ATLAS_BRAIN_CAUSAL_EFFECT_GATE = 'uses_atlas_brain_causal_effect_gate';
    public const FIELD_FLAG = 'flag';
    public const FIELD_FLAG_DEFAULT = 'flag_default';
    public const FIELD_ID = 'id';
    public const FIELD_MIN_N = 'min_n';
    public const FIELD_N_BASE = 'n_base';
    public const FIELD_N_TREAT = 'n_treat';

    public const STATUS_NO_ELIGIBLE_BETS = 'no_eligible_bets';

    public const STATUS_OK = 'ok';

    public const ACTION_RESUME_AND_DOUBLE_DOWN = 'resume_and_double_down';

    public const ACTION_DOUBLE_DOWN = 'double_down';

    public const ACTION_SUSPEND = 'suspend';

    public const ACTION_CONTINUE_EXPLORING = 'continue_exploring';

    public const STATE_ACTIVE = 'active';

    public const STATE_EXPLORING = 'exploring';

    public const BASIS_EVIDENCE_TURNED_POSITIVE = 'evidence_turned_positive';

    public const BASIS_POSITIVE_CAUSAL_EFFECT = 'positive_causal_effect';

    public const BASIS_PROVEN_NEGATIVE_EFFECT = 'proven_negative_effect';

    public const BASIS_UNPROVEN_EFFECT = 'unproven_effect';

    public const DECISION_KIND_CONTINUATION_GATE = 'exploratory_bet_continuation_gate';

    /**
     * @param  list<array<string,mixed>>  $originatedCandidates
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public static function evaluate(array $originatedCandidates, AtlasBrainCausalEffectGate $gate, array $context = []): array
    {
        $source = self::source();
        if (($context[self::FIELD_ENABLED] ?? false) !== true) {
            return [
                self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
                self::FIELD_STATUS => self::STATUS_FLAG_DISABLED,
                self::FIELD_ORIGINATED_CANDIDATES => $originatedCandidates,
                self::FIELD_EVALUATED_BETS => [],
                self::FIELD_DECISIONS => [],
                self::FIELD_RECEIPTS => [],
                self::FIELD_SUSPENSION_UPDATES => [],
                self::FIELD_SOURCE => $source,
            ];
        }

        $k = max(0, (int) (AiValueNormalizer::finiteFloatOrNull($context[self::FIELD_K] ?? null) ?? self::DEFAULT_K));
        $windowId = AiValueNormalizer::trimmedStringOrNull($context[self::FIELD_WINDOW_ID]  ?? null) ?? 'current_window';
        $windowDays = max(1, (int) (AiValueNormalizer::finiteFloatOrNull($context[self::FIELD_WINDOW_DAYS] ?? null) ?? self::DEFAULT_WINDOW_DAYS));
        $objectiveClass = AiValueNormalizer::trimmedStringOrNull($context[self::FIELD_OBJECTIVE_CLASS] ?? null) ?? '';
        /** @var array<string,string> $suspendedPaths */
        $suspendedPaths = array_filter(
            AiValueNormalizer::arrayOrEmpty($context[self::FIELD_SUSPENDED_PATHS] ?? null),
            static fn ($state): bool => AiValueNormalizer::trimmedStringOrNull($state) !== null,
        );

        $bets = array_slice(self::eligibleBets($originatedCandidates), 0, $k);
        $decisions = [];
        $receipts = [];
        $suspensionUpdates = [];

        foreach ($bets as $bet) {
            $path = AiValueNormalizer::trimmedStringOrNull($bet[self::FIELD_PATH] ?? null) ?? '';
            $effect = $objectiveClass !== ''
                ? $gate->effect($path, $objectiveClass)
                : $gate->effect($path);
            $decision = self::decisionFor($bet, $effect, $suspendedPaths[$path] ?? null);
            $decisions[] = $decision;
            $receipt = self::receipt($decision, $effect, $k, $windowId, $windowDays);
            $receipts[] = $receipt;

            if (isset($decision[self::FIELD_SUSPENSION_UPDATE])) {
                $suspensionUpdates[] = $decision[self::FIELD_SUSPENSION_UPDATE];
            }
        }

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_STATUS => $bets === [] ? self::STATUS_NO_ELIGIBLE_BETS : self::STATUS_OK,
            self::FIELD_ORIGINATED_CANDIDATES => $originatedCandidates,
            self::FIELD_EVALUATED_BETS => $bets,
            self::FIELD_DECISIONS => $decisions,
            self::FIELD_RECEIPTS => $receipts,
            self::FIELD_SUSPENSION_UPDATES => $suspensionUpdates,
            self::FIELD_SOURCE => $source,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @return list<array<string,mixed>>
     */
    private static function eligibleBets(array $candidates): array
    {
        $bets = [];
        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $path = AiValueNormalizer::trimmedStringOrNull($candidate[self::FIELD_PATH] ?? $candidate['path_id'] ?? null) ?? '';
            if ($path === '') {
                continue;
            }
            if ((AiValueNormalizer::trimmedStringOrNull($candidate['rung'] ?? null) ?? '') !== AmbitionRungPolicy::RUNG_TASK) {
                continue;
            }
            if (array_key_exists('path_yield', $candidate) && $candidate['path_yield'] !== null) {
                continue;
            }

            $bets[] = array_merge($candidate, [self::FIELD_PATH => $path]);
        }

        return $bets;
    }

    /**
     * @param  array<string,mixed>  $bet
     * @param  array<string,mixed>  $effect
     * @return array<string,mixed>
     */
    private static function decisionFor(array $bet, array $effect, ?string $suspendedState): array
    {
        $path = AiValueNormalizer::trimmedStringOrNull($bet[self::FIELD_PATH] ?? null) ?? '';
        $base = [
            self::FIELD_PATH => $path,
            self::FIELD_CANDIDATE_ID => AiValueNormalizer::trimmedStringOrNull($bet[self::FIELD_ID] ?? null) ?? $path,
            self::FIELD_CAUSAL_EFFECT => $effect,
            self::FIELD_PATH_WEIGHT_MULTIPLIER => 1.0,
        ];

        if (($effect[self::FIELD_ADMIT_COMPOUNDING] ?? false) === true) {
            $action = $suspendedState === PromotionProtocol::STATE_SUSPENDED_PENDING_EVIDENCE
                ? self::ACTION_RESUME_AND_DOUBLE_DOWN
                : self::ACTION_DOUBLE_DOWN;
            $decision = array_merge($base, [
                self::FIELD_ACTION => $action,
                self::FIELD_STATE => self::STATE_ACTIVE,
                self::FIELD_PATH_WEIGHT_MULTIPLIER => self::DOUBLE_DOWN_MULTIPLIER,
                self::FIELD_BASIS => $action === self::ACTION_RESUME_AND_DOUBLE_DOWN ? self::BASIS_EVIDENCE_TURNED_POSITIVE : self::BASIS_POSITIVE_CAUSAL_EFFECT,
            ]);
            if ($action === self::ACTION_RESUME_AND_DOUBLE_DOWN) {
                $decision[self::FIELD_SUSPENSION_UPDATE] = [
                    self::FIELD_PATH => $path,
                    self::FIELD_FROM_STATE => PromotionProtocol::STATE_SUSPENDED_PENDING_EVIDENCE,
                    self::FIELD_TO_STATE => self::STATE_ACTIVE,
                    self::FIELD_BASIS => self::BASIS_EVIDENCE_TURNED_POSITIVE,
                ];
            }

            return $decision;
        }

        if (self::isProvenNegative($effect)) {
            return array_merge($base, [
                self::FIELD_ACTION => self::ACTION_SUSPEND,
                self::FIELD_STATE => PromotionProtocol::STATE_SUSPENDED_PENDING_EVIDENCE,
                self::FIELD_BASIS => self::BASIS_PROVEN_NEGATIVE_EFFECT,
                self::FIELD_SUSPENSION_UPDATE => [
                    self::FIELD_PATH => $path,
                    self::FIELD_FROM_STATE => $suspendedState ?? self::STATE_EXPLORING,
                    self::FIELD_TO_STATE => PromotionProtocol::STATE_SUSPENDED_PENDING_EVIDENCE,
                    self::FIELD_BASIS => self::BASIS_PROVEN_NEGATIVE_EFFECT,
                ],
            ]);
        }

        return array_merge($base, [
            self::FIELD_ACTION => self::ACTION_CONTINUE_EXPLORING,
            self::FIELD_STATE => $suspendedState === PromotionProtocol::STATE_SUSPENDED_PENDING_EVIDENCE
                ? PromotionProtocol::STATE_SUSPENDED_PENDING_EVIDENCE
                : self::STATE_EXPLORING,
            self::FIELD_BASIS => AiValueNormalizer::trimmedStringOrNull($effect['reason'] ?? null) ?? self::BASIS_UNPROVEN_EFFECT,
        ]);
    }

    /** @param array<string,mixed> $effect */
    private static function isProvenNegative(array $effect): bool
    {
        return (int) (AiValueNormalizer::finiteFloatOrNull($effect[self::FIELD_N_TREAT] ?? null) ?? 0) >= self::MIN_N
            && (int) (AiValueNormalizer::finiteFloatOrNull($effect[self::FIELD_N_BASE] ?? null) ?? 0) >= self::MIN_N
            && (AiValueNormalizer::finiteFloatOrNull($effect[self::FIELD_CI_HIGH] ?? null) ?? 0.0) < 0.0;
    }

    /**
     * @param  array<string,mixed>  $decision
     * @param  array<string,mixed>  $effect
     * @return array<string,mixed>
     */
    private static function receipt(array $decision, array $effect, int $k, string $windowId, int $windowDays): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_DECISION_KIND => self::DECISION_KIND_CONTINUATION_GATE,
            self::FIELD_PATH => AiValueNormalizer::trimmedStringOrNull($decision[self::FIELD_PATH] ?? null) ?? '',
            self::FIELD_ACTION => AiValueNormalizer::trimmedStringOrNull($decision[self::FIELD_ACTION] ?? null) ?? '',
            self::FIELD_STATE => AiValueNormalizer::trimmedStringOrNull($decision[self::FIELD_STATE] ?? null) ?? '',
            self::FIELD_BASIS => AiValueNormalizer::trimmedStringOrNull($decision[self::FIELD_BASIS] ?? null) ?? '',
            self::FIELD_PATH_WEIGHT_MULTIPLIER => AiValueNormalizer::finiteFloatOrNull($decision[self::FIELD_PATH_WEIGHT_MULTIPLIER] ?? null) ?? 0.0,
            self::FIELD_WINDOW_ID => $windowId,
            self::FIELD_WINDOW_DAYS => $windowDays,
            self::FIELD_K => $k,
            self::FIELD_MIN_N => self::MIN_N,
            self::FIELD_CAUSAL_EFFECT => $effect,
        ];
    }

    /** @return array<string,mixed> */
    private static function source(): array
    {
        return [
            self::FIELD_ALLOCATION_BOUNDARY => 'originated_slice_sub_policy',
            self::FIELD_USES_ATLAS_BRAIN_CAUSAL_EFFECT_GATE => true,
            'recomputes_multk_06_allocation' => false,
            self::FIELD_WRITES_CLASS_ALLOCATION_WEIGHTS => false,
            self::FIELD_COUNTS_LANDING_OR_ACCEPTANCE => false,
            self::FIELD_COUNTS_PROVEN_REAL_ONLY => true,
            'suspends_on_insufficient_n' => false,
            self::FIELD_DELETES_SUSPENDED_FAMILY => false,
            self::FIELD_FLAG => 'atlas.loop.exploratory_bets_portfolio_enabled',
            self::FIELD_FLAG_DEFAULT => 'off',
        ];
    }
}
