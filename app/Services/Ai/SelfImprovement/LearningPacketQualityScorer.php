<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfImprovement;

final class LearningPacketQualityScorer
{
    private const EVIDENCE_BONUS_PER_ITEM = 0.05;

    private const EVIDENCE_BONUS_CAP = 0.20;

    private const CONTRADICTION_PENALTY = 0.30;

    private const ROLLBACK_PENALTY = 0.25;

    private const FAILURE_PENALTY_PER_EXTRA = 0.05;

    private const FAILURE_PENALTY_CAP = 0.15;

    private const CLEAN_RULE_BONUS = 0.05;

    private const PUBLISHABLE_THRESHOLD = 0.75;

    private const PROVISIONAL_THRESHOLD = 0.45;

    /**
     * @param  array<string,mixed>  $packet
     * @return array{score: float, band: string, reasons: list<string>}
     */
    public function score(array $packet): array
    {
        $confidence = $this->floatValue($packet, 'confidence');
        $evidence = $this->listValue($packet, 'evidence_supporting_improvement');
        $failures = array_values(array_filter(
            $this->listValue($packet, 'what_failed_or_was_missing'),
            static fn (mixed $failure): bool => ! is_string($failure) || trim($failure) !== '',
        ));
        $rulePresent = $this->stringPresent($packet, 'new_rule_candidate');
        $rollbackPresent = $this->stringPresent($packet, 'rollback_recommendation');

        $evidenceCount = count($evidence);
        $failureCount = count($failures);
        $hasFailures = $failureCount > 0;

        $score = $this->clampUnit($confidence);
        $reasons = [];

        // Rule (1): +0.05 per evidence item, capped +0.20.
        if ($evidenceCount > 0) {
            $bonus = min((float) $evidenceCount * self::EVIDENCE_BONUS_PER_ITEM, self::EVIDENCE_BONUS_CAP);
            $score += $bonus;
            $reasons[] = 'evidence_bonus_applied';
        }

        // Rule (2): -0.30 when new_rule_candidate non-empty AND what_failed_or_was_missing non-empty.
        if ($rulePresent && $hasFailures) {
            $score -= self::CONTRADICTION_PENALTY;
            $reasons[] = 'rule_failure_contradiction_penalty';
        }

        // Rule (3): -0.25 when rollback_recommendation non-empty.
        if ($rollbackPresent) {
            $score -= self::ROLLBACK_PENALTY;
            $reasons[] = 'rollback_recommendation_penalty';
        }

        // Rule (4): -0.05 per failure beyond first, capped -0.15.
        if ($failureCount > 1) {
            $extraFailures = $failureCount - 1;
            $penalty = min((float) $extraFailures * self::FAILURE_PENALTY_PER_EXTRA, self::FAILURE_PENALTY_CAP);
            $score -= $penalty;
            $reasons[] = 'excess_failure_penalty';
        }

        // Rule (5): +0.05 when rule present AND zero failures.
        if ($rulePresent && ! $hasFailures) {
            $score += self::CLEAN_RULE_BONUS;
            $reasons[] = 'clean_rule_bonus';
        }

        // Rule (6): clamp 0..1 round 2dp.
        $score = round($this->clampUnit($score), 2);

        return [
            'score' => $score,
            'band' => $this->resolveBand($score, $rulePresent, $hasFailures),
            'reasons' => $reasons,
        ];
    }

    private function resolveBand(float $score, bool $rulePresent, bool $hasFailures): string
    {
        if ($score >= self::PUBLISHABLE_THRESHOLD && $rulePresent && ! $hasFailures) {
            return 'publishable';
        }

        if ($score >= self::PROVISIONAL_THRESHOLD) {
            return 'provisional';
        }

        return 'discard';
    }

    private function clampUnit(float $value): float
    {
        return min(max($value, 0.0), 1.0);
    }

    /**
     * @param  array<string,mixed>  $packet
     */
    private function floatValue(array $packet, string $key): float
    {
        $value = $packet[$key] ?? 0.0;

        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * @param  array<string,mixed>  $packet
     * @return list<mixed>
     */
    private function listValue(array $packet, string $key): array
    {
        $value = $packet[$key] ?? [];

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @param  array<string,mixed>  $packet
     */
    private function stringPresent(array $packet, string $key): bool
    {
        $value = $packet[$key] ?? null;

        return is_string($value) && trim($value) !== '';
    }
}
