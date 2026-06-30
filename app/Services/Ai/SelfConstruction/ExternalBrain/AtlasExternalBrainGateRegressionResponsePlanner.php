<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure planner. Converts critical atlas:brain:audit gate_regression findings
 * into repair-first recommendations BEFORE any ambition, frontier, or volume
 * task is accepted.
 *
 * INVARIANTS:
 *  - Any holes in the audit result → verdict='repair_first', blocked_origination=true.
 *    The repair packet targets the highest-priority hole (first in the sorted list).
 *    No volume-padding fallback is ever emitted.
 *  - Zero holes → verdict='no_regression', blocked_origination=false so normal
 *    origination can continue.
 *
 * The evidence_command tells the external brain which CLI command proves the
 * regression is fixed, making the repair packet self-verifiable.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainGateRegressionResponsePlanner
{
    public const SCHEMA = 'atlas.external_brain.gate_regression_response_planner.v1';

    public const VERDICT_REPAIR_FIRST   = 'repair_first';
    public const VERDICT_NO_REGRESSION  = 'no_regression';

    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_HIGH     = 'high';
    public const SEVERITY_MEDIUM   = 'medium';

    private const EVIDENCE_COMMAND = 'php artisan atlas:brain:audit --verify';

    // Five poison packet classes and their deterministic repair actions.
    public const POISON_CONTRADICTION               = 'contradiction';
    public const POISON_IMPLEMENTATION_FILE_MISSING = 'implementation_file_missing';
    public const POISON_FORBIDDEN_TARGET            = 'forbidden_target';
    public const POISON_STALE_DUPLICATE             = 'stale_duplicate';
    public const POISON_TEST_ONLY_SPEC              = 'test_only_spec';

    public const REPAIR_REWRITE_CRITERIA = 'rewrite_criteria';
    public const REPAIR_ADD_SCOPE        = 'add_scope';
    public const REPAIR_QUARANTINE       = 'quarantine';
    public const REPAIR_SPLIT_PACKET     = 'split_packet';
    public const REPAIR_GIVE_BACK        = 'give_back';

    /** Maps each poison class to its deterministic repair action. */
    private const POISON_REPAIR_MAP = [
        self::POISON_CONTRADICTION               => self::REPAIR_REWRITE_CRITERIA,
        self::POISON_IMPLEMENTATION_FILE_MISSING => self::REPAIR_ADD_SCOPE,
        self::POISON_FORBIDDEN_TARGET            => self::REPAIR_QUARANTINE,
        self::POISON_STALE_DUPLICATE             => self::REPAIR_SPLIT_PACKET,
        self::POISON_TEST_ONLY_SPEC              => self::REPAIR_GIVE_BACK,
    ];

    // AC3: severity sort order (higher = more critical).
    private const SEVERITY_PRIORITY = [
        self::SEVERITY_CRITICAL => 3,
        self::SEVERITY_HIGH     => 2,
        self::SEVERITY_MEDIUM   => 1,
    ];

    /**
     * @param  array{
     *   audit?: array{attacks_tried?:int, holes?:list<array{attack?:string, expected?:string, deficiencies?:list<string>, severity?:string}>},
     *   severity_override?: string|null,
     * }  $input
     * @return array<string,mixed>
     */
    public function plan(array $input): array
    {
        $audit        = is_array($input['audit'] ?? null) ? $input['audit'] : [];
        $holes        = is_array($audit['holes'] ?? null) ? $audit['holes'] : [];
        $attacksTried = (int) ($audit['attacks_tried'] ?? 0);

        if ($holes === []) {
            return [
                'schema'              => self::SCHEMA,
                'verdict'             => self::VERDICT_NO_REGRESSION,
                'regression_count'    => 0,
                'attacks_tried'       => $attacksTried,
                'blocked_origination' => false,
            ];
        }

        // AC3: sort holes by severity descending; stable (PHP 8+ usort is stable).
        $sorted = $holes;
        usort($sorted, function (array $a, array $b): int {
            $pa = self::SEVERITY_PRIORITY[$a['severity'] ?? self::SEVERITY_CRITICAL] ?? 0;
            $pb = self::SEVERITY_PRIORITY[$b['severity'] ?? self::SEVERITY_CRITICAL] ?? 0;

            return $pb <=> $pa; // descending
        });

        $topHole      = $sorted[0];
        $attack       = (string) ($topHole['attack']       ?? 'unknown_attack');
        $expected     = (string) ($topHole['expected']     ?? 'gate_must_catch_this');
        $deficiencies = (array)  ($topHole['deficiencies'] ?? []);
        $topSeverity  = (string) ($topHole['severity']     ?? self::SEVERITY_CRITICAL);
        if (! isset(self::SEVERITY_PRIORITY[$topSeverity])) {
            $topSeverity = self::SEVERITY_CRITICAL;
        }

        // severity_override may explicitly override the output label (preserved for back-compat).
        $severityOut = (string) ($input['severity_override'] ?? $topSeverity);
        if (! isset(self::SEVERITY_PRIORITY[$severityOut])) {
            $severityOut = self::SEVERITY_CRITICAL;
        }

        return [
            'schema'              => self::SCHEMA,
            'verdict'             => self::VERDICT_REPAIR_FIRST,
            'highest_severity'    => $severityOut,
            'repair_target'       => $attack,
            'expected_deficiency' => $expected,
            'deficiencies'        => $deficiencies,
            'evidence_command'    => self::EVIDENCE_COMMAND,
            'regression_count'    => count($holes),
            'attacks_tried'       => $attacksTried,
            'blocked_origination' => true,
            'sorted_holes'        => $sorted,
            'remaining_holes'     => array_slice($sorted, 1),
        ];
    }

    /**
     * Classify a raw task packet into one of the five poison classes and return
     * the deterministic repair action. Fails closed when evidence is insufficient.
     *
     * @param  array<string,mixed>  $packet
     * @return array{poison_class:string|null, repair_action:string|null, fail_closed:bool, repair_reason:string|null}
     */
    public function diagnose(array $packet): array
    {
        $allowedFiles   = (array) ($packet['allowed_files']   ?? []);
        $forbiddenFiles = (array) ($packet['forbidden_files'] ?? []);
        $deficiencies   = (array) ($packet['packet_quality']['deficiencies'] ?? []);
        $isDuplicate    = (bool)  ($packet['is_duplicate']    ?? false);

        // Fail closed: no allowed_files → cannot make a safe determination.
        if ($allowedFiles === []) {
            return [
                'poison_class'  => null,
                'repair_action' => null,
                'fail_closed'   => true,
                'repair_reason' => 'insufficient_evidence:no_allowed_files_to_inspect',
            ];
        }

        // Priority 1 — forbidden_target
        if ($forbiddenFiles !== []) {
            return $this->poisonResult(self::POISON_FORBIDDEN_TARGET, 'packet_targets_forbidden_files');
        }

        // Priority 2 — test_only_spec (explicitly flagged by quality deficiency)
        if (in_array('test_only_has_contract', $deficiencies, true)) {
            return $this->poisonResult(self::POISON_TEST_ONLY_SPEC, 'allowed_files_contains_only_test_paths');
        }

        // Priority 3 — implementation_file_missing (test files present but no impl file, no explicit flag)
        $hasTest = (bool) count(array_filter($allowedFiles, fn(string $f) => str_starts_with($f, 'tests/')));
        $hasImpl = (bool) count(array_filter($allowedFiles, fn(string $f) => ! str_starts_with($f, 'tests/')));
        if ($hasTest && ! $hasImpl) {
            return $this->poisonResult(self::POISON_IMPLEMENTATION_FILE_MISSING, 'test_files_present_but_no_implementation_file');
        }

        // Priority 4 — contradiction
        $hasContradiction = in_array('hidden_poison:contradictory_acceptance', $deficiencies, true)
            || in_array('contradictory_acceptance', $deficiencies, true);
        if ($hasContradiction) {
            return $this->poisonResult(self::POISON_CONTRADICTION, 'contradictory_acceptance_criteria_detected');
        }

        // Priority 5 — stale_duplicate
        if ($isDuplicate || in_array('stale_duplicate', $deficiencies, true)) {
            return $this->poisonResult(self::POISON_STALE_DUPLICATE, 'packet_is_stale_duplicate_of_existing_task');
        }

        return [
            'poison_class'  => null,
            'repair_action' => null,
            'fail_closed'   => false,
            'repair_reason' => null,
        ];
    }

    /** @return array{poison_class:string, repair_action:string, fail_closed:bool, repair_reason:string} */
    private function poisonResult(string $poisonClass, string $reason): array
    {
        return [
            'poison_class'  => $poisonClass,
            'repair_action' => self::POISON_REPAIR_MAP[$poisonClass],
            'fail_closed'   => true,
            'repair_reason' => $reason,
        ];
    }
}
