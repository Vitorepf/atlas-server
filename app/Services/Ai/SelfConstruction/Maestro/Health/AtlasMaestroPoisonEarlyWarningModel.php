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

    private const SCORE_HIGH_THRESHOLD = 3;

    private const SCORE_MEDIUM_THRESHOLD = 2;

    private const GIVE_BACK_HIGH = 8;

    private const GIVE_BACK_MEDIUM = 4;

    /**
     * @param  array<string,mixed>  $packet
     * @return array{schema_version:string, poison_risk:string, score:int, reasons:list<string>, recommended_action:string, confidence:float}
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

        if (($facts['test_only_has_contract'] ?? false) === true) {
            $totalScore += 3;
            $reasons[] = 'test_only_has_contract';
            $rescopeSignals[] = 'test_only_has_contract';
        }

        $hasForbiddenSelfTarget = is_array($facts['forbidden_self_targets'] ?? null)
            && $facts['forbidden_self_targets'] !== [];
        if ($hasForbiddenSelfTarget || (bool) ($packet['forbidden_self_target'] ?? false)) {
            $totalScore += 3;
            $reasons[] = 'forbidden_self_target';
            $retireSignals[] = 'forbidden_self_target';
        }

        if (($facts['dormant_cli_arm_proxy'] ?? false) === true) {
            $totalScore += 3;
            $reasons[] = 'dormant_cli_arm_proxy';
            $retireSignals[] = 'dormant_cli_arm_proxy';
        }

        $contradictory = in_array('contradictory_acceptance', $deficiencies, true)
            || in_array('hidden_poison:contradictory_acceptance', $deficiencies, true)
            || (bool) ($packet['contradictory_acceptance'] ?? false);
        if ($contradictory) {
            $totalScore += 2;
            $reasons[] = 'contradictory_acceptance';
            $rescopeSignals[] = 'contradictory_acceptance';
        }

        if ($giveBackCount >= self::GIVE_BACK_HIGH) {
            $totalScore += 3;
            $reasons[] = 'repeated_give_back:'.$giveBackCount;
            $retireSignals[] = 'repeated_give_back';
        } elseif ($giveBackCount >= self::GIVE_BACK_MEDIUM) {
            $totalScore += 2;
            $reasons[] = 'repeated_give_back:'.$giveBackCount;
        }

        if ((bool) ($packet['schema_mismatch'] ?? false)) {
            $totalScore += 2;
            $reasons[] = 'schema_mismatch';
            $rescopeSignals[] = 'schema_mismatch';
        }

        if ($allowed !== []) {
            $hasTest = array_filter($allowed, static fn (string $f): bool => str_contains($f, 'Test.php') || str_contains($f, '/tests/')) !== [];
            $hasImpl = array_filter($allowed, static fn (string $f): bool => ! str_contains($f, 'Test.php') && ! str_contains($f, '/tests/')) !== [];

            if ($hasTest && ! $hasImpl) {
                $totalScore += 2;
                $reasons[] = 'missing_implementation_file';
                $rescopeSignals[] = 'missing_implementation_file';
            } elseif ($hasImpl && ! $hasTest) {
                $totalScore += 1;
                $reasons[] = 'missing_test_file';
            }
        }

        $risk = match (true) {
            $totalScore >= self::SCORE_HIGH_THRESHOLD => self::RISK_HIGH,
            $totalScore >= self::SCORE_MEDIUM_THRESHOLD => self::RISK_MEDIUM,
            default => self::RISK_LOW,
        };

        $action = $this->recommendAction($risk, $retireSignals, $rescopeSignals);

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
        ];
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
