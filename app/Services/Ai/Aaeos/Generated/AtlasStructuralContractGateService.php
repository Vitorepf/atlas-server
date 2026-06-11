<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\AtlasAaeosStringListNormalizer;

/**
 * Atlas Self-Construction Structural Contract Gate — pure, deterministic
 * documentation-first gate that decides whether a structural subsystem may
 * receive runtime, and at which step of the mandatory order.
 *
 * The gate exists because Atlas must not let any AI implement a central system
 * before the law of that system is explicit. This service is read-only and
 * advisory: it never mutates files, never approves scoped execution by itself,
 * it only emits a verdict + evidence that another agent must respect.
 *
 * Contract (from the doc "Rule", "Mandatory Order", "Minimum Contract
 * Checklist", the three subsystem gates, "Runtime Unlock Criteria",
 * "One-Line Multi-Agent Goal" and "Non-Negotiable Invariant"):
 *
 *   Entrada:
 *     subsystem          : structural subsystem id (or '' if not structural)
 *     requested_step     : step the agent is about to perform (1..9)
 *     contract           : the contract document fields actually present
 *     unlock             : the six Runtime Unlock signals actually satisfied
 *     packet_fields      : fields documented for the AI Implementation Packet
 *     splitter_fields    : fields documented for the Work Splitter
 *     validator_fields   : fields documented for the Scope Validator
 *
 *   Saida:
 *     verdict            : allow | block (fail-closed)
 *     allowed_step       : the highest step that is currently permitted (0..9)
 *     blocking           : list of blocking reasons (empty iff allow)
 *     ...evidence (checklist gaps, unlock gaps, subsystem-gate gaps)
 *
 * Documented invariants this code enforces:
 *   - "runtime implementation is forbidden until a complete contract document
 *     exists" => any code step (>=8) blocks while the checklist is incomplete.
 *   - Mandatory Order: a later step requires every earlier step complete; the
 *     gate caps the allowed step at the first unsatisfied prerequisite.
 *   - "Only then implement read-only runtime" (step 8) and "Only after
 *     validation consider scoped execution" (step 9) are the only code steps,
 *     and step 9 additionally requires step-8 read-only runtime + validation.
 *   - Runtime Unlock Criteria: read-only runtime (step 8) requires ALL six
 *     signals (contract linked, examples, failure modes, docs-health clean,
 *     architecture validate clean/reported, focused tests planned).
 *   - One-Line Multi-Agent Goal is forbidden until AI Implementation Packet,
 *     Work Splitter AND Scope Validator each have a documented contract
 *     (every required field present) and read-only validation.
 *   - Non-Negotiable Invariant: for structural subsystems, sequencing is hard;
 *     a non-structural target is admitted without the structural gate.
 *
 * @see docs/engineering-knowledge-base/self-construction/structural-contract-gate.md
 */
final class AtlasStructuralContractGateService
{
    /** Stable evidence schema id this gate emits. */
    public const SCHEMA = 'atlas.self_construction.structural_contract_gate.v1';

    public const VERDICT_ALLOW = 'allow';
    public const VERDICT_BLOCK = 'block';

    /**
     * "Structural subsystems include:" — the closed list of central systems for
     * which documentation-first sequencing is mandatory.
     *
     * @var list<string>
     */
    public const STRUCTURAL_SUBSYSTEMS = [
        'ai_implementation_packet',
        'work_splitter',
        'scope_validator',
        'evidence_ledger',
        'spec_drift_detector',
        'memory_os',
        'research_os',
        'sdd_core',
        'self_construction_runtime',
        'provider_model_decision_policy',
        'tool_mcp_write_policy',
        'autonomous_repair_or_execution_loop',
    ];

    /**
     * "Mandatory Order" — the nine ordered steps. Index = step number (1-based).
     * Steps 8 and 9 are the only ones that touch runtime code.
     *
     * @var array<int,string>
     */
    public const MANDATORY_ORDER = [
        1 => 'define_contract_document',
        2 => 'define_schemas_and_packet_fields',
        3 => 'define_invariants_and_hard_laws',
        4 => 'define_ownership_boundaries',
        5 => 'define_examples_and_failure_modes',
        6 => 'define_gates_and_evidence',
        7 => 'define_rollout_phases',
        8 => 'implement_read_only_runtime',
        9 => 'consider_scoped_execution',
    ];

