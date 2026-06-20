<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Pattern;

use InvalidArgumentException;

/**
 * LOOP-PATTERN-REGISTRY · Slice 1 — the immutable governed spec for ONE loop execution pattern.
 *
 * A pattern is the *repertoire* of work-structures the Atlas Loop chooses BEFORE it originates work
 * (see docs/engineering-knowledge-base/atlas-loop-pattern-registry.md). It is NOT a parallel runtime,
 * a prompt book, or an autonomy grant — it is a declarative, verifiable contract Atlas can reason over.
 *
 * This VO is deterministic and fail-closed: {@see fromArray()} validates the required fields and the
 * controlled vocabularies (source / status / durability / terminal-states / sandbox capabilities) and
 * THROWS on anything missing or out-of-vocab. A spec with no success gate, no terminal state, or no
 * sandbox profile can never come into existence — the very theatre the registry exists to prevent.
 *
 * Status reconciliation: the canonical doc's lifecycle is
 *   source_material → candidate → ready → default → deprecated.
 * "active" in operator shorthand means a pattern the selector may actually pick == {ready, default}.
 * {@see isSelectable()} encodes that, so external/candidate material is structurally un-pickable.
 */
final class AtlasLoopPatternSpec
{
    /** Where a pattern came from (provenance). External provenance can never be born selectable. */
    public const SOURCE_ATLAS_NATIVE = 'atlas_native';

    public const SOURCE_EXTERNAL_SKILL = 'external_skill';

    public const SOURCE_EXTERNAL_CATALOG = 'external_catalog';

    public const SOURCE_RUN_LEARNING = 'run_learning';

    public const SOURCE_OPERATOR_SEED = 'operator_seed';

    /** Governance lifecycle. Only {ready, default} are selectable; the rest are quarantine/history. */
    public const STATUS_SOURCE_MATERIAL = 'source_material';

    public const STATUS_CANDIDATE = 'candidate';

    public const STATUS_READY = 'ready';

    public const STATUS_DEFAULT = 'default';

    public const STATUS_DEPRECATED = 'deprecated';

    /** Execution durability shape (how state survives across cycles). */
    public const DURABILITY_SINGLE_CYCLE = 'single_cycle';

    public const DURABILITY_RESUMABLE = 'resumable_state_machine';

    public const DURABILITY_CAMPAIGN = 'campaign_supervised';

    public const DURABILITY_EXTERNAL_QUEUE = 'external_queue_adapter';

    /** The six honest terminal states a pattern run may end in. */
    public const TERMINAL_SUCCESS = 'success';

    public const TERMINAL_CLEAN_NO_OP = 'clean_no_op';

    public const TERMINAL_BLOCKED = 'blocked';

    public const TERMINAL_APPROVAL_REQUIRED = 'approval_required';

    public const TERMINAL_EXHAUSTED = 'exhausted';

    public const TERMINAL_STAGNATED = 'stagnated';

    /** Sandbox capabilities — deny-by-default; a profile names ONLY what it explicitly opts into. */
    public const CAP_READ_ONLY = 'read_only';

    public const CAP_WORKTREE_WRITE = 'worktree_write';

    public const CAP_COMMAND = 'command';

    public const CAP_NETWORK = 'network';

    public const CAP_CREDENTIALS = 'credentials';

    public const CAP_EXTERNAL_EGRESS = 'external_egress';

    public const SOURCES = [
        self::SOURCE_ATLAS_NATIVE,
        self::SOURCE_EXTERNAL_SKILL,
        self::SOURCE_EXTERNAL_CATALOG,
        self::SOURCE_RUN_LEARNING,
        self::SOURCE_OPERATOR_SEED,
    ];

    public const STATUSES = [
        self::STATUS_SOURCE_MATERIAL,
        self::STATUS_CANDIDATE,
        self::STATUS_READY,
        self::STATUS_DEFAULT,
        self::STATUS_DEPRECATED,
    ];

