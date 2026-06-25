<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

final class AtlasLoopProviderEffortPolicy
{
    public const SCHEMA = 'atlas.loop.provider_effort.v1';

    private const EFFORTS = ['low', 'medium', 'high'];

    /**
     * @param  array<string,mixed>  $taskInput
     * @return array{schema:string,effort:string,reason:string,routed_provider_tier:string}
     */
    public function resolve(array $taskInput): array
    {
        $tier = trim((string) ($taskInput['routed_provider_tier'] ?? ''));
        $objectiveKind = strtolower(trim((string) ($taskInput['objective_kind'] ?? '')));
        $targetKind = strtolower(trim((string) ($taskInput['target_kind'] ?? '')));
        $attemptIndex = max(0, (int) ($taskInput['attempt_index'] ?? 0));
        $priorFailures = max(0, (int) ($taskInput['prior_failures'] ?? 0));

        if ($attemptIndex >= 2 || $priorFailures >= 2) {
            return $this->decision('medium', 'retry_escalation', $tier);
        }

        if ($this->isArchitectClass($objectiveKind, $targetKind)) {
            return $this->decision('high', 'architect_high', $tier);
        }

        if ($this->isMediumClass($objectiveKind, $targetKind)) {
            return $this->decision('medium', 'class_medium', $tier);
        }

        return $this->decision('low', 'default_low', $tier);
    }

    public function defaultEffort(): string
    {
        $configured = strtolower(trim((string) config('atlas.ai.hermes.default_reasoning_effort', 'medium')));

        return in_array($configured, self::EFFORTS, true) ? $configured : 'medium';
    }

    private function isArchitectClass(string $objectiveKind, string $targetKind): bool
    {
        foreach ([$objectiveKind, $targetKind] as $value) {
            if (str_contains($value, 'architect')
                || str_contains($value, 'architecture')
                || str_contains($value, 'merge_readiness')
                || str_contains($value, 'merge-readiness')
                || str_contains($value, 'certification')
            ) {
                return true;
            }
        }

        return false;
    }

    private function isMediumClass(string $objectiveKind, string $targetKind): bool
    {
        foreach ([$objectiveKind, $targetKind] as $value) {
            if (str_contains($value, 'refactor') || str_contains($value, 'extract')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{schema:string,effort:string,reason:string,routed_provider_tier:string}
     */
    private function decision(string $effort, string $reason, string $tier): array
    {
        return [
            'schema' => self::SCHEMA,
            'effort' => $effort,
            'reason' => $reason,
            'routed_provider_tier' => $tier !== '' ? $tier : 'unknown',
        ];
    }
}
