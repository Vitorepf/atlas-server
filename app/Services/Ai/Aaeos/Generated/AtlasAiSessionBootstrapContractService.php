<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Runtime for the Atlas Self-Construction AI Session Bootstrap Contract doc.
 *
 * AI Session Bootstrap is the one packet an AI session reads immediately after a
 * human says "continue implementation". This service is the deterministic
 * contract guard for that bootstrap payload. It does NOT select a packet from a
 * queue, call a provider, touch the DB, the shell or the filesystem — it
 * validates a candidate bootstrap payload against the doc's hard contract and
 * returns one controlled verdict (`accept` | `blocked`), plus the read-only
 * authority invariants the payload must always carry.
 *
 * Three bootstrap modes are governed exactly as the doc's "Bootstrap Modes"
 * section defines them:
 *
 *   1. read_only  — `--ai-session-bootstrap`: emits a bootstrap payload only. No
 *      claim is written (`claim_persisted` must be false).
 *   2. claim_next — `--claim-next-packet`: selects the next available packet,
 *      writes a durable LOCAL claim (`claim_persisted` true) and returns
 *      packet-scoped first commands. It STILL does not dispatch or grant
 *      execution authority.
 *   3. codex_start — `--codex-start-packet`: the preferred fresh-session entry
 *      point. Claims the next packet AND emits a self-contained contract. The
 *      doc requires it to also include a completion command so the owning
 *      session can later mark its packet complete after gates + evidence.
 *
 * Hard authority invariants from the "Payload Schema" + "Non Goals" sections —
 * these must hold in EVERY mode, regardless of how complete the payload is:
 *   - execution_allowed must be false  (bootstrap never grants execution).
 *   - ledger_write_allowed must be false (bootstrap never writes the ledger).
 *   - the payload must not dispatch a session, grant execution authority, mark
 *     completion, or hide hot external blockers (each is a STOP CONDITION).
 *
 * Provider adapter invariant from "Provider Adapter Fields":
 *   - adapter_may_widen_scope must ALWAYS be false. A payload that sets it true
 *     is blocked, because a provider adapter may never widen the packet scope.
 *
 * Mode-specific claim invariant from the doc:
 *   - read_only must NOT persist a claim; claim_next / codex_start MUST persist a
 *     claim and carry a selected packet to scope their first commands.
 *   - codex_start must additionally carry a completion_command (the doc: "It
 *     must also include a completion command").
 *
 * Stop conditions from the "Stop Conditions" section are exposed as a closed,
 * named set so a session can pin them; if a candidate payload reports any active
 * stop condition, the bootstrap is blocked.
 *
 * Pure and deterministic: same input always yields the same verdict. No
 * randomness, no clock, no I/O.
 *
 * @see docs/engineering-knowledge-base/self-construction/ai-session-bootstrap-contract.md
 */
final class AtlasAiSessionBootstrapContractService
{
    /** Stable schema id for the verdict this service emits. */
    public const SCHEMA_VERSION = 'atlas.aaeos.ai_session_bootstrap_contract.v1';

    public const MODE_VALIDATION = 'read_only_ai_session_bootstrap_contract_validation';

    /** Bootstrap modes, exactly as the doc's "Bootstrap Modes" section. */
    public const BOOTSTRAP_READ_ONLY = 'read_only';

    public const BOOTSTRAP_CLAIM_NEXT = 'claim_next';

    public const BOOTSTRAP_CODEX_START = 'codex_start';

    /** The doc's canonical session_mode value for the read-only payload. */
    public const SESSION_MODE_READ_ONLY = 'read_only_ai_bootstrap';

    public const VERDICT_ACCEPT = 'accept';

    public const VERDICT_BLOCKED = 'blocked';

    /**
     * The "Payload Schema" fields every bootstrap payload must carry, in doc
     * order. These are the fields a fresh AI session needs to resume without chat
     * history.
     *
     * @var list<string>
     */
    private const PAYLOAD_REQUIRED_FIELDS = [
        'bootstrap_id',
        'session_mode',
        'selected_packet_id',
        'assignment_hash',
        'reservation_hash',
        'runbook_hash',
        'scope_validator_status',
        'completion_gate_status',
        'execution_allowed',
        'claim_persisted',
        'ledger_write_allowed',
    ];

    /**
     * Boolean authority invariants. Each maps field => the value it MUST hold.
     * A payload that violates any of these is blocked with the paired code.
     *
     * @var array<string, array{value:bool, code:string}>
     */
    private const AUTHORITY_INVARIANTS = [
        'execution_allowed' => ['value' => false, 'code' => 'execution_allowed_must_be_false'],
        'ledger_write_allowed' => ['value' => false, 'code' => 'ledger_write_allowed_must_be_false'],
    ];

    /**
     * The doc's "Non Goals" expressed as boolean prohibition flags. If a
     * candidate payload sets any of these truthy, it is a hard STOP CONDITION and
     * the bootstrap is blocked. `code` is the machine reason emitted.
     *
     * @var array<string, string>
     */
    private const NON_GOAL_FLAGS = [
        'dispatches_session' => 'non_goal_dispatch_session',
        'grants_execution_authority' => 'non_goal_grant_execution_authority',
        'marks_completion' => 'non_goal_mark_completion',
        'hides_hot_blockers' => 'non_goal_hide_hot_blockers',
    ];

    /**
     * The doc's "Stop Conditions" section as a closed, named set. A candidate
     * payload may report any of these as active; an active stop condition blocks
     * the bootstrap.
     *
     * @var array<string, string>
     */
    private const STOP_CONDITIONS = [
        'selected_packet_missing' => 'selected packet is missing',
        'hash_changed_unexpectedly' => 'packet, split, assignment or reservation hash changed unexpectedly',
        'scope_blocked_by_other_lane' => 'scope validator is blocked by files owned by another lane',
        'hot_voice_or_kernel_in_scope' => 'hot Voice/Kernel files appear in claimed scope',
        'required_gates_failed' => 'required tests or docs gates fail',
        'operator_evidence_missing' => 'operator evidence is missing',
        'completion_gate_blocked' => 'completion gate is blocked',
    ];

    /**
     * Validate a candidate bootstrap payload against the contract for a mode.
     *
     * @param  array<string, mixed>  $payload
     * @param  string  $mode  one of the BOOTSTRAP_* constants
     * @return array{
     *     schema_version:string,
     *     mode:string,
     *     bootstrap_mode:string,
     *     verdict:string,
     *     accepted:bool,
     *     self_contained:bool,
     *     missing_fields:list<string>,
     *     authority_violations:list<string>,
     *     non_goal_violations:list<string>,
     *     active_stop_conditions:list<string>,
     *     adapter_may_widen_scope:bool,
     *     adapter_scope_violation:bool,
     *     claim_persisted:bool,
     *     required_field_count:int,
     *     present_field_count:int,
     *     reasons:list<string>,
     *     execution_allowed:bool,
     *     ledger_write_allowed:bool,
     *     dispatched:bool
     * }
     */
    public function validate(array $payload, string $mode = self::BOOTSTRAP_READ_ONLY): array
    {
        $mode = $this->normalizeMode($mode);

        $missing = [];
        foreach (self::PAYLOAD_REQUIRED_FIELDS as $field) {
            if (! $this->fieldPresent($payload, $field)) {
                $missing[] = $field;
            }
        }

        // codex_start must additionally carry a completion command (doc explicit).
        if ($mode === self::BOOTSTRAP_CODEX_START && ! $this->fieldPresent($payload, 'completion_command')) {
            $missing[] = 'completion_command';
        }

        // Authority invariants: execution_allowed / ledger_write_allowed false.
        $authorityViolations = [];
        foreach (self::AUTHORITY_INVARIANTS as $field => $rule) {
            if ($this->flagTruthy($payload, $field) !== $rule['value']) {
                $authorityViolations[] = $rule['code'];
            }
        }

        // Non-Goals: any declared is a hard stop.
        $nonGoalViolations = [];
        foreach (self::NON_GOAL_FLAGS as $flag => $code) {
            if ($this->flagTruthy($payload, $flag)) {
                $nonGoalViolations[] = $code;
            }
        }

        // Provider adapter invariant: adapter_may_widen_scope must always be false.
        $adapterMayWiden = $this->flagTruthy($payload, 'adapter_may_widen_scope');
        $adapterScopeViolation = $adapterMayWiden === true;

        // Mode-specific claim invariant.
        $claimPersisted = $this->flagTruthy($payload, 'claim_persisted');
        $claimViolation = $this->claimInvariantViolation($mode, $claimPersisted);

        // Active stop conditions reported on the payload.
        $activeStops = $this->activeStopConditions($payload);

        $reasons = [];
        foreach ($missing as $field) {
            $reasons[] = 'missing_required_field:'.$field;
        }
        foreach ($authorityViolations as $code) {
            $reasons[] = $code;
        }
        foreach ($nonGoalViolations as $code) {
            $reasons[] = $code;
        }
        if ($adapterScopeViolation) {
            $reasons[] = 'adapter_may_widen_scope_must_be_false';
        }
        if ($claimViolation !== null) {
            $reasons[] = $claimViolation;
        }
        foreach ($activeStops as $key) {
            $reasons[] = 'stop_condition:'.$key;
        }

        $selfContained = $missing === [];

        $accepted = $selfContained
            && $authorityViolations === []
            && $nonGoalViolations === []
            && ! $adapterScopeViolation
            && $claimViolation === null
            && $activeStops === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE_VALIDATION,
            'bootstrap_mode' => $mode,
            'verdict' => $accepted ? self::VERDICT_ACCEPT : self::VERDICT_BLOCKED,
            'accepted' => $accepted,
            'self_contained' => $selfContained,
            'missing_fields' => array_values($missing),
            'authority_violations' => array_values($authorityViolations),
            'non_goal_violations' => array_values($nonGoalViolations),
            'active_stop_conditions' => array_values($activeStops),
            'adapter_may_widen_scope' => $adapterMayWiden,
            'adapter_scope_violation' => $adapterScopeViolation,
            'claim_persisted' => $claimPersisted,
            'required_field_count' => count(self::PAYLOAD_REQUIRED_FIELDS) + ($mode === self::BOOTSTRAP_CODEX_START ? 1 : 0),
            'present_field_count' => (count(self::PAYLOAD_REQUIRED_FIELDS) + ($mode === self::BOOTSTRAP_CODEX_START ? 1 : 0)) - count($missing),
            'reasons' => $reasons,
            // Read-only authority invariants always reported, regardless of verdict.
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'dispatched' => false,
        ];
    }

    /**
     * The closed required-field list of the documented payload schema.
     *
     * @return list<string>
     */
    public function requiredFields(): array
    {
        return self::PAYLOAD_REQUIRED_FIELDS;
    }

    /**
     * The closed set of Non-Goal prohibition codes the contract enforces.
     *
     * @return list<string>
     */
    public function nonGoalCodes(): array
    {
        return array_values(self::NON_GOAL_FLAGS);
    }

    /**
     * The doc's closed "Stop Conditions" set: key => human description.
     *
     * @return array<string, string>
     */
    public function stopConditions(): array
    {
        return self::STOP_CONDITIONS;
    }

    /**
     * The five "Required First Commands" every AI session must start by running,
     * in doc order. When $packetId is provided (a durable claimed packet), the
     * packet-scoped commands carry `--packet=<id>` as the doc requires.
     *
     * @return list<string>
     */
    public function requiredFirstCommands(?string $packetId = null): array
    {
        $packetSuffix = ($packetId !== null && trim($packetId) !== '')
            ? ' --packet='.$packetId
            : '';

        return [
            'git status --short',
            'git diff --stat',
            'git diff --name-only',
            'php artisan atlas:ai:self-construction --ai-session-bootstrap'.$packetSuffix.' --json',
            'php artisan atlas:ai:self-construction --scope-validator'.$packetSuffix.' --json',
        ];
    }

    /**
     * A worked, minimally-complete read-only bootstrap payload that satisfies the
     * contract. Useful as a template and as the command's self-describing sample.
     * Every authority invariant is held and no Non-Goal is declared.
     *
     * @return array<string, mixed>
     */
    public function sampleReadOnlyPayload(string $packetId = 'AIP-SPLIT-0001'): array
    {
        return [
            'bootstrap_id' => 'BOOTSTRAP-SELF-CONSTRUCTION-0001',
            'session_mode' => self::SESSION_MODE_READ_ONLY,
            'selected_packet_id' => $packetId,
            'assignment_hash' => 'sha256:assignment-placeholder',
            'reservation_hash' => 'sha256:reservation-placeholder',
            'runbook_hash' => 'sha256:runbook-placeholder',
            'scope_validator_status' => 'pass',
            'completion_gate_status' => 'human_review_required',
            'execution_allowed' => false,
            'claim_persisted' => false,
            'ledger_write_allowed' => false,
            // Provider adapter invariant, explicitly held:
            'adapter_may_widen_scope' => false,
            // Blockers present (not hidden), simply empty:
            'open_hot_blockers' => [],
            // Non-Goals, all explicitly refused:
            'dispatches_session' => false,
            'grants_execution_authority' => false,
            'marks_completion' => false,
            'hides_hot_blockers' => false,
            // Stop conditions, all reported inactive:
            'stop_selected_packet_missing' => false,
            'stop_hash_changed_unexpectedly' => false,
            'stop_scope_blocked_by_other_lane' => false,
            'stop_hot_voice_or_kernel_in_scope' => false,
            'stop_required_gates_failed' => false,
            'stop_operator_evidence_missing' => false,
            'stop_completion_gate_blocked' => false,
        ];
    }

    /**
     * A worked codex_start payload: the read-only payload promoted to a durable
     * claim (claim_persisted true) plus the required completion command.
     *
     * @return array<string, mixed>
     */
    public function sampleCodexStartPayload(string $packetId = 'AIP-SPLIT-0001'): array
    {
        $payload = $this->sampleReadOnlyPayload($packetId);
        $payload['session_mode'] = 'codex_start_packet';
        $payload['claim_persisted'] = true;
        $payload['completion_command'] = 'php artisan atlas:ai:self-construction --complete-packet --packet='.$packetId.' --json';

        return $payload;
    }

    /**
     * Self-describe the contract and run a worked accept + blocked pair, proving
     * the validator is live. Read-only.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        $sample = $this->sampleReadOnlyPayload();
        $accepted = $this->validate($sample, self::BOOTSTRAP_READ_ONLY);

        // A blocked sample: drop one field, declare a Non-Goal, AND set the
        // provider adapter scope-widen flag (which must always be false).
        $bad = $sample;
        unset($bad['runbook_hash']);
        $bad['marks_completion'] = true;
        $bad['adapter_may_widen_scope'] = true;
        $blocked = $this->validate($bad, self::BOOTSTRAP_READ_ONLY);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE_VALIDATION,
            'bootstrap_modes' => [self::BOOTSTRAP_READ_ONLY, self::BOOTSTRAP_CLAIM_NEXT, self::BOOTSTRAP_CODEX_START],
            'payload_required_field_count' => count(self::PAYLOAD_REQUIRED_FIELDS),
            'non_goal_count' => count(self::NON_GOAL_FLAGS),
            'non_goal_codes' => $this->nonGoalCodes(),
            'stop_condition_count' => count(self::STOP_CONDITIONS),
            'stop_condition_keys' => array_keys(self::STOP_CONDITIONS),
            'required_first_commands' => $this->requiredFirstCommands(),
            'sample_accept' => $accepted,
            'sample_blocked' => $blocked,
            'adapter_may_widen_scope_invariant' => false,
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'dispatched' => false,
        ];
    }

    private function normalizeMode(string $mode): string
    {
        return match ($mode) {
            self::BOOTSTRAP_CLAIM_NEXT => self::BOOTSTRAP_CLAIM_NEXT,
            self::BOOTSTRAP_CODEX_START => self::BOOTSTRAP_CODEX_START,
            default => self::BOOTSTRAP_READ_ONLY,
        };
    }

    /**
     * Mode-specific claim invariant:
     *   - read_only must NOT persist a claim.
     *   - claim_next / codex_start MUST persist a claim.
     *
     * @return string|null  violation code, or null if the invariant holds
     */
    private function claimInvariantViolation(string $mode, bool $claimPersisted): ?string
    {
        if ($mode === self::BOOTSTRAP_READ_ONLY) {
            return $claimPersisted ? 'read_only_must_not_persist_claim' : null;
        }

        // claim_next + codex_start
        return $claimPersisted ? null : 'claim_mode_must_persist_claim';
    }

    /**
     * Collect the stop-condition keys reported active on the payload. A payload
     * reports a stop condition active with a truthy `stop_<key>` flag.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function activeStopConditions(array $payload): array
    {
        $active = [];
        foreach (array_keys(self::STOP_CONDITIONS) as $key) {
            if ($this->flagTruthy($payload, 'stop_'.$key)) {
                $active[] = $key;
            }
        }

        return $active;
    }

    /**
     * A field counts as present only when it carries real content. Empty strings,
     * empty arrays and null do not satisfy a required field — the doc requires a
     * self-contained payload, and "looks present" is not present. Booleans count
     * as present even when false (the schema's authority flags are meaningfully
     * false).
     *
     * @param  array<string, mixed>  $payload
     */
    private function fieldPresent(array $payload, string $field): bool
    {
        if (! array_key_exists($field, $payload)) {
            return false;
        }

        $value = $payload[$field];

        if ($value === null) {
            return false;
        }

        if (is_bool($value)) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function flagTruthy(array $payload, string $flag): bool
    {
        return array_key_exists($flag, $payload) && (bool) $payload[$flag] === true;
    }
}
