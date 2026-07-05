<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Retry;

/**
 * Compiles give_back reasons into concrete spec repairs for objective, scope,
 * acceptance and evidence deficiencies.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasMaestroGiveBackToSpecRepairCompiler
{
    public const SCHEMA = 'atlas.maestro.give_back_to_spec_repair_compiler.v1';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function compile(array $input): array
    {
        $spec = is_array($input['spec'] ?? null) ? $input['spec'] : [];
        $reasons = is_array($input['give_back_reasons'] ?? null) ? $input['give_back_reasons'] : [];
        $taskPacketId = (string) ($input['task_packet_id'] ?? ($spec['task_packet_id'] ?? ''));

        $repairs = [];
        $repairedObjective = (string) ($spec['objective'] ?? '');
        $repairedAcceptance = (array) ($spec['acceptance_criteria'] ?? []);
        $repairedEvidence = (array) ($spec['required_evidence'] ?? []);
        $rejected = false;
        $repairedAllowedFiles = (array) ($spec['allowed_files'] ?? []);

        foreach ($reasons as $reason) {
            $reason = (string) $reason;
            $repair = $this->repairFor($reason);

            if ($repair === null) {
                continue;
            }

            $repairs[] = $repair;

            if ($repair['field'] === 'objective') {
                $repairedObjective = $repair['suggested_value'];
            }

            if ($repair['field'] === 'allowed_files') {
                $repairedAllowedFiles = array_values(array_unique(array_merge($repairedAllowedFiles, (array) $repair['suggested_value'])));
            }

            if ($repair['field'] === 'acceptance_criteria') {
                $repairedAcceptance = array_values(array_unique(array_merge($repairedAcceptance, (array) $repair['suggested_value'])));
            }

            if ($repair['field'] === 'required_evidence') {
                $repairedEvidence = array_values(array_unique(array_merge($repairedEvidence, (array) $repair['suggested_value'])));
            }

            if ($repair['action'] === 'reject') {
                $rejected = true;
            }
        }

        return [
            'schema_version' => self::SCHEMA,
            'task_packet_id' => $taskPacketId,
            'rejected' => $rejected,
            'repairs' => $repairs,
            'repaired_spec' => [
                'objective' => $repairedObjective,
                'allowed_files' => $repairedAllowedFiles,
                'acceptance_criteria' => $repairedAcceptance,
                'required_evidence' => $repairedEvidence,
            ],
            'repair_count' => count($repairs),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function repairFor(string $reason): ?array
    {
        $lower = strtolower($reason);

        return match (true) {
            str_contains($lower, 'objective') || str_contains($lower, 'unclear objective') => [
                'field' => 'objective',
                'action' => 'rewrite',
                'reason' => $reason,
                'suggested_value' => 'Clarify the objective with a single concrete deliverable and success signal.',
            ],
            str_contains($lower, 'scope') || str_contains($lower, 'allowed_files') => [
                'field' => 'allowed_files',
                'action' => 'expand',
                'reason' => $reason,
                'suggested_value' => 'Add the missing implementation and test files referenced by the acceptance criteria.',
            ],
            str_contains($lower, 'acceptance') || str_contains($lower, 'criteria') => [
                'field' => 'acceptance_criteria',
                'action' => 'rewrite',
                'reason' => $reason,
                'suggested_value' => [
                    'Provide a runnable command or assertion that proves the implementation.',
                    'Bind each criterion to a specific allowed file or test class.',
                ],
            ],
            str_contains($lower, 'evidence') || str_contains($lower, 'proof') => [
                'field' => 'required_evidence',
                'action' => 'add',
                'reason' => $reason,
                'suggested_value' => ['tests_or_gates_result', 'implementation_notes'],
            ],
            str_contains($lower, 'duplicate') || str_contains($lower, 'already_satisfied') => [
                'field' => 'objective',
                'action' => 'reject',
                'reason' => $reason,
                'suggested_value' => 'quarantine_as_duplicate_or_noop',
            ],
            str_contains($lower, 'forbidden') || str_contains($lower, 'contradiction') => [
                'field' => 'objective',
                'action' => 'reject',
                'reason' => $reason,
                'suggested_value' => 'quarantine_as_unsafe',
            ],
            default => null,
        };
    }
}
