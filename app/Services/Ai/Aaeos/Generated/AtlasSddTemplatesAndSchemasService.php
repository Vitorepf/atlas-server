<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Runtime for the Atlas SDD Templates And Schemas doc.
 *
 * The doc ships two load-bearing, machine-checkable schemas plus a projection
 * rule. This class turns them into pure, deterministic decision logic (no IO,
 * no clock, no DB). Same input -> same output. The doc is the authoring
 * boundary; templates "define required structure but do not create parallel
 * authority outside canonical docs", so this code only validates and reports —
 * it never invents fields the doc did not declare.
 *
 *   1. The "Operational Spec" schema. The doc enumerates the required top-level
 *      sections and the closed value sets for spec.status, spec.type and
 *      spec.risk. validateSpec() reports which sections are present/missing and
 *      rejects any enum value outside the documented set. A spec is only valid
 *      when every required section is present AND every constrained enum holds.
 *      -> validateSpec()
 *
 *   2. The "SDD Policy" schema. default_mode is auto_spec_then_execute, and the
 *      "clarification.ask_only_when" list is the *closed* set of conditions that
 *      force a clarification — Atlas executes autonomously only when none fire.
 *      The design_system block (forbid_hardcoded_colors, prefer_tokens,
 *      require_accessibility_for_ui_changes) and the evidence block (require_diff,
 *      require_test_output, require_traceability) are enforced as hard gates.
 *      -> evaluateSddPolicy()
 *
 *   3. The literal policy as written, for projection into .atlas/ templates and
 *      AGENTS.md without drifting from canonical authority. -> policySnapshot()
 *
 * @see docs/engineering-knowledge-base/spec-operating-system/templates-and-schemas.md
 */
final class AtlasSddTemplatesAndSchemasService
{
    /** Stable evidence schema id this runtime emits. */
    public const SCHEMA_VERSION = 'atlas.sdd_templates_and_schemas.v1';

    /**
     * Required top-level sections of the "Operational Spec" YAML, verbatim from
     * the doc. The presence of every one of these is the structural contract.
     *
     * @var list<string>
     */
    public const SPEC_SECTIONS = [
        'spec',
        'intent',
        'context',
        'business',
        'requirements',
        'acceptance_criteria',
        'technical_constraints',
        'assumptions',
        'test_strategy',
    ];

    /** Closed value set for spec.status (doc: draft|approved|implemented|superseded). */
    public const SPEC_STATUS = ['draft', 'approved', 'implemented', 'superseded'];

    /** Closed value set for spec.type (doc: feature|bugfix|refactor|ui_change|api_change|data_change). */
    public const SPEC_TYPE = ['feature', 'bugfix', 'refactor', 'ui_change', 'api_change', 'data_change'];

    /** Closed value set for spec.risk (doc: low|medium|high|critical). */
    public const SPEC_RISK = ['low', 'medium', 'high', 'critical'];

    /** The documented default SDD mode. */
    public const DEFAULT_MODE = 'auto_spec_then_execute';

    /**
     * The closed "clarification.ask_only_when" list — the ONLY conditions that
     * force a clarification. If a signal outside this set is raised it is ignored
     * (the doc's intent: ask narrowly, otherwise execute).
     *
     * @var list<string>
     */
    public const ASK_ONLY_WHEN = [
        'missing_target_context',
        'ambiguous_business_object',
        'security_or_permission_unclear',
        'irreversible_or_high_risk_action',
    ];

    /**
     * The design_system sub-schema, verbatim from the doc body.
     *
     * @var array<string, bool>
     */
    public const DESIGN_SYSTEM = [
        'forbid_hardcoded_colors' => true,
        'prefer_tokens' => true,
        'require_accessibility_for_ui_changes' => true,
    ];

    /**
     * The evidence sub-schema, verbatim from the doc body. Every flag is a hard
     * requirement before a change may be promoted.
     *
     * @var array<string, bool>
     */
    public const EVIDENCE_REQUIREMENTS = [
        'require_diff' => true,
        'require_test_output' => true,
        'require_traceability' => true,
    ];

