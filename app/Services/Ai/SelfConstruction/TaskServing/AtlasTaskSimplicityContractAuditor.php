<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskServing;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketBuilder;

/**
 * Read-only, facts-only auditor that checks:
 *   (a) task_packet.simplicity_contract conformance against the canonical default, and
 *   (b) task spec quality: over-broad allowed_files, test-only tasks, wrapper/template-farm wording,
 *       and missing implementation targets.
 *
 * All spec checks are ADVISORY: they emit named facts and a suggested action — never block a task.
 * Returns deterministic envelopes, no scalar scoring, no side effects.
 */
final class AtlasTaskSimplicityContractAuditor
{
    public const SCHEMA = 'atlas.task_serving.simplicity_contract_audit.v1';

    public const STATUS_CONFORMING = 'conforming';

    public const STATUS_MISSING = 'missing';

    public const STATUS_DRIFTED = 'drifted';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_WARNING = 'warning';

    /** Tasks with more than this many allowed_files are flagged as over-broad. */
    public const OVER_BROAD_FILE_THRESHOLD = 15;

    /** Patterns in objective or acceptance_criteria that indicate proxy/template-farm work. */
    private const WRAPPER_PATTERNS = [
        'cosmetic wrapper',
        'template farm',
        'template_farm',
        'proxy work',
        'no-op refactor',
        'boilerplate only',
    ];

    /** Patterns indicating an unrequested new abstraction (AC2). */
    private const ABSTRACTION_PATTERNS = [
        'new interface for',
        'new abstract class',
        'introduce a factory',
        'add a new abstraction layer',
        'generic base class',
        'new wrapper class',
        'add a plugin system',
    ];

    /** Patterns indicating speculative, not-yet-needed configuration (AC2). */
    private const SPECULATIVE_CONFIG_PATTERNS = [
        'in case we need',
        'for future flexibility',
        'configurable for later',
        'feature flag for later',
        'make it configurable in case',
        'add an environment variable for future',
    ];

    public const DECISION_ACCEPT = 'accept';

    public const DECISION_SIMPLIFY = 'simplify';

    public const DECISION_SPLIT = 'split';

    public const DECISION_REJECT = 'reject';

