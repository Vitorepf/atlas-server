<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure normalizer. Converts raw model task proposals into strict, provider-free
 * intermediate contracts ready for the task fabric.
 *
 * Required fields (AC2 — rejection if absent or empty):
 *   objective     — non-empty string describing the task goal.
 *   allowed_files — non-empty list of file paths that may be modified.
 *   acceptance    — non-empty list of acceptance criteria strings.
 *   evidence      — non-empty list of proof requirements.
 *
 * Optional fields (defaulted to [] when absent):
 *   scope_in      — list of what is in scope.
 *   risks         — list of known risks.
 *   dependencies  — list of dependency identifiers.
 *
 * AC3 — rejection policy:
 *   A proposal is rejected when any required field is missing or empty.
 *   The normalizer NEVER invents field values; it only rejects or passes through.
 *
 * AC4 outputs: normalized_contracts, rejected_inputs, missing_fields
 *   (union of all missing fields across rejected proposals),
 *   task_fabric_ready (true when at least one contract is normalized and none rejected).
 *
 * Pure, deterministic, no providers, no I/O.
 */
final class AtlasExternalBrainOutputContractNormalizer
{
    public const SCHEMA = 'atlas.external_brain.output_contract_normalizer.v1';

    private const REQUIRED_FIELDS = ['objective', 'allowed_files', 'acceptance', 'evidence'];
    private const OPTIONAL_FIELDS = ['scope_in', 'risks', 'dependencies'];

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function normalize(array $facts): array
    {
        $proposals = is_array($facts['proposals'] ?? null) ? $facts['proposals'] : [];

        $normalizedContracts = [];
        $rejectedInputs      = [];
        $missingFieldsUnion  = [];

        foreach ($proposals as $proposal) {
            $id = (string) ($proposal['id'] ?? '');

            [$contract, $missing] = $this->tryNormalize($proposal);

            if (! empty($missing)) {
                $rejectedInputs[]    = [
                    'id'              => $id,
                    'rejection_reason' => 'missing_required_fields',
                    'missing_fields'  => $missing,
                ];
                foreach ($missing as $field) {
                    $missingFieldsUnion[$field] = true;
                }
            } else {
                $normalizedContracts[] = array_merge(['id' => $id], $contract);
            }
        }

        $taskFabricReady = ! empty($normalizedContracts) && empty($rejectedInputs);

        return [
            'schema_version'       => self::SCHEMA,
            'normalized_contracts' => $normalizedContracts,
            'rejected_inputs'      => $rejectedInputs,
            'missing_fields'       => array_values(array_keys($missingFieldsUnion)),
            'task_fabric_ready'    => $taskFabricReady,
        ];
    }

    /**
     * @return array{array<string,mixed>, list<string>}  [contract, missing]
     */
    private function tryNormalize(array $proposal): array
    {
        $missing  = [];
        $contract = [];

        // Required string: objective.
        $objective = trim((string) ($proposal['objective'] ?? ''));
        if ($objective === '') {
            $missing[] = 'objective';
        } else {
            $contract['objective'] = $objective;
        }

        // Required non-empty list: allowed_files.
        $allowedFiles = $this->toStringList($proposal['allowed_files'] ?? null);
        if (empty($allowedFiles)) {
            $missing[] = 'allowed_files';
        } else {
            $contract['allowed_files'] = $allowedFiles;
        }

        // Required non-empty list: acceptance.
        $acceptance = $this->toStringList($proposal['acceptance'] ?? null);
        if (empty($acceptance)) {
            $missing[] = 'acceptance';
        } else {
            $contract['acceptance'] = $acceptance;
        }

        // Required non-empty list: evidence.
        $evidence = $this->toStringList($proposal['evidence'] ?? null);
        if (empty($evidence)) {
            $missing[] = 'evidence';
        } else {
            $contract['evidence'] = $evidence;
        }

        // Optional fields.
        foreach (self::OPTIONAL_FIELDS as $field) {
            $contract[$field] = $this->toStringList($proposal[$field] ?? null);
        }

        return [$contract, $missing];
    }

    private function toStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $value), static fn (string $s): bool => $s !== ''));
    }
}
