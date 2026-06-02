<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S127 — L9SovereigntyInvariantRegistry (block: L9 Sovereign Engineering).
 *
 * Materializes the permanent invariant that the operator is the SOLE source of
 * engineering values and ends. This is the "invariante que nunca se transcende":
 * objectives, values, taste and engineering sovereignty stay with the operator,
 * forever. The system may help ARTICULATE engineering criteria the operator
 * holds but has not expressed; it may NEVER be the SOURCE of the value. The
 * instant the system chooses its own engineering ends it stops being a substrate
 * of sovereignty and becomes autonomous-by-itself — which the thesis refuses.
 *
 * The registry exposes the sacred invariant under list() together with the
 * decisions it protects, the system actions it forbids (always including
 * `system_as_value_source`), the system actions it does permit (articulating
 * operator criteria, never choosing ends) and an always-on override requirement.
 * list() also yields a certification verdict computed from the supplied context:
 * without explicit operator authority the invariant cannot be certified, so the
 * verdict is fail-closed and surfaces `operator_authority_missing`.
 *
 * Pure: every returned field is computed from the method inputs and the constant
 * invariant definition via the rules above. No I/O, DB, Eloquent, facade,
 * provider, git/Process, filesystem, clock or randomness. Identical inputs always
 * yield an identical result.
 *
 * @see docs/engineering-knowledge-base/atlas-aaeos-l9-sovereign-engineering-map.md
 * @see docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md
 */
final class L9SovereigntyInvariantRegistry
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l9.sovereignty_invariant.v1';

    /** The L9 phase this registry materializes. */
    public const PHASE = 'sovereignty';

    /** Stable identifier of the sacred sovereignty invariant. */
    public const INVARIANT_ID = 'l9.operator_sole_value_source';

    /**
     * The permanent invariant statement (operator is the sole source of
     * engineering values and ends).
     */
    public const STATEMENT = 'The operator is the sole source of engineering values and ends; the system may articulate operator criteria but never chooses engineering ends.';

    /** The forbidden system action whose presence the invariant must guarantee. */
    public const FORBIDDEN_VALUE_SOURCE_ACTION = 'system_as_value_source';

    /**
     * Engineering decisions that remain with the operator forever. Sorted so the
     * registry is deterministic regardless of authoring order.
     *
     * @var list<string>
     */
    private const PROTECTED_DECISIONS = [
        'engineering_ends',
        'engineering_north_star',
        'engineering_taste',
        'engineering_values',
        'quality_bar',
        'sovereignty_of_engineering',
    ];

    /**
     * System actions the invariant permanently forbids. `system_as_value_source`
     * is always present; the others spell out the same prohibition (the system
     * never selects, overrides or authors engineering ends/values).
     *
     * @var list<string>
     */
    private const FORBIDDEN_SYSTEM_ACTIONS = [
        'authorize_own_engineering_values',
        'choose_engineering_ends',
        'override_operator_values',
        'set_engineering_north_star',
        'system_as_value_source',
    ];

    /**
     * System actions the invariant DOES permit: the system may surface and
     * articulate the operator's latent criteria, but the operator stays the
     * source. ("System may articulate operator criteria, never choose
     * engineering ends.")
     *
     * @var list<string>
     */
    private const PERMITTED_SYSTEM_ACTIONS = [
        'articulate_operator_criteria',
        'propose_options_for_operator_decision',
        'surface_latent_operator_criteria',
    ];

    /**
     * List the sovereignty invariant registry and certify it against the given
     * context.
     *
     * Recognised `$context` keys (any one asserts operator authority):
     *   - `operator_authority` (bool true, or array with truthy `present`); or
     *   - `operator_authority_present` (bool true); or
     *   - `operator_id` (non-empty string).
     * Authority absent or explicitly false => certification is blocked.
     *
     * @param  array<string,mixed>  $context
     * @return array{
     *     schema_version:string,
     *     phase:string,
     *     invariant_id:string,
     *     statement:string,
     *     protected_decisions:list<string>,
     *     forbidden_system_actions:list<string>,
     *     permitted_system_actions:list<string>,
     *     system_as_value_source_forbidden:bool,
     *     override_required:bool,
     *     operator_authority_present:bool,
     *     certifiable:bool,
     *     blockers:list<string>
     * }
     */
    public function list(array $context = []): array
    {
        $operatorAuthorityPresent = $this->operatorAuthorityPresent($context);

        $blockers = [];
        if (! $operatorAuthorityPresent) {
            // Missing operator authority blocks certification: the invariant is
            // about WHO is the source of value, so it cannot be certified
            // without a present, authoritative operator.
            $blockers[] = 'operator_authority_missing';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'phase' => self::PHASE,
            'invariant_id' => self::INVARIANT_ID,
            'statement' => self::STATEMENT,
            'protected_decisions' => self::PROTECTED_DECISIONS,
            'forbidden_system_actions' => self::FORBIDDEN_SYSTEM_ACTIONS,
            'permitted_system_actions' => self::PERMITTED_SYSTEM_ACTIONS,
            'system_as_value_source_forbidden' => $this->forbids(self::FORBIDDEN_VALUE_SOURCE_ACTION),
            'override_required' => true,
            'operator_authority_present' => $operatorAuthorityPresent,
            'certifiable' => $blockers === [],
            'blockers' => $blockers,
        ];
    }

    /**
     * Whether a system action is forbidden by the invariant. Computed by real
     * membership over the forbidden-action registry (never a canned answer).
     */
    public function forbids(string $action): bool
    {
        return in_array($action, self::FORBIDDEN_SYSTEM_ACTIONS, true);
    }

    /**
     * Resolve whether the context carries explicit operator authority. Any of the
     * accepted forms asserts authority; an explicit false `present` overrides a
     * sibling truthy signal so the verdict is fail-closed.
     *
     * @param  array<string,mixed>  $context
     */
    private function operatorAuthorityPresent(array $context): bool
    {
        $authority = $context['operator_authority'] ?? null;

        if (is_array($authority)) {
            if (array_key_exists('present', $authority)) {
                return $authority['present'] === true;
            }

            $id = $authority['operator_id'] ?? ($authority['id'] ?? null);
            if (is_string($id) && trim($id) !== '') {
                return true;
            }
        }

        if ($authority === true) {
            return true;
        }

        if (array_key_exists('operator_authority_present', $context)) {
            return $context['operator_authority_present'] === true;
        }

        $operatorId = $context['operator_id'] ?? null;

        return is_string($operatorId) && trim($operatorId) !== '';
    }
}
