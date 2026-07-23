<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

use App\Services\Ai\Aaeos\AtlasAaeosStringListNormalizer;

/**
 * Atlas AI Kernel Contracts decider.
 *
 * Pure, deterministic runtime for the executable contracts documented in the
 * Kernel Contracts doc. The doc declares three things this service enforces:
 *
 *   1. Core Objects + their REQUIRED fields (Operation Envelope, Decision
 *      Receipt v2, Ledger Event, Capability Manifest, Domain Manifest, Surface
 *      Adapter, Provider Driver). `validateObject()` checks a candidate payload
 *      against the documented "Must contain" field set and returns missing
 *      fields — an object that is missing any required field is invalid.
 *
 *   2. Three State Machines with explicit state sets (operation, decision
 *      receipt, tool run). `canTransition()` / `transition()` enforce the
 *      documented ordering: the linear happy path plus the branch/terminal
 *      edges the doc names (e.g. operation `executing -> repairing -> executing`,
 *      `gated`, and `failed`/`blocked` as terminal). Unknown states and illegal
 *      jumps are rejected.
 *
 *   3. The Policy invariant: "No lower layer can weaken hard Kernel
 *      invariants." `evaluatePolicyOverride()` rejects any lower-layer override
 *      that tries to widen a hard Kernel invariant and allows only narrowing.
 *
 * The service NEVER executes an operation, calls a provider, issues a real
 * receipt, writes the ledger or touches a database. It only decides whether a
 * shape / transition / override is contract-legal. Callers enforce.
 *
 * @see docs/engineering-knowledge-base/kernel/contracts.md
 */
final class AtlasKernelContractsService
{
    /** Stable schema id for the verdict envelope this service emits. */
    public const SCHEMA = 'atlas.kernel.contracts_gate.v1';

    /** The canonical Operation Envelope contract id (doc anti-duplication note). */
    public const ENVELOPE_CONTRACT = 'atlas.envelope.v1';

    /**
     * Core Objects and their documented REQUIRED fields ("Must contain" column).
     *
     * @var array<string,list<string>>
     */
    private const REQUIRED_FIELDS = [
        'operation_envelope' => [
            'envelope_id', 'operator_tenant', 'provenance', 'input_refs',
            'state', 'decision', 'execution', 'output', 'audit_hash',
        ],
        'decision_receipt_v2' => [
            'receipt_id', 'schema', 'selected', 'budget', 'gates',
            'repair_policy', 'dry_run', 'signed_by', 'hashes',
        ],
        'ledger_event' => [
            'event_id', 'envelope_id', 'type', 'payload_hash',
            'occurred_at', 'actor', 'schema_version',
        ],
        'capability_manifest' => [
            'capability_id', 'owner', 'surfaces', 'gates', 'tests', 'authority_group',
        ],
        'domain_manifest' => [
            'domain_id', 'flows', 'orchestrator', 'profiles', 'gates', 'memory_projection',
        ],
        'surface_adapter' => [
            'normalize_input', 'declare_capabilities', 'call_kernel', 'render_output',
        ],
        'provider_driver' => [
            'prepare_call', 'inject_identity', 'execute', 'normalize_response', 'report_telemetry',
        ],
    ];

    /**
     * State machines: machine => documented state set.
     *
     * @var array<string,list<string>>
     */
    private const STATES = [
        'operation' => [
            'created', 'routed', 'decided', 'executing', 'gated',
            'repairing', 'completed', 'failed', 'blocked',
        ],
        'decision_receipt' => [
            'issued', 'attached', 'consumed', 'expired', 'superseded', 'replayed',
        ],
        'tool_run' => [
            'planned', 'approved', 'invoked', 'returned',
            'normalized', 'evidenced', 'waived', 'failed',
        ],
    ];

