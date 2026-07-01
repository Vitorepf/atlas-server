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
     * Consumer-proof deletion plan: any runtime consumer, public contract consumer, or unresolved
     * dynamic consumer blocks deletion outright, regardless of how dead the code otherwise looks.
     * Even a candidate with ZERO references still needs a runnable guard test (required_tests) and
     * a rollback path before it is truly safe — dead code with no rollback path is a risk, not a
     * freebie. Only a fully proven candidate receives an executable deletion plan.
     *
     * @param  array{
     *   candidate_id?:              string,
     *   runtime_consumers?:         list<string>,
     *   public_contract_consumers?: list<string>,
     *   dynamic_consumers?:         list<string>,
     *   replacement_owner?:         string,
     *   allowed_files?:             list<string>,
     *   required_tests?:            list<string>,
     *   rollback_path?:             string,
     * }  $candidate
     * @return array<string,mixed>
     */
    public function planSafeDeletion(array $candidate): array
    {
        $candidateId = trim((string) ($candidate['candidate_id'] ?? ''));
        $runtimeConsumers = array_values(array_unique(array_map('strval', (array) ($candidate['runtime_consumers'] ?? []))));
        $publicContractConsumers = array_values(array_unique(array_map('strval', (array) ($candidate['public_contract_consumers'] ?? []))));
        $dynamicConsumers = array_values(array_unique(array_map('strval', (array) ($candidate['dynamic_consumers'] ?? []))));
        $replacementOwner = trim((string) ($candidate['replacement_owner'] ?? ''));
        $allowedFiles = array_values(array_unique(array_map('strval', (array) ($candidate['allowed_files'] ?? []))));
        $requiredTests = array_values(array_unique(array_map('strval', (array) ($candidate['required_tests'] ?? []))));
        $rollbackPath = trim((string) ($candidate['rollback_path'] ?? ''));

        $reasons = [];
        if ($runtimeConsumers !== []) {
            $reasons[] = 'runtime_consumer_present:'.implode(',', $runtimeConsumers);
        }
        if ($publicContractConsumers !== []) {
            $reasons[] = 'public_contract_consumer_present:'.implode(',', $publicContractConsumers);
        }
        if ($dynamicConsumers !== []) {
            $reasons[] = 'unresolved_dynamic_consumer_present:'.implode(',', $dynamicConsumers);
        }
        if ($reasons === [] && $replacementOwner === '') {
            $reasons[] = 'replacement_owner_missing';
        }
        if ($reasons === [] && $requiredTests === []) {
            $reasons[] = 'guard_test_missing';
        }
        if ($reasons === [] && $rollbackPath === '') {
            $reasons[] = 'rollback_path_missing';
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
        $replayGates = ['php artisan test '.implode(' ', $requiredTests)];

        $plan = [
            'action' => 'safe_delete',
            'candidate_id' => $candidateId,
            'deletion_steps' => $deletionSteps,
            'import_cleanup_steps' => $importCleanupSteps,
            'replay_gates' => $replayGates,
            'rollback_receipt_required' => true,
            'rollback_path' => $rollbackPath,
            'risk_reasons' => [],
        ];
        $plan['plan_hash'] = $this->planHash($candidateId, 'safe_delete', array_merge($deletionSteps, $importCleanupSteps, $replayGates, [$rollbackPath]));

        return $plan;
    }

    /**
     * Wrapper retirement plan (AC2 new): unlike planSafeDeletion(), a wrapper CAN have consumers —
     * the plan is only valid when it explicitly names both the replacement_target those consumers
     * move to AND the consumer_update_path describing how they get migrated. Missing either blocks
     * the retirement rather than silently deleting a wrapper still in active use.
     *
     * @param  array{
     *   candidate_id?:         string,
     *   replacement_target?:   string,
     *   consumer_update_path?: string,
     *   consumers_to_migrate?: list<string>,
     * }  $candidate
     * @return array<string,mixed>
     */
    public function planWrapperRetirement(array $candidate): array
    {
        $candidateId = trim((string) ($candidate['candidate_id'] ?? ''));
        $replacementTarget = trim((string) ($candidate['replacement_target'] ?? ''));
        $consumerUpdatePath = trim((string) ($candidate['consumer_update_path'] ?? ''));
        $consumersToMigrate = array_values(array_unique(array_map('strval', (array) ($candidate['consumers_to_migrate'] ?? []))));

        $reasons = [];
        if ($replacementTarget === '') {
            $reasons[] = 'replacement_target_missing';
        }
        if ($consumerUpdatePath === '') {
            $reasons[] = 'consumer_update_path_missing';
        }

        if ($reasons !== []) {
            return [
                'action' => 'blocked',
                'candidate_id' => $candidateId,
                'risk_reasons' => $reasons,
                'plan_hash' => $this->planHash($candidateId, 'blocked', $reasons),
            ];
        }

        $plan = [
            'action' => 'retire_wrapper',
            'candidate_id' => $candidateId,
            'replacement_target' => $replacementTarget,
            'consumer_update_path' => $consumerUpdatePath,
            'consumers_to_migrate' => $consumersToMigrate,
            'risk_reasons' => [],
        ];
        $plan['plan_hash'] = $this->planHash($candidateId, 'retire_wrapper', array_merge([$replacementTarget, $consumerUpdatePath], $consumersToMigrate));

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