    /** The first step that is "runtime code" (read-only runtime). */
    public const FIRST_CODE_STEP = 8;

    /** Scoped execution — the only step beyond read-only runtime. */
    public const SCOPED_EXECUTION_STEP = 9;

    /**
     * "Minimum Contract Checklist" — the 13 fields a structural contract must
     * define. Steps 1..7 are gated on these (a missing field caps the order).
     *
     * @var list<string>
     */
    public const CONTRACT_CHECKLIST = [
        'purpose_and_non_goals',
        'consumers_and_producers',
        'json_schema_shape',
        'allowed_files_actions',
        'forbidden_files_actions',
        'ownership_and_disjoint_write_set_rules',
        'acceptance_criteria',
        'required_gates',
        'evidence_requirements',
        'rollback_policy',
        'failure_modes',
        'completion_criteria',
        'examples_safe_and_rejected',
    ];

    /**
     * "Runtime Unlock Criteria" — ALL six must hold before read-only runtime
     * (step 8) is permitted.
     *
     * @var list<string>
     */
    public const UNLOCK_CRITERIA = [
        'contract_doc_linked_by_self_construction_os',
        'examples_present',
        'failure_modes_explicit',
        'docs_health_clean',
        'architecture_validate_clean_or_reported',
        'focused_tests_planned',
    ];

    /**
     * "AI Implementation Packet Gate" — fields docs must define before
     * implementing `--implementation-packet`.
     *
     * @var list<string>
     */
    public const PACKET_GATE_FIELDS = [
        'packet_schema',
        'task_selection_policy',
        'allowed_and_forbidden_scopes',
        'acceptance_criteria_model',
        'required_gates_and_evidence',
        'rollback_and_repair_policy',
        'one_line_prompt_consumption',
    ];

    /**
     * "Work Splitter Gate" — fields docs must define before implementing
     * `--work-splitter`.
     *
     * @var list<string>
     */
    public const SPLITTER_GATE_FIELDS = [
        'max_packets_emitted',
        'disjoint_write_set_enforcement',
        'dependency_representation',
        'hot_file_exclusion',
        'packet_assignment_or_reservation',
        'collision_risk_reporting',
    ];

    /**
     * "Scope Validator Gate" — fields docs must define before implementing
     * `--scope-validator`.
     *
     * @var list<string>
     */
    public const VALIDATOR_GATE_FIELDS = [
        'git_diff_name_only_classification',
        'untracked_file_classification',
        'allowed_forbidden_unknown_reporting',
        'blocking_violation_rules',
        'runtime_entrypoint_blocking',
        'validation_evidence_emission',
    ];