    /**
     * Legal transitions per machine: from => list<to>.
     *
     * Derived from the documented state ordering: the linear happy path plus the
     * branch/terminal edges the doc names explicitly (operation repair loop,
     * gating, block/fail terminals; receipt expiry/supersede/replay; tool waive
     * and fail). Terminal states have no outgoing edges.
     *
     * @var array<string,array<string,list<string>>>
     */
    private const TRANSITIONS = [
        'operation' => [
            'created' => ['routed', 'blocked', 'failed'],
            'routed' => ['decided', 'blocked', 'failed'],
            'decided' => ['executing', 'blocked', 'failed'],
            'executing' => ['gated', 'repairing', 'completed', 'failed'],
            'gated' => ['executing', 'repairing', 'completed', 'blocked', 'failed'],
            'repairing' => ['executing', 'failed', 'blocked'],
            'completed' => [],
            'failed' => [],
            'blocked' => [],
        ],
        'decision_receipt' => [
            'issued' => ['attached', 'expired', 'superseded'],
            'attached' => ['consumed', 'expired', 'superseded'],
            'consumed' => ['replayed', 'superseded'],
            'replayed' => ['superseded'],
            'expired' => [],
            'superseded' => [],
        ],
        'tool_run' => [
            'planned' => ['approved', 'waived', 'failed'],
            'approved' => ['invoked', 'waived', 'failed'],
            'invoked' => ['returned', 'failed'],
            'returned' => ['normalized', 'failed'],
            'normalized' => ['evidenced', 'failed'],
            'evidenced' => [],
            'waived' => [],
            'failed' => [],
        ],
    ];

    /** Terminal states per machine (no outgoing transitions). */
    private const TERMINAL = [
        'operation' => ['completed', 'failed', 'blocked'],
        'decision_receipt' => ['expired', 'superseded'],
        'tool_run' => ['evidenced', 'waived', 'failed'],
    ];

    /**
     * Validate a candidate Core Object payload against the doc's required fields.
     *
     * @param  array<string,mixed>  $payload
     * @return array{
     *     schema:string, object:string, known_object:bool, valid:bool,
     *     required:list<string>, missing:list<string>, present:list<string>,
     *     reasons:list<string>
     * }
     */
    public function validateObject(string $object, array $payload): array
    {
        $object = $this->normalizeKey($object);
        $known = isset(self::REQUIRED_FIELDS[$object]);
        $required = self::REQUIRED_FIELDS[$object] ?? [];

        $missing = [];
        $present = [];
        foreach ($required as $field) {
            if ($this->hasValue($payload, $field)) {
                $present[] = $field;
            } else {
                $missing[] = $field;
            }
        }

        $reasons = [];
        if (! $known) {
            $reasons[] = 'unknown_core_object';
        }
        foreach ($missing as $field) {
            $reasons[] = 'missing_required_field:' . $field;
        }

        return [
            'schema' => self::SCHEMA,
            'object' => $object,
            'known_object' => $known,
            'valid' => $known && $missing === [],
            'required' => $required,
            'missing' => $missing,
            'present' => $present,
            'reasons' => $reasons,
        ];
    }

    /**
     * Is a transition from -> to legal in the given machine?
     */
    public function canTransition(string $machine, string $from, string $to): bool
    {
        $machine = $this->normalizeKey($machine);
        $from = $this->normalizeKey($from);
        $to = $this->normalizeKey($to);

        if (! isset(self::TRANSITIONS[$machine])) {
            return false;
        }
        if (! $this->isState($machine, $from) || ! $this->isState($machine, $to)) {
            return false;
        }

        return in_array($to, self::TRANSITIONS[$machine][$from] ?? [], true);
    }

    /**
     * Decide a transition and return a verdict envelope.
     *
     * @return array{
     *     schema:string, machine:string, from:string, to:string,
     *     allowed:bool, from_terminal:bool, to_terminal:bool,
     *     allowed_next:list<string>, reasons:list<string>
     * }
     */
    public function transition(string $machine, string $from, string $to): array
    {
        $machine = $this->normalizeKey($machine);
        $from = $this->normalizeKey($from);
        $to = $this->normalizeKey($to);

        $reasons = [];
        $known = isset(self::TRANSITIONS[$machine]);
        if (! $known) {
            $reasons[] = 'unknown_machine';
        }

        $fromKnown = $known && $this->isState($machine, $from);
        $toKnown = $known && $this->isState($machine, $to);
        if ($known && ! $fromKnown) {
            $reasons[] = 'unknown_from_state';
        }
        if ($known && ! $toKnown) {
            $reasons[] = 'unknown_to_state';
        }

        $fromTerminal = $fromKnown && $this->isTerminal($machine, $from);
        if ($fromTerminal) {
            $reasons[] = 'from_state_is_terminal';
        }

        $allowedNext = ($known && $fromKnown) ? self::TRANSITIONS[$machine][$from] : [];
        $allowed = $this->canTransition($machine, $from, $to);
        if (! $allowed && $known && $fromKnown && $toKnown && ! $fromTerminal) {
            $reasons[] = 'illegal_transition';
        }

        return [
            'schema' => self::SCHEMA,
            'machine' => $machine,
            'from' => $from,
            'to' => $to,
            'allowed' => $allowed,
            'from_terminal' => $fromTerminal,
            'to_terminal' => $toKnown && $this->isTerminal($machine, $to),
            'allowed_next' => $allowedNext,
            'reasons' => $reasons,
        ];
    }