    /**
     * @param  list<array<string,mixed>>  $records
     * @return array<string,mixed>
     */
    public function audit(array $records): array
    {
        $default = AgentControlPlaneTaskPacketBuilder::defaultSimplicityContract();
        $findings = [];
        $decisions = [];
        $inspected = 0;
        $conforming = 0;
        $missing = 0;
        $drift = 0;
        $skipped = 0;
        $specWarnings = 0;

        foreach ($records as $record) {
            if (! is_array($record) || ! isset($record['task_packet']) || ! is_array($record['task_packet'])) {
                $skipped++;
                $findings[] = $this->finding($record, self::STATUS_SKIPPED, [], 'malformed_record', 'skip_no_action');

                continue;
            }
            $inspected++;
            $packet = $record['task_packet'];
            $contract = $packet['simplicity_contract'] ?? null;

            // --- Simplicity contract conformance ---
            if (! is_array($contract)) {
                $missing++;
                $findings[] = $this->finding(
                    $record,
                    self::STATUS_MISSING,
                    array_keys($default),
                    'simplicity_contract_absent',
                    'rebuild_packet_with_default_contract',
                );
            } else {
                $driftFields = [];
                foreach ($default as $key => $expected) {
                    if (! array_key_exists($key, $contract) || $contract[$key] !== $expected) {
                        $driftFields[] = $key;
                    }
                }
                if ($driftFields === []) {
                    $conforming++;
                    $findings[] = $this->finding($record, self::STATUS_CONFORMING, [], 'matches_default_contract', 'no_action');
                } else {
                    $drift++;
                    $findings[] = $this->finding(
                        $record,
                        self::STATUS_DRIFTED,
                        $driftFields,
                        'drifted_from_default_contract',
                        'reset_drifted_fields_to_default',
                    );
                }
            }

            // --- Spec quality checks (advisory, facts-only, never block) ---

            $issues = [];

            // allowed_files checks (only when key is explicitly present).
            $fileCount = 0;
            if (array_key_exists('allowed_files', $packet)) {
                $allowedFiles = array_values((array) ($packet['allowed_files'] ?? []));
                $implFiles = array_filter($allowedFiles, static fn ($f) => is_string($f) && ! str_starts_with($f, 'tests/'));
                $fileCount = count($allowedFiles);

                if ($fileCount === 0) {
                    $specWarnings++;
                    $issues[] = 'missing_implementation_target';
                    $findings[] = $this->finding($record, self::STATUS_WARNING, [], 'missing_implementation_target', 'specify_implementation_files_in_allowed_files');
                } elseif (count($implFiles) === 0) {
                    $specWarnings++;
                    $issues[] = 'test_only_task';
                    $findings[] = $this->finding($record, self::STATUS_WARNING, [], 'test_only_task', 'add_implementation_target_to_allowed_files');
                } elseif ($fileCount > self::OVER_BROAD_FILE_THRESHOLD) {
                    $specWarnings++;
                    $issues[] = 'over_broad_allowed_files';
                    $findings[] = $this->finding($record, self::STATUS_WARNING, ['allowed_files_count:'.$fileCount], 'over_broad_allowed_files', 'split_into_smaller_tasks');
                }
            }

            // Wrapper/template-farm wording check.
            $textToScan = strtolower(
                (string) ($packet['objective'] ?? '').' '.
                implode(' ', (array) ($packet['acceptance_criteria'] ?? []))
            );
            foreach (self::WRAPPER_PATTERNS as $pattern) {
                if (str_contains($textToScan, $pattern)) {
                    $specWarnings++;
                    $issues[] = 'wrapper_wording_detected';
                    $findings[] = $this->finding($record, self::STATUS_WARNING, ['pattern:'.$pattern], 'wrapper_wording_detected', 'rewrite_objective_to_delivery_focused');
                    break; // one warning per packet
                }
            }

            // AC2: unnecessary new abstraction wording check.
            foreach (self::ABSTRACTION_PATTERNS as $pattern) {
                if (str_contains($textToScan, $pattern)) {
                    $specWarnings++;
                    $issues[] = 'unnecessary_abstraction_detected';
                    $findings[] = $this->finding($record, self::STATUS_WARNING, ['pattern:'.$pattern], 'unnecessary_abstraction_detected', 'remove_the_unrequested_abstraction_and_solve_directly');
                    break;
                }
            }

            // AC2: speculative configuration wording check.
            foreach (self::SPECULATIVE_CONFIG_PATTERNS as $pattern) {
                if (str_contains($textToScan, $pattern)) {
                    $specWarnings++;
                    $issues[] = 'speculative_config_detected';
                    $findings[] = $this->finding($record, self::STATUS_WARNING, ['pattern:'.$pattern], 'speculative_config_detected', 'drop_the_speculative_config_and_hardcode_the_current_value');
                    break;
                }
            }

            // AC3/AC4: per-record value classification and simplify/split/reject/accept decision.
            $decisions[] = $this->decide($packet, $issues);
        }

        return [
            'schema_version' => self::SCHEMA,
            'status' => 'ok',
            'inspected_count' => $inspected,
            'conforming_count' => $conforming,
            'missing_count' => $missing,
            'drift_count' => $drift,
            'skipped_count' => $skipped,
            'findings' => $findings,
            'decisions' => $decisions,
            'proof_summary' => [
                'default_contract_field_count' => count($default),
                'records_total' => count($records),
                'spec_warning_count' => $specWarnings,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $packet
     * @param  list<string>  $issues
     * @return array{task_packet_id:string, decision:string, value_classification:string, scope_reduction_advice:string}
     */
    private function decide(array $packet, array $issues): array
    {
        [$decision, $advice] = match (true) {
            in_array('wrapper_wording_detected', $issues, true) => [self::DECISION_REJECT, 'this task is proxy/wrapper-only work with no real deliverable — reject it rather than implement it'],
            in_array('missing_implementation_target', $issues, true), in_array('test_only_task', $issues, true) => [self::DECISION_REJECT, 'this task names no concrete implementation target — reject and respec with a real file before re-queuing'],
            in_array('over_broad_allowed_files', $issues, true) => [self::DECISION_SPLIT, 'split allowed_files into multiple smaller, independently-provable tasks'],
            in_array('unnecessary_abstraction_detected', $issues, true) => [self::DECISION_SIMPLIFY, 'drop the unrequested abstraction/interface/factory and implement the concrete case directly'],
            in_array('speculative_config_detected', $issues, true) => [self::DECISION_SIMPLIFY, 'drop the speculative configuration/flag and hardcode the value actually needed today'],
            default => [self::DECISION_ACCEPT, 'no scope-reduction action needed'],
        };

        $valueClassification = $issues === [] ? 'simple_high_value' : 'under_specified_low_value';

        return [
            'task_packet_id' => (string) ($packet['task_packet_id'] ?? ''),
            'decision' => $decision,
            'value_classification' => $valueClassification,
            'scope_reduction_advice' => $advice,
        ];
    }

    /**
     * @param  array<string,mixed>|mixed  $record
     * @param  list<string>  $driftFields
     * @return array<string,mixed>
     */
    private function finding(mixed $record, string $status, array $driftFields, string $findingType, string $recommendedAction): array
    {
        $packet = is_array($record) && isset($record['task_packet']) && is_array($record['task_packet']) ? $record['task_packet'] : [];

        return [
            'task_packet_id' => (string) ($packet['task_packet_id'] ?? (is_array($record) ? (string) ($record['task_packet_id'] ?? '') : '')),
            'status' => $status,
            'wave' => is_array($record) ? ($record['wave'] ?? null) : null,
            'tags' => is_array($record) ? array_values((array) ($record['tags'] ?? [])) : [],
            'finding_type' => $findingType,
            'drift_fields' => array_values($driftFields),
            'recommended_action' => $recommendedAction,
        ];
    }
}