    /**
     * Evaluate one structural-subsystem operation against the gate.
     *
     * @param array<string,mixed> $operation
     *        subsystem        : string  structural subsystem id ('' if none)
     *        requested_step   : int     step 1..9 the agent is about to perform
     *        contract         : list<string>  CONTRACT_CHECKLIST fields present
     *        unlock           : list<string>  UNLOCK_CRITERIA signals satisfied
     *        packet_fields    : list<string>  PACKET_GATE_FIELDS documented
     *        splitter_fields  : list<string>  SPLITTER_GATE_FIELDS documented
     *        validator_fields : list<string>  VALIDATOR_GATE_FIELDS documented
     *
     * @return array<string,mixed> the gate verdict + evidence receipt
     */
    public function evaluate(array $operation): array
    {
        $subsystem = $this->str($operation['subsystem'] ?? null);
        $requestedStep = $this->stepNumber($operation['requested_step'] ?? null);

        $contractPresent = $this->intersectKnown($operation['contract'] ?? [], self::CONTRACT_CHECKLIST);
        $unlockPresent = $this->intersectKnown($operation['unlock'] ?? [], self::UNLOCK_CRITERIA);

        $checklistGaps = $this->missing(self::CONTRACT_CHECKLIST, $contractPresent);
        $unlockGaps = $this->missing(self::UNLOCK_CRITERIA, $unlockPresent);

        $isStructural = $subsystem !== '' && in_array($subsystem, self::STRUCTURAL_SUBSYSTEMS, true);

        // A non-structural target is not governed by this gate: the mandatory
        // sequencing applies only to structural core systems (Non-Negotiable
        // Invariant). Such an operation is admitted with allowed_step = full.
        if (! $isStructural) {
            return $this->receipt(
                verdict: self::VERDICT_ALLOW,
                subsystem: $subsystem,
                isStructural: false,
                requestedStep: $requestedStep,
                allowedStep: self::SCOPED_EXECUTION_STEP,
                blocking: [],
                checklistGaps: $checklistGaps,
                unlockGaps: $unlockGaps,
                subsystemGate: $this->subsystemGate($operation),
            );
        }

        $allowedStep = $this->highestAllowedStep($checklistGaps, $unlockGaps, $requestedStep, $operation);

        $blocking = [];
        if ($requestedStep < 1) {
            $blocking[] = 'requested_step_missing_or_invalid';
        } elseif ($requestedStep > $allowedStep) {
            // The requested step runs ahead of the documented law. Report every
            // prerequisite that holds it back so the agent knows what to write.
            $blocking = array_merge(
                $blocking,
                $this->blockReasonsForStep($requestedStep, $checklistGaps, $unlockGaps, $operation),
            );
            if ($blocking === []) {
                $blocking[] = "step_out_of_order:{$requestedStep}";
            }
        }

        $verdict = $blocking === [] ? self::VERDICT_ALLOW : self::VERDICT_BLOCK;

        return $this->receipt(
            verdict: $verdict,
            subsystem: $subsystem,
            isStructural: true,
            requestedStep: $requestedStep,
            allowedStep: $allowedStep,
            blocking: array_values(array_unique($blocking)),
            checklistGaps: $checklistGaps,
            unlockGaps: $unlockGaps,
            subsystemGate: $this->subsystemGate($operation),
        );
    }

    /**
     * "One-Line Multi-Agent Goal" — the multi-agent experience is forbidden
     * until AI Implementation Packet, Work Splitter AND Scope Validator each
     * have a complete documented contract and read-only validation.
     *
     * @param array<string,mixed> $state
     *        packet_fields    : list<string>
     *        splitter_fields  : list<string>
     *        validator_fields : list<string>
     *        read_only_validation : array{packet?:bool,work_splitter?:bool,scope_validator?:bool}
     *
     * @return array<string,mixed>
     */
    public function oneLineMultiAgentReadiness(array $state): array
    {
        $validation = is_array($state['read_only_validation'] ?? null)
            ? $state['read_only_validation']
            : [];

        $fronts = [
            'ai_implementation_packet' => [
                'fields' => self::PACKET_GATE_FIELDS,
                'present' => $this->intersectKnown($state['packet_fields'] ?? [], self::PACKET_GATE_FIELDS),
                'validated' => (bool) ($validation['packet'] ?? false),
            ],
            'work_splitter' => [
                'fields' => self::SPLITTER_GATE_FIELDS,
                'present' => $this->intersectKnown($state['splitter_fields'] ?? [], self::SPLITTER_GATE_FIELDS),
                'validated' => (bool) ($validation['work_splitter'] ?? false),
            ],
            'scope_validator' => [
                'fields' => self::VALIDATOR_GATE_FIELDS,
                'present' => $this->intersectKnown($state['validator_fields'] ?? [], self::VALIDATOR_GATE_FIELDS),
                'validated' => (bool) ($validation['scope_validator'] ?? false),
            ],
        ];

        $blocking = [];
        $fronts_report = [];
        foreach ($fronts as $id => $front) {
            $gaps = $this->missing($front['fields'], $front['present']);
            $contractComplete = $gaps === [];
            foreach ($gaps as $gap) {
                $blocking[] = "contract_incomplete:{$id}:{$gap}";
            }
            if (! $front['validated']) {
                $blocking[] = "read_only_validation_missing:{$id}";
            }
            $fronts_report[$id] = [
                'contract_complete' => $contractComplete,
                'read_only_validation' => $front['validated'],
                'missing_fields' => $gaps,
            ];
        }

        $blocking = array_values(array_unique($blocking));

        return [
            'schema_version' => self::SCHEMA,
            'capability' => 'one_line_multi_agent_goal',
            'allowed' => $blocking === [],
            'mode' => 'read_only_advisory',
            'fronts' => $fronts_report,
            'blocking' => $blocking,
            'message' => $blocking === []
                ? 'One-line multi-agent self-construction may proceed: all three gate contracts are documented and read-only validated.'
                : 'One-line multi-agent self-construction is forbidden until packet, work splitter and scope validator have complete contracts and read-only validation.',
        ];
    }

