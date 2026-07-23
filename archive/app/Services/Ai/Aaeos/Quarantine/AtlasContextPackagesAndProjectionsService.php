<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas Context Packages And Projections — pure, deterministic runtime for the
 * SDD context-packaging and local-projection contract.
 *
 * The doc declares three concrete contracts; this service enforces each one as a
 * pure function (no DB, no IO, mutates nothing):
 *
 *  1. Context Package validity ("Each package must define …"): a versioned
 *     context package is valid ONLY when it defines all ten required sections —
 *     purpose_and_scope, authority_source, instructions, good_examples,
 *     bad_examples, commands_and_gates, common_mistakes, evaluation_criteria,
 *     compatible_domains and version_and_supersession. A package missing any of
 *     these is invalid and may not be used to brief an agent.
 *
 *  2. Package Selection ("Context Builder selects packages from …"): given an
 *     operation signal (stack, active domain, changed file types, risk, current
 *     spec/receipt) the Context Builder returns the relevant package set. The doc
 *     pins one worked example — a React + Laravel UI save action must select
 *     react-typescript-v1, atlas-design-system-v2, laravel-api-v1,
 *     testing-standard-v3, security-policy-v1 and decision-receipt-v1.
 *
 *  3. Projection Law ("Canonical docs, APs, Kernel and receipts outrank .atlas
 *     projection files"): a local .atlas projection NEVER outranks canonical
 *     sources, must declare its generation source and timestamp, must be checked
 *     for drift, must be re-verified against canonical docs before risky
 *     execution, and must not silently add permissions, tools or autonomy. This
 *     service computes the precedence + admissibility verdict for a projection
 *     file relative to a canonical source.
 *
 * @see docs/engineering-knowledge-base/spec-operating-system/context-packages-and-projections.md
 */
final class AtlasContextPackagesAndProjectionsService
{
    /** Stable schema id this runtime emits. */
    public const SCHEMA = 'atlas.context_packages_and_projections.v1';

    /**
     * The ten sections every context package MUST define, in documented order
     * (the "Each package must define" list). A package missing any is invalid.
     *
     * @var list<string>
     */
    public const REQUIRED_PACKAGE_FIELDS = [
        'purpose_and_scope',
        'authority_source',
        'instructions',
        'good_examples',
        'bad_examples',
        'commands_and_gates',
        'common_mistakes',
        'evaluation_criteria',
        'compatible_domains',
        'version_and_supersession',
    ];

    /**
     * The five Projection Law rules, as stable rule ids (the "Projection Law"
     * section). Used as the closed law read model and to label verdict reasons.
     *
     * @var array<string,string>
     */
    public const PROJECTION_LAW = [
        'canonical_outranks_projection' => 'Canonical docs, APs, Kernel and receipts outrank .atlas projection files.',
        'must_declare_source_and_timestamp' => 'Projection files must declare generation source and timestamp.',
        'drift_must_be_detected' => 'Projection drift must be detected by docs-health or dedicated projection audit.',
        'verify_canonical_before_risky_execution' => 'External agents may read projections, but Atlas must verify against canonical docs before risky execution.',
        'no_silent_permission_escalation' => 'Projection files must not silently add permissions, tools or autonomy.',
    ];

    /**
     * The documented worked example: a React + Laravel UI save action selects
     * exactly these packages, in the doc's listed order.
     *
     * @var list<string>
     */
    public const REACT_LARAVEL_UI_SAVE_PACKAGES = [
        'react-typescript-v1',
        'atlas-design-system-v2',
        'laravel-api-v1',
        'testing-standard-v3',
        'security-policy-v1',
        'decision-receipt-v1',
    ];

    /** Risk levels at which a projection must be re-verified against canon before execution. */
    private const RISKY_EXECUTION_LEVELS = ['medium', 'high', 'critical', 'irreversible'];

    /** Verdicts for the projection-admissibility check (closed set). */
    public const PROJECTION_ADMIT = 'admit';
    public const PROJECTION_REJECT = 'reject';

    /**
     * Validate a single context package against the documented ten-section shape.
     * A section counts as defined only when it is a non-empty value (non-blank
     * string, or a non-empty list/map). Missing sections make the package
     * unusable for agent briefing.
     *
     * @param  array<string,mixed>  $package
     * @return array{schema:string,valid:bool,name:string,missing_fields:list<string>,defined_fields:list<string>,usable_for_briefing:bool}
     */
    public function validatePackage(array $package): array
    {
        $missing = [];
        $defined = [];
        foreach (self::REQUIRED_PACKAGE_FIELDS as $field) {
            if ($this->valuePresent($package[$field] ?? null)) {
                $defined[] = $field;
            } else {
                $missing[] = $field;
            }
        }

        $valid = $missing === [];

        return [
            'schema' => self::SCHEMA,
            'valid' => $valid,
            'name' => $this->str($package['name'] ?? '') ?: 'unnamed',
            'missing_fields' => $missing,
            'defined_fields' => $defined,
            // An invalid (incomplete) package must NOT be used to brief an agent.
            'usable_for_briefing' => $valid,
        ];
    }

    /**
     * Context Builder package selection from an operation signal.
     *
     * The doc lists the selection inputs (operation envelope, project stack,
     * active domain, changed file types, risk level, current spec/receipt,
     * canonical docs / Knowledge DB). This method maps a concrete signal onto the
     * relevant package set and is pinned by the documented React + Laravel UI save
     * worked example: the union of frontend + backend + design + testing +
     * security + receipt packages.
     *
     * @param  array{stack?:list<string>,domain?:string,changed_file_types?:list<string>,risk_level?:string,has_receipt?:bool}  $signal
     * @return array{schema:string,packages:list<string>,count:int,reasons:list<string>}
     */
    public function selectPackages(array $signal): array
    {
        $stack = array_map(
            fn ($s): string => $this->normalize((string) $s),
            is_array($signal['stack'] ?? null) ? $signal['stack'] : [],
        );
        $types = array_map(
            fn ($s): string => strtolower(trim((string) $s)),
            is_array($signal['changed_file_types'] ?? null) ? $signal['changed_file_types'] : [],
        );
        $risk = $this->normalize((string) ($signal['risk_level'] ?? 'low'));

        $packages = [];
        $reasons = [];

        $frontend = $this->signalsFrontend($stack, $types);
        $backend = $this->signalsBackend($stack, $types);

        if ($frontend) {
            $packages[] = 'react-typescript-v1';
            $packages[] = 'atlas-design-system-v2';
            $reasons[] = 'frontend_stack_selects_react_and_design_system';
        }
        if ($backend) {
            $packages[] = 'laravel-api-v1';
            $reasons[] = 'backend_stack_selects_laravel_api';
        }

        // Testing standard applies to any code change (frontend or backend).
        if ($frontend || $backend) {
            $packages[] = 'testing-standard-v3';
            $reasons[] = 'code_change_selects_testing_standard';
        }

        // Security policy applies to any write-capable / save action, and is
        // mandatory once the change carries non-low risk.
        $writeAction = in_array('save', $types, true)
            || in_array('write', $types, true)
            || in_array($risk, self::RISKY_EXECUTION_LEVELS, true)
            || $backend;
        if ($writeAction) {
            $packages[] = 'security-policy-v1';
            $reasons[] = 'write_or_risky_action_selects_security_policy';
        }

        // A decision receipt is expected whenever a receipt is in play OR the
        // action mutates state (save/write/backend).
        $needsReceipt = (bool) ($signal['has_receipt'] ?? false) || $writeAction;
        if ($needsReceipt) {
            $packages[] = 'decision-receipt-v1';
            $reasons[] = 'mutating_or_receipt_action_selects_decision_receipt';
        }

        $packages = array_values(array_unique($packages));

        return [
            'schema' => self::SCHEMA,
            'packages' => $packages,
            'count' => count($packages),
            'reasons' => $reasons,
        ];
    }

    /**
     * Projection-admissibility verdict for a local .atlas projection file
     * relative to a canonical source. Enforces all five Projection Law rules:
     *
     *  - canonical always outranks the projection (precedence is fixed);
     *  - the projection must declare a generation source AND timestamp, else it is
     *    rejected (cannot prove provenance);
     *  - if drift vs canonical is detected (or never checked), the projection is
     *    not admissible until reconciled;
     *  - for risky execution the projection must be re-verified against canon —
     *    an unverified projection is rejected for risky runs;
     *  - any permission / tool / autonomy the projection adds beyond canon is a
     *    silent escalation and is rejected outright.
     *
     * @param  array{declared_source?:string,declared_timestamp?:string,drift_detected?:bool,drift_checked?:bool,verified_against_canonical?:bool,risk_level?:string,added_permissions?:list<string>,added_tools?:list<string>,added_autonomy?:bool}  $projection
     * @return array{schema:string,verdict:string,admissible:bool,canonical_outranks_projection:bool,reasons:list<string>,violated_rules:list<string>,risky_execution:bool}
     */
    public function evaluateProjection(array $projection): array
    {
        $reasons = [];
        $violated = [];

        // Rule 2 — must declare generation source AND timestamp.
        $hasSource = $this->str($projection['declared_source'] ?? '') !== '';
        $hasTimestamp = $this->str($projection['declared_timestamp'] ?? '') !== '';
        if (! $hasSource || ! $hasTimestamp) {
            $violated[] = 'must_declare_source_and_timestamp';
            $reasons[] = 'projection_missing_generation_source_or_timestamp';
        }

        // Rule 3 — drift must be detected; unchecked or drifted is inadmissible.
        $driftChecked = (bool) ($projection['drift_checked'] ?? false);
        $driftDetected = (bool) ($projection['drift_detected'] ?? false);
        if (! $driftChecked) {
            $violated[] = 'drift_must_be_detected';
            $reasons[] = 'projection_drift_never_checked';
        } elseif ($driftDetected) {
            $violated[] = 'drift_must_be_detected';
            $reasons[] = 'projection_drift_detected_vs_canonical';
        }

        // Rule 4 — verify against canonical before risky execution.
        $risk = $this->normalize((string) ($projection['risk_level'] ?? 'low'));
        $riskyExecution = in_array($risk, self::RISKY_EXECUTION_LEVELS, true);
        $verified = (bool) ($projection['verified_against_canonical'] ?? false);
        if ($riskyExecution && ! $verified) {
            $violated[] = 'verify_canonical_before_risky_execution';
            $reasons[] = 'risky_execution_without_canonical_verification';
        }

        // Rule 5 — no silent permission / tool / autonomy escalation.
        $addedPermissions = $this->cleanList($projection['added_permissions'] ?? []);
        $addedTools = $this->cleanList($projection['added_tools'] ?? []);
        $addedAutonomy = (bool) ($projection['added_autonomy'] ?? false);
        if ($addedPermissions !== [] || $addedTools !== [] || $addedAutonomy) {
            $violated[] = 'no_silent_permission_escalation';
            $reasons[] = 'projection_adds_permissions_tools_or_autonomy';
        }

        $violated = array_values(array_unique($violated));
        $admissible = $violated === [];
        if ($admissible) {
            $reasons[] = 'projection_admissible_under_canonical_authority';
        }

        return [
            'schema' => self::SCHEMA,
            'verdict' => $admissible ? self::PROJECTION_ADMIT : self::PROJECTION_REJECT,
            'admissible' => $admissible,
            // Rule 1 — canonical ALWAYS outranks the projection, regardless of verdict.
            'canonical_outranks_projection' => true,
            'reasons' => $reasons,
            'violated_rules' => $violated,
            'risky_execution' => $riskyExecution,
        ];
    }

    /**
     * Full governance snapshot: the package contract, the projection law and the
     * documented worked example. Used as the command's default projection.
     *
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        $law = [];
        foreach (self::PROJECTION_LAW as $rule => $statement) {
            $law[] = ['rule' => $rule, 'statement' => $statement];
        }

        return [
            'schema' => self::SCHEMA,
            'doc' => 'docs/engineering-knowledge-base/spec-operating-system/context-packages-and-projections.md',
            'required_package_fields' => self::REQUIRED_PACKAGE_FIELDS,
            'projection_law' => $law,
            'react_laravel_ui_save_example' => [
                'signal' => [
                    'stack' => ['react', 'typescript', 'laravel'],
                    'changed_file_types' => ['tsx', 'php', 'save'],
                    'risk_level' => 'medium',
                ],
                'packages' => self::REACT_LARAVEL_UI_SAVE_PACKAGES,
            ],
        ];
    }

    /**
     * @param  list<string>  $stack
     * @param  list<string>  $types
     */
    private function signalsFrontend(array $stack, array $types): bool
    {
        foreach (['react', 'typescript', 'tsx', 'ts', 'frontend', 'ui'] as $needle) {
            if (in_array($needle, $stack, true) || in_array($needle, $types, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $stack
     * @param  list<string>  $types
     */
    private function signalsBackend(array $stack, array $types): bool
    {
        foreach (['laravel', 'php', 'backend', 'api'] as $needle) {
            if (in_array($needle, $stack, true) || in_array($needle, $types, true)) {
                return true;
            }
        }

        return false;
    }

    private function valuePresent(mixed $value): bool
    {
        if (is_string($value)) {
            return trim($value) !== '';
        }
        if (is_array($value)) {
            return $value !== [];
        }

        return is_int($value) || is_float($value) || is_bool($value);
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function cleanList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return array_values(array_unique($out));
    }

    private function str(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private function normalize(string $value): string
    {
        $value = strtolower(trim($value));

        return (string) preg_replace('/[^a-z0-9]+/', '_', $value);
    }
}
