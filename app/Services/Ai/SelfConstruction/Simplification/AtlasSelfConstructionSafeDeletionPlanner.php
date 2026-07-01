<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Simplification;

/**
 * Turns a redundancy cluster into a delete_or_merge plan ONLY when behavior
 * equivalence, consumer coverage, a replacement owner, and rollback notes are
 * all present. Pure, deterministic, no I/O: refuses (action=blocked) rather
 * than guessing when any of the four safety facts is missing.
 */
final class AtlasSelfConstructionSafeDeletionPlanner
{
    /**
     * @param  array<string,mixed>  $cluster
     * @return array<string,mixed>
     */
    public function plan(array $cluster): array
    {
        $reasons = [];

        $behaviorEquivalenceProven = (bool) ($cluster['behavior_equivalence_proven'] ?? false);
        if (! $behaviorEquivalenceProven) {
            $reasons[] = 'behavior_equivalence_not_proven';
        }

        $consumers = array_values((array) ($cluster['consumers'] ?? []));
        $uncoveredConsumers = array_values(array_filter(
            $consumers,
            static fn ($consumer): bool => ! (bool) (is_array($consumer) ? ($consumer['covered'] ?? false) : false),
        ));
        if ($consumers === [] || $uncoveredConsumers !== []) {
            $reasons[] = 'consumer_coverage_incomplete';
        }

        $replacementOwner = trim((string) ($cluster['replacement_owner'] ?? ''));
        if ($replacementOwner === '') {
            $reasons[] = 'replacement_owner_missing';
        }

        $rollbackPlan = trim((string) ($cluster['rollback_notes'] ?? ''));
        if ($rollbackPlan === '') {
            $reasons[] = 'rollback_notes_missing';
        }

        if ($reasons !== []) {
            return [
                'action' => 'blocked',
                'risk_reasons' => $reasons,
            ];
        }

        return [
            'action' => 'delete_or_merge',
            'allowed_files' => array_values((array) ($cluster['allowed_files'] ?? [])),
            'required_tests' => array_values((array) ($cluster['required_tests'] ?? [])),
            'rollback_plan' => $rollbackPlan,
            'risk_reasons' => [],
        ];
    }

    /**
     * Consumer-proof deletion plan: any runtime consumer or public contract consumer blocks
     * deletion outright, regardless of how dead the code otherwise looks. Only truly dead,
     * replacement-covered candidates receive an executable deletion plan.
     *
     * @param  array{
     *   candidate_id?:              string,
     *   runtime_consumers?:         list<string>,
     *   public_contract_consumers?: list<string>,
     *   replacement_owner?:         string,
     *   allowed_files?:             list<string>,
     *   required_tests?:            list<string>,
     * }  $candidate
     * @return array<string,mixed>
     */
    public function planSafeDeletion(array $candidate): array
    {
        $candidateId = trim((string) ($candidate['candidate_id'] ?? ''));
        $runtimeConsumers = array_values(array_unique(array_map('strval', (array) ($candidate['runtime_consumers'] ?? []))));
        $publicContractConsumers = array_values(array_unique(array_map('strval', (array) ($candidate['public_contract_consumers'] ?? []))));
        $replacementOwner = trim((string) ($candidate['replacement_owner'] ?? ''));
        $allowedFiles = array_values(array_unique(array_map('strval', (array) ($candidate['allowed_files'] ?? []))));
        $requiredTests = array_values(array_unique(array_map('strval', (array) ($candidate['required_tests'] ?? []))));

        $reasons = [];
        if ($runtimeConsumers !== []) {
            $reasons[] = 'runtime_consumer_present:'.implode(',', $runtimeConsumers);
        }
        if ($publicContractConsumers !== []) {
            $reasons[] = 'public_contract_consumer_present:'.implode(',', $publicContractConsumers);
        }
        if ($reasons === [] && $replacementOwner === '') {
            $reasons[] = 'replacement_owner_missing';
        }

        if ($reasons !== []) {
            return [
                'action' => 'blocked',
                'candidate_id' => $candidateId,
                'risk_reasons' => $reasons,
                'plan_hash' => $this->planHash($candidateId, 'blocked', $reasons),
            ];
        }

        $deletionSteps = array_map(
            static fn (string $file): string => "delete_file:{$file}",
            $allowedFiles,
        );
        $importCleanupSteps = array_map(
            static fn (string $file): string => "remove_imports_of:{$candidateId}_from:{$file}",
            $allowedFiles,
        );
        $replayGates = $requiredTests !== []
            ? ['php artisan test '.implode(' ', $requiredTests)]
            : [];

        $plan = [
            'action' => 'safe_delete',
            'candidate_id' => $candidateId,
            'deletion_steps' => $deletionSteps,
            'import_cleanup_steps' => $importCleanupSteps,
            'replay_gates' => $replayGates,
            'rollback_receipt_required' => true,
            'risk_reasons' => [],
        ];
        $plan['plan_hash'] = $this->planHash($candidateId, 'safe_delete', array_merge($deletionSteps, $importCleanupSteps, $replayGates));

        return $plan;
    }

    /** @param  list<string>  $parts */
    private function planHash(string $candidateId, string $action, array $parts): string
    {
        sort($parts, SORT_STRING);

        return hash('sha256', json_encode(
            ['candidate_id' => $candidateId, 'action' => $action, 'parts' => $parts],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}