    /**
     * The highest mandatory step currently permitted given the documented
     * artifacts. Steps 1..7 are gated on the matching checklist field(s);
     * step 8 requires the full checklist + all unlock criteria; step 9 also
     * requires step-8 prerequisites (read-only runtime + validation evidence).
     *
     * @param list<string> $checklistGaps
     * @param list<string> $unlockGaps
     * @param array<string,mixed> $operation
     */
    private function highestAllowedStep(array $checklistGaps, array $unlockGaps, int $requestedStep, array $operation): int
    {
        $allowed = 0;
        for ($step = 1; $step <= self::SCOPED_EXECUTION_STEP; $step++) {
            if ($this->stepPrerequisitesMet($step, $checklistGaps, $unlockGaps, $operation)) {
                $allowed = $step;

                continue;
            }
            break;
        }

        return $allowed;
    }

    /**
     * Whether every prerequisite for $step is satisfied.
     *
     * @param list<string> $checklistGaps
     * @param list<string> $unlockGaps
     * @param array<string,mixed> $operation
     */
    private function stepPrerequisitesMet(int $step, array $checklistGaps, array $unlockGaps, array $operation): bool
    {
        return $this->blockReasonsForStep($step, $checklistGaps, $unlockGaps, $operation) === [];
    }

    /**
     * The blocking reasons that prevent $step from being performed now.
     *
     * @param list<string> $checklistGaps
     * @param list<string> $unlockGaps
     * @param array<string,mixed> $operation
     * @return list<string>
     */
    private function blockReasonsForStep(int $step, array $checklistGaps, array $unlockGaps, array $operation): array
    {
        $reasons = [];

        // Steps 1..7 require their matching checklist field(s). The doc maps the
        // ordered "define" steps onto the minimum-checklist items.
        foreach ($this->checklistFieldsForStep($step) as $field) {
            if (in_array($field, $checklistGaps, true)) {
                $reasons[] = "checklist_incomplete:{$field}";
            }
        }

        // Read-only runtime (step 8): forbidden until a COMPLETE contract exists
        // AND all six Runtime Unlock Criteria hold.
        if ($step >= self::FIRST_CODE_STEP) {
            foreach ($checklistGaps as $gap) {
                $reasons[] = "contract_incomplete:{$gap}";
            }
            foreach ($unlockGaps as $gap) {
                $reasons[] = "unlock_criteria_unmet:{$gap}";
            }
        }

        // Scoped execution (step 9): additionally requires that read-only
        // runtime exists and validation has been done ("Only after validation
        // consider scoped execution").
        if ($step >= self::SCOPED_EXECUTION_STEP) {
            if (! $this->flag($operation['read_only_runtime_implemented'] ?? null)) {
                $reasons[] = 'read_only_runtime_not_implemented';
            }
            if (! $this->flag($operation['validation_passed'] ?? null)) {
                $reasons[] = 'validation_not_passed';
            }
        }

        return array_values(array_unique($reasons));
    }

    /**
     * Maps a "define" step (1..7) onto the minimum-contract-checklist field(s)
     * that must be present for that step.
     *
     * @return list<string>
     */
    private function checklistFieldsForStep(int $step): array
    {
        return match ($step) {
            1 => ['purpose_and_non_goals', 'consumers_and_producers'],
            2 => ['json_schema_shape'],
            3 => ['acceptance_criteria', 'completion_criteria'],
            4 => ['allowed_files_actions', 'forbidden_files_actions', 'ownership_and_disjoint_write_set_rules'],
            5 => ['examples_safe_and_rejected', 'failure_modes'],
            6 => ['required_gates', 'evidence_requirements'],
            7 => ['rollback_policy'],
            default => [],
        };
    }