    public const DURABILITY_MODES = [
        self::DURABILITY_SINGLE_CYCLE,
        self::DURABILITY_RESUMABLE,
        self::DURABILITY_CAMPAIGN,
        self::DURABILITY_EXTERNAL_QUEUE,
    ];

    public const TERMINAL_STATES = [
        self::TERMINAL_SUCCESS,
        self::TERMINAL_CLEAN_NO_OP,
        self::TERMINAL_BLOCKED,
        self::TERMINAL_APPROVAL_REQUIRED,
        self::TERMINAL_EXHAUSTED,
        self::TERMINAL_STAGNATED,
    ];

    public const SANDBOX_CAPABILITIES = [
        self::CAP_READ_ONLY,
        self::CAP_WORKTREE_WRITE,
        self::CAP_COMMAND,
        self::CAP_NETWORK,
        self::CAP_CREDENTIALS,
        self::CAP_EXTERNAL_EGRESS,
    ];

    public const RISK_LEVELS = ['low', 'medium', 'high'];

    /** Provenance that may NEVER be born as ready/default — it must earn promotion via a fresh eval. */
    private const EXTERNAL_SOURCES = [
        self::SOURCE_EXTERNAL_SKILL,
        self::SOURCE_EXTERNAL_CATALOG,
    ];

    /**
     * @param  array<string,mixed>  $triggerSchema   fit / objective_kinds / preconditions (selector input)
     * @param  array<string,mixed>  $paramsSchema    backend-owned parameter schema (UI/CLI only project it)
     * @param  array<string,mixed>  $outputSchema    expected receipts/invariants the certifier proves
     * @param  list<string>         $successGates    reproducible, independent, observable proofs (≥1)
     * @param  list<string>         $terminalStates  subset of TERMINAL_STATES, must include success
     * @param  array<string,mixed>  $sandboxProfile  {allowed: list<cap>, deny_by_default: true}
     * @param  array<string,mixed>  $agentLanePolicy {lanes, self_approval:false, verifier_independent}
     * @param  array<string,mixed>  $sourceSnapshot  {source, version|hash, captured_at, drift_notes}
     */
    private function __construct(
        public readonly string $id,
        public readonly string $version,
        public readonly string $name,
        public readonly string $description,
        public readonly string $intent,
        public readonly array $triggerSchema,
        public readonly array $paramsSchema,
        public readonly array $outputSchema,
        public readonly array $successGates,
        public readonly array $terminalStates,
        public readonly string $durabilityMode,
        public readonly array $sandboxProfile,
        public readonly array $agentLanePolicy,
        public readonly string $riskLevel,
        public readonly string $source,
        public readonly array $sourceSnapshot,
        public readonly string $status,
    ) {
    }

