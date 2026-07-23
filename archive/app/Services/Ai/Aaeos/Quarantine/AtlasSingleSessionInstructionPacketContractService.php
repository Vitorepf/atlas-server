<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Runtime for the Single Session Instruction Packet Contract doc.
 *
 * When broader automated dispatch is blocked, Atlas must still be able to emit a
 * single, self-contained packet that ONE AI session can consume and continue
 * Self-Construction work safely, without chat history. This service is the
 * deterministic contract guard for that packet. It does not build the packet's
 * content from a queue, call a provider, touch the DB, the shell or the
 * filesystem — it validates a candidate packet against the doc's hard contract
 * and returns a single controlled verdict (`accept` | `reject`).
 *
 * Two packet shapes are governed, as the doc defines them:
 *
 *   1. Instruction Packet (deterministic read-only) — "Purpose" section lists
 *      the fields it MUST include:
 *        selected packet id; one-line operator instruction; required first
 *        commands; allowed files; forbidden hot scopes; required gates;
 *        evidence expected; stop conditions; bootstrap and readiness hashes.
 *
 *   2. Codex Start Packet (durable claim) — the stronger entry point. "Codex
 *      Start Contract" lists the fields the start contract MUST include (a
 *      superset that also carries claim/reservation/lease and the explicit
 *      command set). The doc: "--codex-start-packet may durably claim one
 *      packet and return a start contract."
 *
 * Hard prohibitions ("Non Goals"), each a STOP CONDITION — if a candidate
 * packet declares any of them, the packet is REJECTED no matter how complete
 * its fields are:
 *   - Do not dispatch work.
 *   - Do not auto-start another process.
 *   - Do not auto-merge.
 *   - Do not grant execution authority beyond the user's active implementation
 *     request.
 *   - Do not mark completion.
 *   - Do not hide current blockers.
 *
 * The doc is also explicit that even a valid Codex Start Packet keeps dispatch,
 * auto-merge and completion as SEPARATE governed steps: this service therefore
 * only ever certifies that a packet may let one session continue safely; it
 * never itself dispatches, claims, merges or completes.
 *
 * Pure and deterministic: same input always yields the same verdict. No
 * randomness, no clock, no I/O.
 *
 * @see docs/engineering-knowledge-base/self-construction/single-session-instruction-packet-contract.md
 */
final class AtlasSingleSessionInstructionPacketContractService
{
    /** Stable schema id for the verdict this service emits. */
    public const SCHEMA_VERSION = 'atlas.aaeos.single_session_instruction_packet_contract.v1';

    public const MODE = 'read_only_single_session_packet_contract_validation';

    public const KIND_INSTRUCTION = 'instruction_packet';

    public const KIND_CODEX_START = 'codex_start_packet';

    public const VERDICT_ACCEPT = 'accept';

    public const VERDICT_REJECT = 'reject';

    /**
     * Required fields for a deterministic read-only Instruction Packet, exactly
     * as the doc's "Purpose / It must include" list. Order is the doc order.
     *
     * @var list<string>
     */
    private const INSTRUCTION_REQUIRED_FIELDS = [
        'selected_packet_id',
        'operator_instruction',     // one-line operator instruction
        'required_first_commands',
        'allowed_files',
        'forbidden_hot_scopes',
        'required_gates',
        'evidence_expected',
        'stop_conditions',
        'bootstrap_hash',
        'readiness_hash',
    ];

    /**
     * Required fields a Codex Start Contract MUST include, exactly as the doc's
     * "Codex Start Contract / The start contract must include" list. This is the
     * stronger superset: it carries the durable claim plus the explicit command
     * set the doc enumerates.
     *
     * @var list<string>
     */
    private const START_CONTRACT_REQUIRED_FIELDS = [
        'claimed_packet_id',
        'actor_id',
        'session_id',
        'one_line_user_prompt',
        'operator_prompt',          // for a session with no chat history
        'mission',
        'reservation_id',
        'lease_expiry',
        'claim_hash',
        'allowed_files',
        'forbidden_hot_scopes',
        'bootstrap_command',
        'scope_validator_command',
        'required_first_commands',
        'required_gates',
        'evidence_required',
        'implementation_rules',
        'final_response_contract',
        'completion_command',
        'release_command',
    ];

    /**
     * The doc's "Non Goals" expressed as boolean prohibition flags. If a
     * candidate packet sets any of these truthy, it is a hard STOP CONDITION and
     * the packet is rejected. `code` is the machine reason emitted.
     *
     * @var array<string, string>
     */
    private const PROHIBITION_FLAGS = [
        'dispatches_work' => 'non_goal_dispatch_work',
        'auto_starts_process' => 'non_goal_auto_start_process',
        'auto_merges' => 'non_goal_auto_merge',
        'grants_execution_authority' => 'non_goal_grant_execution_authority',
        'marks_completion' => 'non_goal_mark_completion',
        'hides_blockers' => 'non_goal_hide_blockers',
    ];

