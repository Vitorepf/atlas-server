<?php

declare(strict_types=1);

namespace App\Services\Ai\Operator;

final class AskVsActCalibrationPolicy
{
    public const SCHEMA_VERSION = 'atlas.operator.ask_vs_act_calibration.v1';

    public const MIN_APPROVALS = 10;

    /** @var list<string> */
    private const LOW_RISK = ['low', 'medium'];

    /**
     * @param  array<string,mixed>  $history
     * @return array<string,mixed>
     */
    public static function resolve(string $baseDecision, array $history): array
    {
        if ($baseDecision !== 'require_confirmation') {
            return self::result($baseDecision, 'base_decision_not_loosenable', $history);
        }

        if ((int) ($history['denies'] ?? 0) > 0 || (int) ($history['reverts'] ?? 0) > 0) {
            return self::result('require_confirmation', 'deny_or_revert_seen', $history);
        }

        $n = (int) ($history['n'] ?? 0);
        $approvals = (int) ($history['approvals'] ?? 0);
        $risk = strtolower((string) ($history['risk'] ?? ''));
        if ($n >= self::MIN_APPROVALS && $approvals >= self::MIN_APPROVALS && in_array($risk, self::LOW_RISK, true)) {
            return self::result('allow_auto', 'clean_history_low_risk', $history);
        }

        return self::result('require_confirmation', 'insufficient_n_or_risk', $history);
    }

    /**
     * @param  array<string,mixed>  $history
     * @return array<string,mixed>
     */
    private static function result(string $decision, string $basis, array $history): array
    {
        $class = (string) ($history['class'] ?? 'unknown');
        $n = max(0, (int) ($history['n'] ?? 0));
        $denyRate = $n > 0 ? round(((int) ($history['denies'] ?? 0)) / $n, 4) : null;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'decision' => $decision,
            'basis' => $basis,
            'receipt' => $decision === 'allow_auto' ? "calibrated_auto:{$class}:n={$n}:deny_rate={$denyRate}" : null,
            'source' => [
                'only_reduces_prompts' => true,
                'loosens_block' => false,
                'caller_declared_decision_allowed' => false,
            ],
        ];
    }
}
