<?php

namespace App\Services\Ai\Programming;

class ProgrammingPatchVerifier
{
    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function verify(array $context): array
    {
        $changedFiles = array_values(array_filter((array) ($context['changed_files'] ?? []), 'is_string'));
        $tests = array_values((array) ($context['tests'] ?? []));
        $reason = is_string($context['no_test_reason'] ?? null) ? trim((string) $context['no_test_reason']) : '';
        $manifests = array_values((array) ($context['action_manifests'] ?? []));
        $manifestChangedFiles = [];
        $risks = [];

        if ($changedFiles === []) {
            $risks[] = 'no_changed_files';
        }
        if (count($changedFiles) > 12) {
            $risks[] = 'large_diff_requires_human_review';
        }
        if ($tests === [] && $reason === '') {
            $risks[] = 'missing_tests_or_reason';
        }
        if ($manifests === []) {
            $risks[] = 'missing_action_manifests';
        }
        foreach ($manifests as $manifest) {
            if (! is_array($manifest)) {
                $risks[] = 'malformed_action_manifest';

                continue;
            }

            if (($manifest['schema_version'] ?? null) !== 'atlas.programming.action_manifest.v1') {
                $risks[] = 'malformed_action_manifest';
            }
            if (($manifest['gate_effect'] ?? null) === 'failed') {
                $risks[] = 'failed_action_manifest';
            }
            foreach ((array) ($manifest['changed_files'] ?? []) as $file) {
                if (is_string($file) && $file !== '') {
                    $manifestChangedFiles[] = $file;
                }
            }
            if ($changedFiles !== []
                && (bool) ($manifest['dry_run'] ?? false) === false
                && in_array((string) ($manifest['stage'] ?? ''), ['patch', 'repair'], true)
                && data_get($manifest, 'rollback.available') !== true
            ) {
                $risks[] = 'write_action_without_rollback';
            }
        }
        $manifestChangedFiles = array_values(array_unique($manifestChangedFiles));
        if ($changedFiles !== [] && $manifestChangedFiles === []) {
            $risks[] = 'changed_files_not_covered_by_action_manifest';
        }
        $uncoveredChangedFiles = array_values(array_diff($changedFiles, $manifestChangedFiles));
        if ($changedFiles !== [] && $manifestChangedFiles !== [] && $uncoveredChangedFiles !== []) {
            $risks[] = 'partial_action_manifest_coverage';
        }
        foreach ($changedFiles as $file) {
            if (str_contains($file, '.env') || str_contains($file, 'secrets')) {
                $risks[] = 'possible_secret_file_touched';
            }
        }

        $blocking = array_values(array_intersect($risks, [
            'missing_tests_or_reason',
            'possible_secret_file_touched',
            'missing_action_manifests',
            'malformed_action_manifest',
            'failed_action_manifest',
            'write_action_without_rollback',
            'changed_files_not_covered_by_action_manifest',
            'partial_action_manifest_coverage',
        ]));

        return [
            'schema_version' => 'atlas.programming.patch_verifier.report.v1',
            'status' => $blocking === [] ? ($risks === [] ? 'passed' : 'advisory_with_reason') : 'blocked',
            'changed_file_count' => count($changedFiles),
            'changed_files' => $changedFiles,
            'manifest_count' => count($manifests),
            'manifest_covered_files' => $manifestChangedFiles,
            'uncovered_changed_files' => $uncoveredChangedFiles ?? [],
            'risk_reasons' => array_values(array_unique($risks)),
            'blocking_reasons' => array_values(array_unique($blocking)),
            'next_action' => $blocking === [] ? 'continue' : 'repair_or_human_review',
            'completion_claim_allowed' => $blocking === [],
        ];
    }
}