    /**
     * Enforce the Kernel Policy invariant:
     * "No lower layer can weaken hard Kernel invariants."
     *
     * A lower layer may only NARROW (tighten) a hard invariant, never widen it.
     * Widening (e.g. requesting more autonomy, a bigger budget, looser gates,
     * more repair attempts, or adding a provider outside the Kernel allowlist)
     * is rejected.
     *
     * @param  array{
     *     autonomy_level?:int, max_budget?:int|float, repair_limit?:int,
     *     quality_gates?:list<string>, provider_allowlist?:list<string>
     * }  $kernel  hard Kernel invariants (the ceiling)
     * @param  array{
     *     autonomy_level?:int, max_budget?:int|float, repair_limit?:int,
     *     quality_gates?:list<string>, provider_allowlist?:list<string>
     * }  $override  the lower-layer requested policy/profile
     * @return array{
     *     schema:string, allowed:bool, violations:list<string>,
     *     effective:array<string,mixed>
     * }
     */
    public function evaluatePolicyOverride(array $kernel, array $override): array
    {
        $violations = [];

        // Numeric ceilings: override may be <= kernel, never greater.
        foreach (['autonomy_level', 'max_budget', 'repair_limit'] as $key) {
            if (array_key_exists($key, $override) && array_key_exists($key, $kernel)) {
                if ($override[$key] > $kernel[$key]) {
                    $violations[] = 'weakens_invariant:' . $key;
                }
            }
        }

        // Quality gates: lower layer must keep every hard gate; it may add, not drop.
        $kernelGates = AtlasAaeosStringListNormalizer::nonBlankStrings($kernel['quality_gates'] ?? []);
        if ($kernelGates !== [] && array_key_exists('quality_gates', $override)) {
            $overrideGates = AtlasAaeosStringListNormalizer::nonBlankStrings($override['quality_gates']);
            foreach ($kernelGates as $gate) {
                if (! in_array($gate, $overrideGates, true)) {
                    $violations[] = 'drops_required_gate:' . $gate;
                }
            }
        }

        // Provider allowlist: lower layer cannot introduce a provider the Kernel
        // does not permit.
        $kernelProviders = AtlasAaeosStringListNormalizer::nonBlankStrings($kernel['provider_allowlist'] ?? []);
        if ($kernelProviders !== [] && array_key_exists('provider_allowlist', $override)) {
            foreach (AtlasAaeosStringListNormalizer::nonBlankStrings($override['provider_allowlist']) as $provider) {
                if (! in_array($provider, $kernelProviders, true)) {
                    $violations[] = 'provider_outside_kernel_allowlist:' . $provider;
                }
            }
        }

        return [
            'schema' => self::SCHEMA,
            'allowed' => $violations === [],
            'violations' => $violations,
            'effective' => $violations === [] ? $override : $kernel,
        ];
    }

    /**
     * Canonical contract manifest: objects, machines, states and terminals.
     *
     * @return array{
     *     schema:string, envelope_contract:string,
     *     objects:array<string,list<string>>,
     *     machines:array<string,list<string>>,
     *     terminal_states:array<string,list<string>>
     * }
     */
    public function manifest(): array
    {
        return [
            'schema' => self::SCHEMA,
            'envelope_contract' => self::ENVELOPE_CONTRACT,
            'objects' => self::REQUIRED_FIELDS,
            'machines' => self::STATES,
            'terminal_states' => self::TERMINAL,
        ];
    }

    /** Is $state a declared state of $machine? */
    private function isState(string $machine, string $state): bool
    {
        return in_array($state, self::STATES[$machine] ?? [], true);
    }

    /** Is $state terminal in $machine? */
    private function isTerminal(string $machine, string $state): bool
    {
        return in_array($state, self::TERMINAL[$machine] ?? [], true);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hasValue(array $payload, string $field): bool
    {
        if (! array_key_exists($field, $payload)) {
            return false;
        }
        $value = $payload[$field];
        if ($value === null) {
            return false;
        }
        if (is_string($value) && trim($value) === '') {
            return false;
        }
        if (is_array($value) && $value === []) {
            return false;
        }

        return true;
    }

    private function normalizeKey(string $value): string
    {
        return strtolower(trim($value));
    }

}