    /**
     * Per-subsystem gate evidence (packet / splitter / validator field gaps),
     * reported for any subsystem so callers can see what each front still owes.
     *
     * @param array<string,mixed> $operation
     * @return array<string,mixed>
     */
    private function subsystemGate(array $operation): array
    {
        $packetPresent = $this->intersectKnown($operation['packet_fields'] ?? [], self::PACKET_GATE_FIELDS);
        $splitterPresent = $this->intersectKnown($operation['splitter_fields'] ?? [], self::SPLITTER_GATE_FIELDS);
        $validatorPresent = $this->intersectKnown($operation['validator_fields'] ?? [], self::VALIDATOR_GATE_FIELDS);

        return [
            'ai_implementation_packet' => [
                'missing_fields' => $this->missing(self::PACKET_GATE_FIELDS, $packetPresent),
                'complete' => $this->missing(self::PACKET_GATE_FIELDS, $packetPresent) === [],
            ],
            'work_splitter' => [
                'missing_fields' => $this->missing(self::SPLITTER_GATE_FIELDS, $splitterPresent),
                'complete' => $this->missing(self::SPLITTER_GATE_FIELDS, $splitterPresent) === [],
            ],
            'scope_validator' => [
                'missing_fields' => $this->missing(self::VALIDATOR_GATE_FIELDS, $validatorPresent),
                'complete' => $this->missing(self::VALIDATOR_GATE_FIELDS, $validatorPresent) === [],
            ],
        ];
    }

    /**
     * Build the standard evidence receipt.
     *
     * @param list<string> $blocking
     * @param list<string> $checklistGaps
     * @param list<string> $unlockGaps
     * @param array<string,mixed> $subsystemGate
     * @return array<string,mixed>
     */
    private function receipt(
        string $verdict,
        string $subsystem,
        bool $isStructural,
        int $requestedStep,
        int $allowedStep,
        array $blocking,
        array $checklistGaps,
        array $unlockGaps,
        array $subsystemGate,
    ): array {
        return [
            'schema_version' => self::SCHEMA,
            'verdict' => $verdict,
            'mode' => 'read_only_advisory',
            'subsystem' => $subsystem,
            'is_structural' => $isStructural,
            'requested_step' => $requestedStep,
            'requested_step_label' => self::MANDATORY_ORDER[$requestedStep] ?? null,
            'allowed_step' => $allowedStep,
            'allowed_step_label' => self::MANDATORY_ORDER[$allowedStep] ?? null,
            'runtime_code_permitted' => $allowedStep >= self::FIRST_CODE_STEP,
            'contract_checklist_complete' => $checklistGaps === [],
            'contract_checklist_gaps' => $checklistGaps,
            'runtime_unlock_satisfied' => $unlockGaps === [],
            'runtime_unlock_gaps' => $unlockGaps,
            'subsystem_gates' => $subsystemGate,
            'blocking' => $blocking,
            'required_next_action' => $verdict === self::VERDICT_ALLOW
                ? 'proceed_within_allowed_step'
                : 'stop_and_complete_contract_first',
            'message' => $verdict === self::VERDICT_ALLOW
                ? 'Structural contract gate: step permitted by documented law.'
                : 'Structural contract gate: runtime forbidden — complete the contract/sequence first.',
        ];
    }

    /**
     * Keep only known members (closed-set discipline), order-stable, de-duped.
     *
     * @param mixed $values
     * @param list<string> $known
     * @return list<string>
     */
    private function intersectKnown(mixed $values, array $known): array
    {
        $list = AtlasAaeosStringListNormalizer::uniqueTrimmedStrings($values);

        return array_values(array_filter(
            $known,
            static fn (string $field): bool => in_array($field, $list, true),
        ));
    }

    /**
     * Members of $required not present in $have, order-stable.
     *
     * @param list<string> $required
     * @param list<string> $have
     * @return list<string>
     */
    private function missing(array $required, array $have): array
    {
        return array_values(array_filter(
            $required,
            static fn (string $field): bool => ! in_array($field, $have, true),
        ));
    }

    private function str(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private function stepNumber(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return (int) trim($value);
        }

        return 0;
    }

    private function flag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }
}