    /**
     * Validate a candidate single-session packet against the contract.
     *
     * @param  array<string, mixed>  $packet
     * @param  string  $kind  self::KIND_INSTRUCTION | self::KIND_CODEX_START
     * @return array{
     *     schema_version:string,
     *     mode:string,
     *     kind:string,
     *     verdict:string,
     *     accepted:bool,
     *     missing_fields:list<string>,
     *     prohibition_violations:list<string>,
     *     required_field_count:int,
     *     present_field_count:int,
     *     self_contained:bool,
     *     dispatch_remains_separate:bool,
     *     reasons:list<string>,
     *     runtime_authorized:bool,
     *     dispatched:bool
     * }
     */
    public function validate(array $packet, string $kind = self::KIND_INSTRUCTION): array
    {
        $kind = $this->normalizeKind($kind);

        $required = $kind === self::KIND_CODEX_START
            ? self::START_CONTRACT_REQUIRED_FIELDS
            : self::INSTRUCTION_REQUIRED_FIELDS;

        $missing = [];
        foreach ($required as $field) {
            if (! $this->fieldPresent($packet, $field)) {
                $missing[] = $field;
            }
        }

        $violations = [];
        foreach (self::PROHIBITION_FLAGS as $flag => $code) {
            if ($this->flagTruthy($packet, $flag)) {
                $violations[] = $code;
            }
        }

        $reasons = [];
        foreach ($missing as $field) {
            $reasons[] = 'missing_required_field:'.$field;
        }
        foreach ($violations as $code) {
            $reasons[] = $code;
        }

        // The packet is self-contained only when every documented field the AI
        // would need to act without chat history is present.
        $selfContained = $missing === [];

        // A packet is acceptable only when it is self-contained AND declares
        // none of the Non-Goals. The two checks are independent gates; failing
        // either rejects.
        $accepted = $selfContained && $violations === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'kind' => $kind,
            'verdict' => $accepted ? self::VERDICT_ACCEPT : self::VERDICT_REJECT,
            'accepted' => $accepted,
            'missing_fields' => array_values($missing),
            'prohibition_violations' => array_values($violations),
            'required_field_count' => count($required),
            'present_field_count' => count($required) - count($missing),
            'self_contained' => $selfContained,
            // Invariant from the doc: dispatch/auto-merge/completion always stay
            // separate governed steps; certifying a packet never collapses them.
            'dispatch_remains_separate' => true,
            'reasons' => $reasons,
            // This service is read-only: it never authorizes runtime and never
            // dispatches, regardless of verdict.
            'runtime_authorized' => false,
            'dispatched' => false,
        ];
    }

    /**
     * The required-field lists, exposed for callers/tests that need the exact
     * documented contract surface.
     *
     * @return array{instruction:list<string>,codex_start:list<string>}
     */
    public function requiredFields(): array
    {
        return [
            'instruction' => self::INSTRUCTION_REQUIRED_FIELDS,
            'codex_start' => self::START_CONTRACT_REQUIRED_FIELDS,
        ];
    }

    /**
     * The closed set of Non-Goal prohibition codes the contract enforces.
     *
     * @return list<string>
     */
    public function prohibitionCodes(): array
    {
        return array_values(self::PROHIBITION_FLAGS);
    }

    /**
     * A worked, minimally-complete Instruction Packet that satisfies the
     * contract. Useful as a template and as the command's self-describing
     * sample. Every Non-Goal flag is explicitly false and never hides blockers.
     *
     * @return array<string, mixed>
     */
    public function sampleInstructionPacket(string $packetId = 'packet-sample'): array
    {
        return [
            'selected_packet_id' => $packetId,
            'operator_instruction' => 'Continue the claimed Self-Construction slice within the declared scope only.',
            'required_first_commands' => [
                'php artisan atlas:ai:session-bootstrap --task="continue packet" --json',
            ],
            'allowed_files' => ['app/Services/Ai/Aaeos/Generated'],
            'forbidden_hot_scopes' => ['app/Providers', 'database/migrations'],
            'required_gates' => ['php artisan atlas:engineering:knowledge docs-health --json'],
            'evidence_expected' => ['docs-health status ok', 'passing unit test'],
            'stop_conditions' => ['scope breach', 'gate red', 'evidence missing'],
            'bootstrap_hash' => 'sha256:bootstrap-placeholder',
            'readiness_hash' => 'sha256:readiness-placeholder',
            'open_blockers' => [], // present (not hidden), simply empty
            // Non-Goals, all explicitly refused:
            'dispatches_work' => false,
            'auto_starts_process' => false,
            'auto_merges' => false,
            'grants_execution_authority' => false,
            'marks_completion' => false,
            'hides_blockers' => false,
        ];
    }

    /**
     * Self-describe the contract and run a worked accept + reject pair, proving
     * the validator is live. Read-only.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        $sample = $this->sampleInstructionPacket();
        $accepted = $this->validate($sample, self::KIND_INSTRUCTION);

        // A reject sample: drop one field AND declare a Non-Goal.
        $bad = $sample;
        unset($bad['stop_conditions']);
        $bad['auto_merges'] = true;
        $rejected = $this->validate($bad, self::KIND_INSTRUCTION);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'instruction_required_field_count' => count(self::INSTRUCTION_REQUIRED_FIELDS),
            'codex_start_required_field_count' => count(self::START_CONTRACT_REQUIRED_FIELDS),
            'prohibition_count' => count(self::PROHIBITION_FLAGS),
            'prohibition_codes' => $this->prohibitionCodes(),
            'sample_accept' => $accepted,
            'sample_reject' => $rejected,
            'dispatch_remains_separate' => true,
            'runtime_authorized' => false,
            'dispatched' => false,
        ];
    }

    private function normalizeKind(string $kind): string
    {
        return $kind === self::KIND_CODEX_START
            ? self::KIND_CODEX_START
            : self::KIND_INSTRUCTION;
    }

    /**
     * A field counts as present only when it carries real content. Empty
     * strings, empty arrays and null do not satisfy a required field — the doc
     * requires a self-contained packet, and "looks present" is not present.
     *
     * @param  array<string, mixed>  $packet
     */
    private function fieldPresent(array $packet, string $field): bool
    {
        if (! array_key_exists($field, $packet)) {
            return false;
        }

        $value = $packet[$field];

        if ($value === null) {
            return false;
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
     * @param  array<string, mixed>  $packet
     */
    private function flagTruthy(array $packet, string $flag): bool
    {
        return array_key_exists($flag, $packet) && (bool) $packet[$flag] === true;
    }
}