    /**
     * Fail-closed factory: build a valid spec or throw. The throw is the gate — there is no path to an
     * invalid {@see AtlasLoopPatternSpec} instance, so downstream code never has to re-check structure.
     *
     * @param  array<string,mixed>  $data
     *
     * @throws InvalidArgumentException with the concrete list of what is missing/invalid.
     */
    public static function fromArray(array $data): self
    {
        $errors = self::validationErrors($data);
        if ($errors !== []) {
            throw new InvalidArgumentException(
                'Invalid AtlasLoopPatternSpec ('.((string) ($data['id'] ?? '?')).'): '.implode('; ', $errors)
            );
        }

        $source = (string) $data['source'];
        $status = (string) $data['status'];

        // Structural soundness above all: an external-provenance spec may NEVER be born ready/default —
        // it is quarantined to candidate at construction. Promotion is the ChampionGate's job, not a
        // seed author's. This makes "no external auto-activation" an invariant of the type, not a hope.
        if (in_array($source, self::EXTERNAL_SOURCES, true)
            && in_array($status, [self::STATUS_READY, self::STATUS_DEFAULT], true)) {
            $status = self::STATUS_CANDIDATE;
        }

        return new self(
            id: (string) $data['id'],
            version: (string) $data['version'],
            name: (string) ($data['name'] ?? $data['id']),
            description: (string) ($data['description'] ?? ''),
            intent: (string) $data['intent'],
            triggerSchema: (array) ($data['trigger_schema'] ?? []),
            paramsSchema: (array) ($data['params_schema'] ?? []),
            outputSchema: (array) ($data['output_schema'] ?? []),
            successGates: array_values(array_filter(array_map(
                static fn ($g): string => trim((string) $g),
                (array) $data['success_gates']
            ), static fn (string $g): bool => $g !== '')),
            terminalStates: array_values(array_unique(array_map(
                static fn ($s): string => (string) $s,
                (array) $data['terminal_states']
            ))),
            durabilityMode: (string) $data['durability_mode'],
            sandboxProfile: self::normalizeSandbox((array) ($data['sandbox_profile'] ?? [])),
            agentLanePolicy: self::normalizeLanePolicy((array) ($data['agent_lane_policy'] ?? [])),
            riskLevel: (string) ($data['risk_level'] ?? 'medium'),
            source: $source,
            sourceSnapshot: (array) ($data['source_snapshot'] ?? []),
            status: $status,
        );
    }

    /**
     * Why a spec is invalid (empty list == valid). Fail-closed contract surface — these are the exact
     * conditions {@see fromArray()} throws on, and the registry/intake re-use them to reject material
     * without constructing it.
     *
     * @param  array<string,mixed>  $data
     * @return list<string>
     */
    public static function validationErrors(array $data): array
    {
        $errors = array_values(array_filter([
            'id' => 'id is required',
            'version' => 'version is required',
            'intent' => 'intent is required',
        ], static fn (string $error, string $field): bool => trim((string) ($data[$field] ?? '')) === '', ARRAY_FILTER_USE_BOTH));

        $gates = array_values(array_filter(array_map(
            static fn ($g): string => trim((string) $g),
            (array) ($data['success_gates'] ?? [])
        ), static fn (string $g): bool => $g !== ''));
        if ($gates === []) {
            $errors[] = 'at least one success_gate is required (fail-closed: a gateless pattern cannot certify)';
        }

        $terminals = array_map(static fn ($s): string => (string) $s, (array) ($data['terminal_states'] ?? []));
        if ($terminals === []) {
            $errors[] = 'at least one terminal_state is required';
        } elseif (! in_array(self::TERMINAL_SUCCESS, $terminals, true)) {
            $errors[] = "terminal_states must include 'success'";
        }
        foreach ($terminals as $t) {
            if (! in_array($t, self::TERMINAL_STATES, true)) {
                $errors[] = "unknown terminal_state '{$t}'";
            }
        }

        $durability = (string) ($data['durability_mode'] ?? '');
        if (! in_array($durability, self::DURABILITY_MODES, true)) {
            $errors[] = "durability_mode '{$durability}' is not one of ".implode('|', self::DURABILITY_MODES);
        }

        // sandbox_profile must EXIST (deny-by-default is fine, absent is not) — execution without a
        // declared capability frontier is exactly the unsafe path the doc forbids.
        if (! array_key_exists('sandbox_profile', $data)) {
            $errors[] = 'sandbox_profile is required (deny-by-default is allowed; absent is not)';
        } else {
            foreach ((array) (($data['sandbox_profile']['allowed'] ?? [])) as $cap) {
                if (! in_array((string) $cap, self::SANDBOX_CAPABILITIES, true)) {
                    $errors[] = "unknown sandbox capability '".((string) $cap)."'";
                }
            }
        }

        $source = (string) ($data['source'] ?? '');
        if (! in_array($source, self::SOURCES, true)) {
            $errors[] = "source '{$source}' is not one of ".implode('|', self::SOURCES);
        }

        $status = (string) ($data['status'] ?? '');
        if (! in_array($status, self::STATUSES, true)) {
            $errors[] = "status '{$status}' is not one of ".implode('|', self::STATUSES);
        }

        $risk = (string) ($data['risk_level'] ?? 'medium');
        if (! in_array($risk, self::RISK_LEVELS, true)) {
            $errors[] = "risk_level '{$risk}' is not one of ".implode('|', self::RISK_LEVELS);
        }

        return $errors;
    }

