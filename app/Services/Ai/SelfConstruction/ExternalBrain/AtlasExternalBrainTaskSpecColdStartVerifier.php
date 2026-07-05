<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure verifier that checks whether a cold muscle can implement a task spec
 * using only objective, allowed_files, acceptance and required evidence.
 *
 * A spec passes cold-start verification when:
 *   - objective is non-empty and substantive (>= 10 chars)
 *   - allowed_files is non-empty and all paths are bound (not wildcards)
 *   - acceptance_criteria includes at least one runnable proof (test path or filter)
 *   - required_evidence is non-empty
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainTaskSpecColdStartVerifier
{
    public const SCHEMA = 'atlas.external_brain.task_spec_cold_start_verifier.v1';

    private const MIN_OBJECTIVE_LENGTH = 10;

    private const RUNNABLE_PROOF_MARKERS = ['phpunit', 'artisan test', 'pytest', 'jest', 'rspec'];

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    public function verify(array $spec): array
    {
        $objective = trim((string) ($spec['objective'] ?? ''));
        $allowedFiles = (array) ($spec['allowed_files'] ?? []);
        $acceptanceCriteria = (array) ($spec['acceptance_criteria'] ?? []);
        $requiredEvidence = (array) ($spec['required_evidence'] ?? []);

        $failures = [];

        // Check objective.
        if ($objective === '') {
            $failures[] = 'missing_objective';
        } elseif (mb_strlen($objective) < self::MIN_OBJECTIVE_LENGTH) {
            $failures[] = 'objective_too_short';
        }

        // Check allowed_files.
        if ($allowedFiles === []) {
            $failures[] = 'missing_allowed_files';
        } else {
            foreach ($allowedFiles as $file) {
                $file = trim((string) $file);
                if ($file === '') {
                    $failures[] = 'empty_allowed_file_path';
                } elseif (str_contains($file, '*') || str_contains($file, '?')) {
                    $failures[] = 'unbound_wildcard_allowed_file:'.$file;
                }
            }
        }

        // Check acceptance_criteria for runnable proof.
        $hasRunnableProof = false;
        foreach ($acceptanceCriteria as $criterion) {
            $text = strtolower(trim((string) $criterion));
            foreach (self::RUNNABLE_PROOF_MARKERS as $marker) {
                if (str_contains($text, $marker)) {
                    $hasRunnableProof = true;
                    break 2;
                }
            }
        }
        if (! $hasRunnableProof) {
            $failures[] = 'no_runnable_acceptance_proof';
        }

        // Check required_evidence.
        if ($requiredEvidence === []) {
            $failures[] = 'missing_required_evidence';
        }

        $passed = $failures === [];

        return [
            'schema_version' => self::SCHEMA,
            'passed' => $passed,
            'failures' => $failures,
            'objective_length' => mb_strlen($objective),
            'allowed_files_count' => count($allowedFiles),
            'acceptance_criteria_count' => count($acceptanceCriteria),
            'required_evidence_count' => count($requiredEvidence),
            'has_runnable_proof' => $hasRunnableProof,
        ];
    }
}
