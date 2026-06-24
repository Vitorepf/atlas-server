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

final class AtlasLoopProviderEffortPolicyDriverDecorator implements LoopExecutionDriver
{
    public function __construct(
        private readonly LoopExecutionDriver $inner,
        private readonly ?AtlasLoopProviderEffortPolicy $policy = null,
    ) {
    }

    public function attempt(
        string $surfaceId,
        string $workspace,
        string $intent,
        array $userConstraints,
        array $surfaceHints,
    ): array {
        $policy = $this->policy ?? new AtlasLoopProviderEffortPolicy;
        if (! (bool) config('atlas.loop.provider_effort_policy_enabled', false)) {
            $surfaceHints['reasoning_effort'] = $policy->defaultEffort();
            $surfaceHints['provider_effort_policy'] = [
                'schema' => AtlasLoopProviderEffortPolicy::SCHEMA,
                'enabled' => false,
                'effort' => $surfaceHints['reasoning_effort'],
            ];

            return $this->inner->attempt($surfaceId, $workspace, $intent, $userConstraints, $surfaceHints);
        }

        $decision = $policy->resolve([
            'objective_kind' => (string) ($surfaceHints['objective_kind'] ?? $this->inferObjectiveKind($intent)),
            'target_kind' => (string) ($surfaceHints['target_kind'] ?? ''),
            'attempt_index' => (int) ($surfaceHints['attempt_index'] ?? 0),
            'prior_failures' => (int) ($surfaceHints['prior_failures'] ?? 0),
            'routed_provider_tier' => (string) ($surfaceHints['routed_provider_tier'] ?? 'unknown'),
        ]);
        $surfaceHints['reasoning_effort'] = $decision['effort'];
        $surfaceHints['provider_effort_policy'] = $decision;

        return $this->inner->attempt($surfaceId, $workspace, $intent, $userConstraints, $surfaceHints);
    }

    private function inferObjectiveKind(string $intent): string
    {
        $lower = strtolower($intent);
        foreach (['verification', 'characterization', 'refactor', 'architect', 'architecture', 'merge_readiness'] as $needle) {
            if (str_contains($lower, $needle)) {
                return $needle;
            }
        }

        return 'unknown';
    }
}
