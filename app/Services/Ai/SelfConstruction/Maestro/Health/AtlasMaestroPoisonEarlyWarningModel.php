<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Health;

/**
 * Pure scoring model: evaluates a claimable packet for poison risk before muscles pull it.
 *
 * Each signal contributes a numeric score. Risk thresholds:
 *   score >= 3 → high  → retire or rescope
 *   score >= 2 → medium → hold or rescope
 *   score <  2 → low   → serve
 *
 * High-risk signals (+3 each): test_only_has_contract, forbidden_self_target,
 *   dormant_cli_arm_proxy, give_back >= 8.
 * Medium-risk signals (+2 each): contradictory_acceptance, schema_mismatch,
 *   missing_implementation_file (test file with no impl counterpart), give_back >= 4.
 * Low-risk signals (+1): missing_test_file.
 *
 * Clean packets (impl + test paired, no signals) score low with confidence=0.95.
 */
final class AtlasMaestroPoisonEarlyWarningModel
{
    public const SCHEMA = 'atlas.maestro.health.poison_early_warning.v1';

    public const RISK_HIGH = 'high';

    public const RISK_MEDIUM = 'medium';

    public const RISK_LOW = 'low';

    public const ACTION_SERVE = 'serve';

    public const ACTION_HOLD = 'hold';

    public const ACTION_RESCOPE = 'rescope';

    public const ACTION_RETIRE = 'retire';

    public const FAMILY_NONE = 'none';

    public const FAMILY_GOVERNANCE = 'governance';

    public const FAMILY_CONTRACT = 'contract';

    public const FAMILY_STRUCTURAL = 'structural';

    public const FAMILY_RELIABILITY = 'reliability';

    public const REPAIRABILITY_NOT_APPLICABLE = 'not_applicable';

    public const REPAIRABILITY_REPAIRABLE = 'repairable';

    public const REPAIRABILITY_RETIRE_ONLY = 'retire_only';

    private const SCORE_HIGH_THRESHOLD = 3;

    private const SCORE_MEDIUM_THRESHOLD = 2;

    private const GIVE_BACK_HIGH = 8;

    private const GIVE_BACK_MEDIUM = 4;

    private const SAFE_NEXT_ACTION_MAP = [
        self::ACTION_SERVE => 'serve',
        self::ACTION_HOLD => 'hold_for_review',
        self::ACTION_RESCOPE => 'auto_rescope',
        self::ACTION_RETIRE => 'auto_retire',
    ];