    /** Non-throwing build for soft paths (intake quarantine). Null when invalid. */
    public static function tryFromArray(array $data): ?self
    {
        return self::validationErrors($data) === [] ? self::fromArray($data) : null;
    }

    /** The selector may only pick patterns the governance ladder has promoted to ready/default. */
    public function isSelectable(): bool
    {
        return in_array($this->status, [self::STATUS_READY, self::STATUS_DEFAULT], true);
    }

    public function isExternalSource(): bool
    {
        return in_array($this->source, self::EXTERNAL_SOURCES, true);
    }

    /** Objective kinds this pattern fits (docs/bug/refactor/verification/self_improvement, …). */
    /** @return list<string> */
    public function objectiveKinds(): array
    {
        $kinds = $this->triggerSchema['objective_kinds'] ?? [$this->intent];

        return array_values(array_filter(array_map(
            static fn ($k): string => (string) $k,
            (array) $kinds
        ), static fn (string $k): bool => $k !== ''));
    }

    /** The capabilities this pattern is permitted to use (deny-by-default → empty == read-only). */
    /** @return list<string> */
    public function allowedCapabilities(): array
    {
        return array_values(array_map(
            static fn ($c): string => (string) $c,
            (array) ($this->sandboxProfile['allowed'] ?? [])
        ));
    }

    /**
     * Whether the lane policy structurally forbids self-approval (creator/implementer ≠ verifier).
     * The ChampionGate and the producer integration both rely on this being honest.
     */
    public function forbidsSelfApproval(): bool
    {
        return ($this->agentLanePolicy['self_approval'] ?? false) === false
            && ($this->agentLanePolicy['verifier_independent'] ?? false) === true;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'name' => $this->name,
            'description' => $this->description,
            'intent' => $this->intent,
            'trigger_schema' => $this->triggerSchema,
            'params_schema' => $this->paramsSchema,
            'output_schema' => $this->outputSchema,
            'success_gates' => $this->successGates,
            'terminal_states' => $this->terminalStates,
            'durability_mode' => $this->durabilityMode,
            'sandbox_profile' => $this->sandboxProfile,
            'agent_lane_policy' => $this->agentLanePolicy,
            'risk_level' => $this->riskLevel,
            'source' => $this->source,
            'source_snapshot' => $this->sourceSnapshot,
            'status' => $this->status,
            'selectable' => $this->isSelectable(),
        ];
    }

    /** Deny-by-default normalization: always carries an explicit allow-list + the deny flag. */
    /**
     * @param  array<string,mixed>  $profile
     * @return array<string,mixed>
     */
    private static function normalizeSandbox(array $profile): array
    {
        $allowed = array_values(array_unique(array_map(
            static fn ($c): string => (string) $c,
            (array) ($profile['allowed'] ?? [])
        )));

        return [
            'allowed' => $allowed === [] ? [self::CAP_READ_ONLY] : $allowed,
            'deny_by_default' => true,
        ];
    }

    /** Lane policy defaults to the safe stance: no self-approval, verifier independent. */
    /**
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    private static function normalizeLanePolicy(array $policy): array
    {
        return [
            'lanes' => array_values(array_map(
                static fn ($l): string => (string) $l,
                (array) ($policy['lanes'] ?? ['designer', 'implementer', 'verifier', 'critic', 'repair'])
            )),
            'self_approval' => (bool) ($policy['self_approval'] ?? false),
            'verifier_independent' => (bool) ($policy['verifier_independent'] ?? true),
        ];
    }
}
