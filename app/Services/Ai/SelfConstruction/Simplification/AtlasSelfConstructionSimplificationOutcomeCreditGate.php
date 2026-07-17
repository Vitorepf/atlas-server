<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Simplification;

/**
 * Pure gate that credits simplification tasks only when they preserve capability
 * and reduce real maintenance or execution burden.
 *
 * Credit is granted when:
 *   - Deletion without capability loss → credits
 *   - Cosmetic reshuffle → zero credit
 *   - Actual reduction in cyclomatic complexity or file count → credits
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasSelfConstructionSimplificationOutcomeCreditGate
{
    public const SCHEMA = 'atlas.self_construction.simplification_outcome_credit_gate.v1';

    public const VERDICT_CREDITED = 'credited';
    public const VERDICT_ZERO_CREDIT = 'zero_credit';

    /**
     * @param  array<string, mixed>  $outcome
     * @return array<string, mixed>
     */
    public function evaluate(array $outcome): array
    {
        $filesDeleted = (int) ($outcome['files_deleted'] ?? 0);
        $linesRemoved = (int) ($outcome['lines_removed'] ?? 0);
        $cyclomaticReduction = (int) ($outcome['cyclomatic_reduction'] ?? 0);
        $capabilityPreserved = (bool) ($outcome['capability_preserved'] ?? false);
        $isCosmeticReshuffle = (bool) ($outcome['cosmetic_reshuffle'] ?? false);

        $reasons = [];

        // Cosmetic reshuffle → zero credit.
        if ($isCosmeticReshuffle) {
            return [
                'schema_version' => self::SCHEMA,
                'verdict' => self::VERDICT_ZERO_CREDIT,
                'credit' => 0,
                'reasons' => ['cosmetic_reshuffle_no_capability_preservation'],
            ];
        }

        // Deletion with capability preserved → credit.
        if ($filesDeleted > 0 && $capabilityPreserved) {
            $credit = $filesDeleted + ($linesRemoved > 0 ? 1 : 0);

            return [
                'schema_version' => self::SCHEMA,
                'verdict' => self::VERDICT_CREDITED,
                'credit' => $credit,
                'reasons' => ['deletion_with_capability_preserved:files='.$filesDeleted.',lines='.$linesRemoved],
            ];
        }

        // Cyclomatic reduction → credit.
        if ($cyclomaticReduction > 0 && $capabilityPreserved) {
            return [
                'schema_version' => self::SCHEMA,
                'verdict' => self::VERDICT_CREDITED,
                'credit' => $cyclomaticReduction,
                'reasons' => ['cyclomatic_reduction_with_capability_preserved:reduction='.$cyclomaticReduction],
            ];
        }

        // No meaningful reduction → zero credit.
        return [
            'schema_version' => self::SCHEMA,
            'verdict' => self::VERDICT_ZERO_CREDIT,
            'credit' => 0,
            'reasons' => ['no_meaningful_simplification_preserving_capability'],
        ];
    }

    /**
     * Elite lane credit: LOC/lines alone never credit. Requires capability_preserved
     * plus a real shrink axis (files deleted, cyclomatic, or decision-point reduction).
     * Cosmetic reshuffle and proxy faxina are zero-credit.
     *
     * @param  array<string, mixed>  $outcome
     * @return array<string, mixed>
     */
    public function evaluateElite(array $outcome): array
    {
        $base = $this->evaluate($outcome);

        $linesRemoved = (int) ($outcome['lines_removed'] ?? 0);
        $filesDeleted = (int) ($outcome['files_deleted'] ?? 0);
        $cyclomaticReduction = (int) ($outcome['cyclomatic_reduction'] ?? 0);
        $decisionPointsReduction = (int) ($outcome['decision_points_reduction'] ?? 0);
        $capabilityPreserved = (bool) ($outcome['capability_preserved'] ?? false);
        $isProxyFaxina = (bool) ($outcome['proxy_faxina'] ?? false);

        if ($isProxyFaxina) {
            return [
                'schema_version' => self::SCHEMA,
                'verdict' => self::VERDICT_ZERO_CREDIT,
                'credit' => 0,
                'elite' => true,
                'reasons' => ['proxy_faxina_forbidden_on_elite_lane'],
            ];
        }

        // LOC-only theater: lines removed without capability + structural shrink.
        if ($linesRemoved > 0
            && $filesDeleted === 0
            && $cyclomaticReduction === 0
            && $decisionPointsReduction === 0
        ) {
            return [
                'schema_version' => self::SCHEMA,
                'verdict' => self::VERDICT_ZERO_CREDIT,
                'credit' => 0,
                'elite' => true,
                'reasons' => ['loc_only_credit_forbidden'],
            ];
        }

        if (! $capabilityPreserved) {
            return [
                'schema_version' => self::SCHEMA,
                'verdict' => self::VERDICT_ZERO_CREDIT,
                'credit' => 0,
                'elite' => true,
                'reasons' => ['capability_not_preserved'],
            ];
        }

        if ($decisionPointsReduction > 0 && $base['verdict'] === self::VERDICT_ZERO_CREDIT) {
            return [
                'schema_version' => self::SCHEMA,
                'verdict' => self::VERDICT_CREDITED,
                'credit' => $decisionPointsReduction,
                'elite' => true,
                'reasons' => ['decision_points_reduction_with_capability_preserved:reduction='.$decisionPointsReduction],
            ];
        }

        $base['elite'] = true;

        return $base;
    }
}