    /**
     * @param  array<string,mixed>  $packet
     * @return array{schema_version:string, poison_risk:string, score:int, reasons:list<string>, recommended_action:string, confidence:float, risk_family:string, repairability:string, safe_next_action:string}
     */
    public function score(array $packet): array
    {
        $facts = is_array($packet['packet_quality']['facts'] ?? null)
            ? $packet['packet_quality']['facts']
            : (is_array($packet['quality_facts'] ?? null) ? $packet['quality_facts'] : []);
        $deficiencies = array_values(array_map('strval', (array) (
            $packet['packet_quality']['blocking_deficiencies'] ?? ($packet['blocking_deficiencies'] ?? [])
        )));
        $giveBackCount = max(0, (int) ($packet['give_back_count'] ?? 0));
        $allowed = array_values(array_map('strval', (array) ($packet['allowed_files'] ?? [])));

        $totalScore = 0;
        $reasons = [];
        $retireSignals = [];
        $rescopeSignals = [];
        /** @var list<array{family:string,weight:int}> $familyWeights */
        $familyWeights = [];

        if (($facts['test_only_has_contract'] ?? false) === true) {
            $totalScore += 3;
            $reasons[] = 'test_only_has_contract';
            $rescopeSignals[] = 'test_only_has_contract';
            $familyWeights[] = ['family' => self::FAMILY_CONTRACT, 'weight' => 3];
        }

        $hasForbiddenSelfTarget = is_array($facts['forbidden_self_targets'] ?? null)
            && $facts['forbidden_self_targets'] !== [];
        if ($hasForbiddenSelfTarget || (bool) ($packet['forbidden_self_target'] ?? false)) {
            $totalScore += 3;
            $reasons[] = 'forbidden_self_target';
            $retireSignals[] = 'forbidden_self_target';
            $familyWeights[] = ['family' => self::FAMILY_GOVERNANCE, 'weight' => 3];
        }

        if (($facts['dormant_cli_arm_proxy'] ?? false) === true) {
            $totalScore += 3;
            $reasons[] = 'dormant_cli_arm_proxy';
            $retireSignals[] = 'dormant_cli_arm_proxy';
            $familyWeights[] = ['family' => self::FAMILY_GOVERNANCE, 'weight' => 3];
        }

        $contradictory = in_array('contradictory_acceptance', $deficiencies, true)
            || in_array('hidden_poison:contradictory_acceptance', $deficiencies, true)
            || (bool) ($packet['contradictory_acceptance'] ?? false);
        if ($contradictory) {
            $totalScore += 2;
            $reasons[] = 'contradictory_acceptance';
            $rescopeSignals[] = 'contradictory_acceptance';
            $familyWeights[] = ['family' => self::FAMILY_CONTRACT, 'weight' => 2];
        }

        if ($giveBackCount >= self::GIVE_BACK_HIGH) {
            $totalScore += 3;
            $reasons[] = 'repeated_give_back:'.$giveBackCount;
            $retireSignals[] = 'repeated_give_back';
            $familyWeights[] = ['family' => self::FAMILY_RELIABILITY, 'weight' => 3];
        } elseif ($giveBackCount >= self::GIVE_BACK_MEDIUM) {
            $totalScore += 2;
            $reasons[] = 'repeated_give_back:'.$giveBackCount;
            $familyWeights[] = ['family' => self::FAMILY_RELIABILITY, 'weight' => 2];
        }

        if ((bool) ($packet['schema_mismatch'] ?? false)) {
            $totalScore += 2;
            $reasons[] = 'schema_mismatch';
            $rescopeSignals[] = 'schema_mismatch';
            $familyWeights[] = ['family' => self::FAMILY_CONTRACT, 'weight' => 2];
        }

        if ($allowed !== []) {
            $hasTest = array_filter($allowed, static fn (string $f): bool => str_contains($f, 'Test.php') || str_contains($f, '/tests/')) !== [];
            $hasImpl = array_filter($allowed, static fn (string $f): bool => ! str_contains($f, 'Test.php') && ! str_contains($f, '/tests/')) !== [];

            if ($hasTest && ! $hasImpl) {
                $totalScore += 2;
                $reasons[] = 'missing_implementation_file';
                $rescopeSignals[] = 'missing_implementation_file';
                $familyWeights[] = ['family' => self::FAMILY_STRUCTURAL, 'weight' => 2];
            } elseif ($hasImpl && ! $hasTest) {
                $totalScore += 1;
                $reasons[] = 'missing_test_file';
                $familyWeights[] = ['family' => self::FAMILY_STRUCTURAL, 'weight' => 1];
            }
        }

        $risk = match (true) {
            $totalScore >= self::SCORE_HIGH_THRESHOLD => self::RISK_HIGH,
            $totalScore >= self::SCORE_MEDIUM_THRESHOLD => self::RISK_MEDIUM,
            default => self::RISK_LOW,
        };

        $action = $this->recommendAction($risk, $retireSignals, $rescopeSignals);
        $riskFamily = $this->primaryFamily($familyWeights);
        $repairability = match (true) {
            $retireSignals !== [] => self::REPAIRABILITY_RETIRE_ONLY,
            $risk === self::RISK_LOW => self::REPAIRABILITY_NOT_APPLICABLE,
            default => self::REPAIRABILITY_REPAIRABLE,
        };
        $safeNextAction = self::SAFE_NEXT_ACTION_MAP[$action] ?? 'hold_for_review';

        $signalCount = count($reasons);
        $confidence = match (true) {
            $signalCount === 0 => 0.95,
            $signalCount === 1 => 0.70,
            default => 0.85,
        };

        return [
            'schema_version' => self::SCHEMA,
            'poison_risk' => $risk,
            'score' => $totalScore,
            'reasons' => $reasons,
            'recommended_action' => $action,
            'confidence' => $confidence,
            'risk_family' => $riskFamily,
            'repairability' => $repairability,
            'safe_next_action' => $safeNextAction,
        ];
    }

    /**
     * The family of the heaviest-weighted triggered signal — the dominant poison shape for this
     * packet. Ties keep the first (highest-priority) signal evaluated. No signals => 'none'.
     *
     * @param  list<array{family:string,weight:int}>  $familyWeights
     */
    private function primaryFamily(array $familyWeights): string
    {
        if ($familyWeights === []) {
            return self::FAMILY_NONE;
        }

        $best = $familyWeights[0];
        foreach ($familyWeights as $candidate) {
            if ($candidate['weight'] > $best['weight']) {
                $best = $candidate;
            }
        }

        return $best['family'];
    }

    private function recommendAction(string $risk, array $retireSignals, array $rescopeSignals): string
    {
        if ($risk === self::RISK_LOW) {
            return self::ACTION_SERVE;
        }
        if ($retireSignals !== []) {
            return self::ACTION_RETIRE;
        }
        if ($rescopeSignals !== []) {
            return self::ACTION_RESCOPE;
        }

        return self::ACTION_HOLD;
    }
}
