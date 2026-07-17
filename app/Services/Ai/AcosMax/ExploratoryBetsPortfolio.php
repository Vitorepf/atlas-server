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
        if (($context['enabled'] ?? false) !== true) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => self::STATUS_FLAG_DISABLED,
                'originated_candidates' => $originatedCandidates,
                'evaluated_bets' => [],
                'decisions' => [],
                'receipts' => [],
                'suspension_updates' => [],
                'source' => $source,
            ];
        }

        $k = max(0, (int) (AiValueNormalizer::finiteFloatOrNull($context['k'] ?? null) ?? self::DEFAULT_K));
        $windowId = AiValueNormalizer::trimmedStringOrNull($context['window_id']  ?? null) ?? 'current_window';
        $windowDays = max(1, (int) (AiValueNormalizer::finiteFloatOrNull($context['window_days'] ?? null) ?? self::DEFAULT_WINDOW_DAYS));
        $objectiveClass = AiValueNormalizer::trimmedStringOrNull($context['objective_class'] ?? null) ?? '';
        /** @var array<string,string> $suspendedPaths */
        $suspendedPaths = array_filter(
            AiValueNormalizer::arrayOrEmpty($context['suspended_paths'] ?? null),
            static fn ($state): bool => AiValueNormalizer::trimmedStringOrNull($state) !== null,
        );

        $bets = array_slice(self::eligibleBets($originatedCandidates), 0, $k);
        $decisions = [];
        $receipts = [];
        $suspensionUpdates = [];

        foreach ($bets as $bet) {
            $path = AiValueNormalizer::trimmedStringOrNull($bet['path'] ?? null) ?? '';
            $effect = $objectiveClass !== ''
                ? $gate->effect($path, $objectiveClass)
                : $gate->effect($path);
            $decision = self::decisionFor($bet, $effect, $suspendedPaths[$path] ?? null);
            $decisions[] = $decision;
            $receipt = self::receipt($decision, $effect, $k, $windowId, $windowDays);
            $receipts[] = $receipt;

            if (isset($decision['suspension_update'])) {
                $suspensionUpdates[] = $decision['suspension_update'];
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $bets === [] ? self::STATUS_NO_ELIGIBLE_BETS : self::STATUS_OK,
            'originated_candidates' => $originatedCandidates,
            'evaluated_bets' => $bets,
            'decisions' => $decisions,
            'receipts' => $receipts,
            'suspension_updates' => $suspensionUpdates,
            'source' => $source,
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
            $path = AiValueNormalizer::trimmedStringOrNull($candidate['path'] ?? $candidate['path_id'] ?? null) ?? '';
            if ($path === '') {
                continue;
            }
            if ((AiValueNormalizer::trimmedStringOrNull($candidate['rung'] ?? null) ?? '') !== AmbitionRungPolicy::RUNG_TASK) {
                continue;
            }
            if (array_key_exists('path_yield', $candidate) && $candidate['path_yield'] !== null) {
                continue;
            }

            $bets[] = array_merge($candidate, ['path' => $path]);
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
        $path = AiValueNormalizer::trimmedStringOrNull($bet['path'] ?? null) ?? '';
        $base = [
            'path' => $path,
            'candidate_id' => AiValueNormalizer::trimmedStringOrNull($bet['id'] ?? null) ?? $path,
            'causal_effect' => $effect,
            'path_weight_multiplier' => 1.0,
        ];

        if (($effect['admit_compounding'] ?? false) === true) {
            $action = $suspendedState === PromotionProtocol::STATE_SUSPENDED_PENDING_EVIDENCE
                ? self::ACTION_RESUME_AND_DOUBLE_DOWN
                : self::ACTION_DOUBLE_DOWN;
            $decision = array_merge($base, [
                'action' => $action,
                'state' => self::STATE_ACTIVE,
                'path_weight_multiplier' => self::DOUBLE_DOWN_MULTIPLIER,
                'basis' => $action === self::ACTION_RESUME_AND_DOUBLE_DOWN ? self::BASIS_EVIDENCE_TURNED_POSITIVE : self::BASIS_POSITIVE_CAUSAL_EFFECT,
            ]);
            if ($action === self::ACTION_RESUME_AND_DOUBLE_DOWN) {
                $decision['suspension_update'] = [
                    'path' => $path,
                    'from_state' => PromotionProtocol::STATE_SUSPENDED_PENDING_EVIDENCE,
                    'to_state' => self::STATE_ACTIVE,
                    'basis' => self::BASIS_EVIDENCE_TURNED_POSITIVE,
                ];
            }

            return $decision;
        }

        if (self::isProvenNegative($effect)) {
            return array_merge($base, [
                'action' => self::ACTION_SUSPEND,
                'state' => PromotionProtocol::STATE_SUSPENDED_PENDING_EVIDENCE,
                'basis' => self::BASIS_PROVEN_NEGATIVE_EFFECT,
                'suspension_update' => [
                    'path' => $path,
                    'from_state' => $suspendedState ?? self::STATE_EXPLORING,
                    'to_state' => PromotionProtocol::STATE_SUSPENDED_PENDING_EVIDENCE,
                    'basis' => self::BASIS_PROVEN_NEGATIVE_EFFECT,
                ],
            ]);
        }

        return array_merge($base, [
            'action' => self::ACTION_CONTINUE_EXPLORING,
            'state' => $suspendedState === PromotionProtocol::STATE_SUSPENDED_PENDING_EVIDENCE
                ? PromotionProtocol::STATE_SUSPENDED_PENDING_EVIDENCE
                : self::STATE_EXPLORING,
            'basis' => AiValueNormalizer::trimmedStringOrNull($effect['reason'] ?? null) ?? self::BASIS_UNPROVEN_EFFECT,
        ]);
    }

    /** @param array<string,mixed> $effect */
    private static function isProvenNegative(array $effect): bool
    {
        return (int) (AiValueNormalizer::finiteFloatOrNull($effect['n_treat'] ?? null) ?? 0) >= self::MIN_N
            && (int) (AiValueNormalizer::finiteFloatOrNull($effect['n_base'] ?? null) ?? 0) >= self::MIN_N
            && (AiValueNormalizer::finiteFloatOrNull($effect['ci_high'] ?? null) ?? 0.0) < 0.0;
    }

    /**
     * @param  array<string,mixed>  $decision
     * @param  array<string,mixed>  $effect
     * @return array<string,mixed>
     */
    private static function receipt(array $decision, array $effect, int $k, string $windowId, int $windowDays): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'decision_kind' => self::DECISION_KIND_CONTINUATION_GATE,
            'path' => AiValueNormalizer::trimmedStringOrNull($decision['path'] ?? null) ?? '',
            'action' => AiValueNormalizer::trimmedStringOrNull($decision['action'] ?? null) ?? '',
            'state' => AiValueNormalizer::trimmedStringOrNull($decision['state'] ?? null) ?? '',
            'basis' => AiValueNormalizer::trimmedStringOrNull($decision['basis'] ?? null) ?? '',
            'path_weight_multiplier' => AiValueNormalizer::finiteFloatOrNull($decision['path_weight_multiplier'] ?? null) ?? 0.0,
            'window_id' => $windowId,
            'window_days' => $windowDays,
            'k' => $k,
            'min_n' => self::MIN_N,
            'causal_effect' => $effect,
        ];
    }

    /** @return array<string,mixed> */
    private static function source(): array
    {
        return [
            'allocation_boundary' => 'originated_slice_sub_policy',
            'uses_atlas_brain_causal_effect_gate' => true,
            'recomputes_multk_06_allocation' => false,
            'writes_class_allocation_weights' => false,
            'counts_landing_or_acceptance' => false,
            'counts_proven_real_only' => true,
            'suspends_on_insufficient_n' => false,
            'deletes_suspended_family' => false,
            'flag' => 'atlas.loop.exploratory_bets_portfolio_enabled',
            'flag_default' => 'off',
        ];
    }
}
