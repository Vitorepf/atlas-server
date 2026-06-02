<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

final class RecallContextBudgetSplitScorer
{
    private const BASE_RATIO = 0.4;

    private const PRIOR_EPISODE_BONUS = 0.2;

    private const DEEP_CONVERSATION_BONUS = 0.15;

    private const EXPLORATORY_PENALTY = 0.2;

    private const DEEP_CONVERSATION_DEPTH = 6;

    private const RATIO_FLOOR = 0.15;

    private const RATIO_CEILING = 0.7;

    private const RISK_CAP = 0.5;

    private const EXPLORATORY_TASK_TYPES = ['research', 'planning', 'decision'];

    private const CAPPED_RISK_LEVELS = ['high', 'irreversible'];

    /**
     * @param  array<string, mixed>  $signals
     * @return array{recall_chars: int, context_chars: int, recall_ratio: float, reasons: list<string>}
     */
    public function split(array $signals): array
    {
        $totalBudget = $this->intValue($signals, 'total_budget_chars');
        $taskType = $this->stringValue($signals, 'task_type');
        $riskLevel = $this->stringValue($signals, 'risk_level');
        $conversationDepth = $this->intValue($signals, 'conversation_depth');
        $hasPriorEpisode = $this->boolValue($signals, 'has_prior_episode');

        $reasons = [];

        $ratio = self::BASE_RATIO;
        $reasons[] = 'base_ratio';

        if ($hasPriorEpisode) {
            $ratio += self::PRIOR_EPISODE_BONUS;
            $reasons[] = 'prior_episode_bonus';
        }

        if ($conversationDepth >= self::DEEP_CONVERSATION_DEPTH) {
            $ratio += self::DEEP_CONVERSATION_BONUS;
            $reasons[] = 'deep_conversation_bonus';
        }

        if (in_array($taskType, self::EXPLORATORY_TASK_TYPES, true)) {
            $ratio -= self::EXPLORATORY_PENALTY;
            $reasons[] = 'exploratory_task_penalty';
        }

        if ($ratio < self::RATIO_FLOOR) {
            $ratio = self::RATIO_FLOOR;
            $reasons[] = 'clamped_to_floor';
        } elseif ($ratio > self::RATIO_CEILING) {
            $ratio = self::RATIO_CEILING;
            $reasons[] = 'clamped_to_ceiling';
        }

        if (in_array($riskLevel, self::CAPPED_RISK_LEVELS, true) && $ratio > self::RISK_CAP) {
            $ratio = self::RISK_CAP;
            $reasons[] = 'risk_capped';
        }

        if ($totalBudget <= 0) {
            return [
                'recall_chars' => 0,
                'context_chars' => 0,
                'recall_ratio' => $ratio,
                'reasons' => $reasons,
            ];
        }

        $recallChars = (int) round($totalBudget * $ratio);
        $contextChars = $totalBudget - $recallChars;

        return [
            'recall_chars' => $recallChars,
            'context_chars' => $contextChars,
            'recall_ratio' => $ratio,
            'reasons' => $reasons,
        ];
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function intValue(array $signals, string $key): int
    {
        $value = $signals[$key] ?? 0;

        return is_int($value) ? $value : (int) $value;
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function stringValue(array $signals, string $key): string
    {
        $value = $signals[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function boolValue(array $signals, string $key): bool
    {
        return ($signals[$key] ?? false) === true;
    }
}