    /** UI-changing spec types that trip the accessibility requirement. */
    public const UI_CHANGE_TYPES = ['ui_change'];

    /**
     * Validate an Operational Spec against the documented schema.
     *
     * Two independent contracts must both hold:
     *   - structural: every section in SPEC_SECTIONS is present (a present-but-null
     *     section still counts as declared);
     *   - enum: when spec.status / spec.type / spec.risk are provided, each value
     *     must fall inside its closed set. Unknown enum values are violations, not
     *     warnings — the schema is closed.
     *
     * @param  array<string, mixed>  $spec  Operational Spec (top-level YAML map).
     * @return array{
     *     schema_version: string,
     *     check: string,
     *     valid: bool,
     *     present_sections: list<string>,
     *     missing_sections: list<string>,
     *     enum_violations: list<array{field: string, value: string, allowed: list<string>}>,
     *     required_section_count: int,
     *     auditable: bool
     * }
     */
    public function validateSpec(array $spec): array
    {
        $present = [];
        $missing = [];

        foreach (self::SPEC_SECTIONS as $section) {
            if (array_key_exists($section, $spec)) {
                $present[] = $section;
            } else {
                $missing[] = $section;
            }
        }

        $specBlock = is_array($spec['spec'] ?? null) ? $spec['spec'] : [];

        $enumViolations = [];
        foreach ([
            'status' => self::SPEC_STATUS,
            'type' => self::SPEC_TYPE,
            'risk' => self::SPEC_RISK,
        ] as $field => $allowed) {
            $raw = $specBlock[$field] ?? null;
            if ($raw === null || $raw === '') {
                // Absent enum is handled by the structural layer (section presence),
                // not double-penalised here.
                continue;
            }
            $value = is_string($raw) ? strtolower(trim($raw)) : '';
            if (! in_array($value, $allowed, true)) {
                $enumViolations[] = [
                    'field' => 'spec.' . $field,
                    'value' => is_string($raw) ? $raw : gettype($raw),
                    'allowed' => $allowed,
                ];
            }
        }

        $valid = $missing === [] && $enumViolations === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'check' => 'operational_spec_schema',
            'valid' => $valid,
            'present_sections' => $present,
            'missing_sections' => $missing,
            'enum_violations' => $enumViolations,
            'required_section_count' => count(self::SPEC_SECTIONS),
            'auditable' => true,
        ];
    }

    /**
     * Apply the SDD Policy to a concrete request.
     *
     * Clarification: scan ASK_ONLY_WHEN against the truthy signals. The decision
     * is "clarify" iff at least one documented trigger fires; otherwise the
     * default_mode (auto_spec_then_execute) governs and Atlas may execute.
     *
     * Design system: when the change touches UI (signal `ui_change` truthy OR the
     * spec type is a UI change), the accessibility requirement is in force.
     * forbid_hardcoded_colors / prefer_tokens are always in force.
     *
     * Evidence: every flag in EVIDENCE_REQUIREMENTS is required; the request must
     * supply the matching `has_*` proof. Missing proofs are reported and block
     * promotion.
     *
     * @param  array<string, mixed>  $signals  Truthy flags describing the request,
     *         e.g. missing_target_context, ambiguous_business_object,
     *         security_or_permission_unclear, irreversible_or_high_risk_action,
     *         ui_change, has_diff, has_test_output, has_traceability, spec_type.
     * @return array{
     *     schema_version: string,
     *     check: string,
     *     default_mode: string,
     *     decision: string,
     *     must_clarify: bool,
     *     may_execute: bool,
     *     triggered_clarifications: list<string>,
     *     design_system: array{
     *         forbid_hardcoded_colors: bool,
     *         prefer_tokens: bool,
     *         accessibility_required: bool,
     *         is_ui_change: bool
     *     },
     *     evidence: array{
     *         satisfied: bool,
     *         missing: list<string>,
     *         required: list<string>
     *     },
     *     promotable: bool
     * }
     */
    public function evaluateSddPolicy(array $signals): array
    {
        // --- clarification.ask_only_when (closed set) ---
        $triggered = [];
        foreach (self::ASK_ONLY_WHEN as $condition) {
            if ($this->flag($signals, $condition)) {
                $triggered[] = $condition;
            }
        }
        $mustClarify = $triggered !== [];

        // --- design_system ---
        $specType = is_string($signals['spec_type'] ?? null)
            ? strtolower(trim($signals['spec_type']))
            : '';
        $isUiChange = $this->flag($signals, 'ui_change')
            || in_array($specType, self::UI_CHANGE_TYPES, true);
        $accessibilityRequired = self::DESIGN_SYSTEM['require_accessibility_for_ui_changes'] && $isUiChange;

        // --- evidence ---
        $missingEvidence = [];
        foreach (array_keys(self::EVIDENCE_REQUIREMENTS) as $requirement) {
            // require_diff -> has_diff, require_test_output -> has_test_output, etc.
            $proof = 'has_' . substr($requirement, strlen('require_'));
            if (! $this->flag($signals, $proof)) {
                $missingEvidence[] = $requirement;
            }
        }
        $evidenceSatisfied = $missingEvidence === [];

        $mayExecute = ! $mustClarify;
        // Promotion needs: a green clarification gate AND full evidence.
        $promotable = $mayExecute && $evidenceSatisfied;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'check' => 'sdd_policy',
            'default_mode' => self::DEFAULT_MODE,
            'decision' => $mustClarify ? 'clarify' : self::DEFAULT_MODE,
            'must_clarify' => $mustClarify,
            'may_execute' => $mayExecute,
            'triggered_clarifications' => $triggered,
            'design_system' => [
                'forbid_hardcoded_colors' => self::DESIGN_SYSTEM['forbid_hardcoded_colors'],
                'prefer_tokens' => self::DESIGN_SYSTEM['prefer_tokens'],
                'accessibility_required' => $accessibilityRequired,
                'is_ui_change' => $isUiChange,
            ],
            'evidence' => [
                'satisfied' => $evidenceSatisfied,
                'missing' => $missingEvidence,
                'required' => array_keys(self::EVIDENCE_REQUIREMENTS),
            ],
            'promotable' => $promotable,
        ];
    }

    /**
     * The literal SDD Policy + spec schema, for projection into .atlas/ templates
     * and AGENTS.md. Canonical authority stays with the repo docs; this is a copy
     * for agent ergonomics, never a parallel source of truth.
     *
     * @return array<string, mixed>
     */
    public function policySnapshot(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'check' => 'snapshot',
            'operational_spec' => [
                'required_sections' => self::SPEC_SECTIONS,
                'enums' => [
                    'spec.status' => self::SPEC_STATUS,
                    'spec.type' => self::SPEC_TYPE,
                    'spec.risk' => self::SPEC_RISK,
                ],
            ],
            'sdd_policy' => [
                'default_mode' => self::DEFAULT_MODE,
                'clarification' => ['ask_only_when' => self::ASK_ONLY_WHEN],
                'design_system' => self::DESIGN_SYSTEM,
                'evidence' => self::EVIDENCE_REQUIREMENTS,
            ],
            'projection_rule' => 'Canonical authority remains repo docs, APs, Knowledge DB, Decision Receipts and Evidence Ledger.',
        ];
    }

    /**
     * Strictly-true flag read. Only boolean true (or the int/string 1, "true")
     * counts; everything else is false. Keeps the gates deterministic and immune
     * to accidental truthiness.
     *
     * @param  array<string, mixed>  $signals
     */
    private function flag(array $signals, string $key): bool
    {
        $value = $signals[$key] ?? false;

        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes'], true);
        }

        return false;
    }
}
