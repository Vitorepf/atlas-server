<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Bridges an External Brain proposal into a muscle-ready Task Fabric spec — but only after
 * the proposal survives the same quality bar a worker-authored packet must clear. A proposal
 * that is a duplicate, a template-farm placeholder, test-only, underscoped, or has no runnable
 * acceptance is REJECTED with concrete repair reasons instead of silently becoming a detached
 * recommendation that never turns into safe queue work.
 *
 * ACCEPTED proposals emit {objective, allowed_files, acceptance_criteria, required_evidence,
 * leverage_reason} — leverage_reason is preserved verbatim so the muscle that eventually claims
 * the task knows WHY it matters, not just what to do.
 *
 * Pure PHP, deterministic, no I/O, no queue mutation.
 */
final class AtlasExternalBrainToTaskFabricBridge
{
    public const SCHEMA = 'atlas.external_brain.to_task_fabric_bridge.v1';

    /**
     * @param  array<string,mixed>  $proposal
     * @return array{schema:string, accepted:bool, task_spec:?array<string,mixed>, rejection_reasons:list<string>}
     */
    public function bridge(array $proposal): array
    {
        $reasons = [];

        $objective = trim((string) ($proposal['objective'] ?? ''));
        if ($objective === '') {
            $reasons[] = 'missing_objective';
        }

        $duplicateOf = trim((string) ($proposal['duplicate_of'] ?? ''));
        if ((bool) ($proposal['is_duplicate'] ?? false) || $duplicateOf !== '') {
            $reasons[] = $duplicateOf !== '' ? "duplicate_proposal:{$duplicateOf}" : 'duplicate_proposal';
        }

        if ((bool) ($proposal['template_farm'] ?? false) || preg_match('/\{[^}]+\}/', $objective)) {
            $reasons[] = 'template_farm_proposal';
        }

        $allowedFiles = is_array($proposal['allowed_files'] ?? null) ? array_values(array_map('strval', $proposal['allowed_files'])) : [];
        if ($allowedFiles === []) {
            $reasons[] = 'insufficient_allowed_files';
        } elseif (! $this->hasImplementationFile($allowedFiles)) {
            $reasons[] = 'test_only_proposal';
        }

        $acceptanceCriteria = is_array($proposal['acceptance_criteria'] ?? null) ? array_values(array_map('strval', $proposal['acceptance_criteria'])) : [];
        if ($acceptanceCriteria === [] || ! $this->mentionsRunnableGate($acceptanceCriteria)) {
            $reasons[] = 'non_runnable_acceptance';
        }

        $requiredEvidence = is_array($proposal['required_evidence'] ?? null) ? array_values(array_map('strval', $proposal['required_evidence'])) : [];
        if ($requiredEvidence === []) {
            $reasons[] = 'missing_required_evidence';
        }

        $leverageReason = trim((string) ($proposal['leverage_reason'] ?? ''));
        if ($leverageReason === '') {
            $reasons[] = 'missing_leverage_reason';
        }

        if ($reasons !== []) {
            return [
                'schema'            => self::SCHEMA,
                'accepted'          => false,
                'task_spec'         => null,
                'rejection_reasons' => $reasons,
            ];
        }

        return [
            'schema'    => self::SCHEMA,
            'accepted'  => true,
            'task_spec' => [
                'objective'           => $objective,
                'allowed_files'       => $allowedFiles,
                'acceptance_criteria' => $acceptanceCriteria,
                'required_evidence'   => $requiredEvidence,
                'leverage_reason'     => $leverageReason,
            ],
            'rejection_reasons' => [],
        ];
    }

    /** @param  list<string>  $allowedFiles */
    private function hasImplementationFile(array $allowedFiles): bool
    {
        foreach ($allowedFiles as $path) {
            if (! $this->isTestFile($path)) {
                return true;
            }
        }

        return false;
    }

    private function isTestFile(string $path): bool
    {
        return str_starts_with($path, 'tests/')
            || str_contains($path, '/tests/')
            || str_ends_with($path, 'Test.php')
            || str_ends_with($path, 'Spec.php');
    }

    /** @param  list<string>  $acceptanceCriteria */
    private function mentionsRunnableGate(array $acceptanceCriteria): bool
    {
        foreach ($acceptanceCriteria as $line) {
            if (preg_match('/phpunit|pint|pest|test|gate|verify|assert/i', $line)) {
                return true;
            }
        }

        return false;
    }
}
