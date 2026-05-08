<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApValidationEvidenceContract
{
    private const SCHEMA_VERSION = 'atlas.ap_validation_evidence_contract.v1';

    /**
     * @return array<string,mixed>
     */
    public function template(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'implemented',
            'mode' => 'read_only_validation_evidence_contract',
            'authority' => 'ap_validation_evidence_shape_only_no_command_execution',
            'required_boolean_keys' => $this->requiredBooleanKeys(),
            'required_array_keys' => ['uncovered_changed_paths'],
            'optional_array_keys' => ['commands', 'notes'],
            'template' => [
                'code_or_doc_changes_scoped' => false,
                'focused_tests_passed' => false,
                'docs_health_ok' => false,
                'architecture_validate_ok' => false,
                'git_diff_check_passed' => false,
                'ap_doc_updated' => false,
                'uncovered_changed_paths' => [],
                'uncovered_paths_reviewed' => false,
                'commands' => [],
                'notes' => [],
            ],
            'delegates_completion_status_to' => 'AtlasApCompletionChecklistContract',
            'guardrails' => [
                'writes_files' => false,
                'executes_commands' => false,
                'marks_complete' => false,
                'accepts_unknown_keys' => false,
                'requires_explicit_boolean_values' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    public function validate(array $evidence): array
    {
        $errors = [
            ...$this->requiredBooleanErrors($evidence),
            ...$this->arrayErrors($evidence),
            ...$this->unknownKeyErrors($evidence),
        ];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $errors === [] ? 'valid_shape' : 'invalid_shape',
            'mode' => 'read_only_validation_evidence_shape_check',
            'authority' => 'ap_validation_evidence_shape_only_no_command_execution',
            'error_count' => count($errors),
            'errors' => $errors,
            'normalized_summary' => [
                'boolean_key_count' => count($this->requiredBooleanKeys()),
                'uncovered_changed_path_count' => count((array) ($evidence['uncovered_changed_paths'] ?? [])),
                'command_count' => count((array) ($evidence['commands'] ?? [])),
                'note_count' => count((array) ($evidence['notes'] ?? [])),
            ],
            'next_action' => $errors === []
                ? 'pass_evidence_to_ap_completion_checklist_contract'
                : 'repair_validation_evidence_shape_before_completion_report',
            'guardrails' => $this->template()['guardrails'],
        ];
    }

    /**
     * @return array<int,string>
     */
    private function requiredBooleanKeys(): array
    {
        return [
            'code_or_doc_changes_scoped',
            'focused_tests_passed',
            'docs_health_ok',
            'architecture_validate_ok',
            'git_diff_check_passed',
            'ap_doc_updated',
        ];
    }

    /**
     * @param  array<string,mixed>  $evidence
     * @return array<int,array<string,mixed>>
     */
    private function requiredBooleanErrors(array $evidence): array
    {
        $errors = [];
        foreach ($this->requiredBooleanKeys() as $key) {
            if (! array_key_exists($key, $evidence)) {
                $errors[] = [
                    'key' => $key,
                    'reason' => 'missing_required_boolean_key',
                ];

                continue;
            }

            if (! is_bool($evidence[$key])) {
                $errors[] = [
                    'key' => $key,
                    'reason' => 'required_key_must_be_boolean',
                    'actual_type' => get_debug_type($evidence[$key]),
                ];
            }
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $evidence
     * @return array<int,array<string,mixed>>
     */
    private function arrayErrors(array $evidence): array
    {
        $errors = [];
        foreach (['uncovered_changed_paths', 'commands', 'notes'] as $key) {
            if ($key === 'uncovered_changed_paths' && ! array_key_exists($key, $evidence)) {
                $errors[] = [
                    'key' => $key,
                    'reason' => 'missing_required_array_key',
                ];

                continue;
            }

            if (array_key_exists($key, $evidence) && ! is_array($evidence[$key])) {
                $errors[] = [
                    'key' => $key,
                    'reason' => 'key_must_be_array',
                    'actual_type' => get_debug_type($evidence[$key]),
                ];
            }
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $evidence
     * @return array<int,array<string,mixed>>
     */
    private function unknownKeyErrors(array $evidence): array
    {
        $allowed = [
            ...$this->requiredBooleanKeys(),
            'uncovered_changed_paths',
            'uncovered_paths_reviewed',
            'commands',
            'notes',
        ];

        return array_values(array_map(
            fn (string $key): array => [
                'key' => $key,
                'reason' => 'unknown_validation_evidence_key',
            ],
            array_diff(array_keys($evidence), $allowed),
        ));
    }
}
