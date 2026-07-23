<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas Self-Construction · Packet Consumption Runbook Contract — runtime.
 *
 * The Packet Consumption Runbook is the ordered instruction set an AI MUST
 * follow AFTER Atlas selects a packet for a session. It turns an assignment
 * preview into a safe execution ritual:
 *
 *   inspect -> read packet -> confirm scope -> implement only allowed files
 *   -> run gates -> validate scope -> return evidence -> stop
 *
 * This service is a sibling — NOT a duplicate — of the AI Implementation
 * Packet Contract validator: that one judges the PACKET ARTIFACT (admission +
 * completion). This one BUILDS the runbook for a selected packet, enforces the
 * mandatory ordered steps, the conditional gate rules, the evidence contract
 * and the stop conditions. Every method is a pure function of its arguments —
 * no DB, no IO, no provider calls.
 *
 * Documented contract this code enforces (from the doc):
 *
 *   Runbook Schema (closed shape):
 *     - schema_version  = atlas.self_construction_packet_consumption_runbook.v1
 *     - execution_allowed = false   (read-only until a signed receipt permits)
 *     - claim_persisted   = false   (Non Goal: "Do not persist claims.")
 *     - steps, required_gates, required_evidence, stop_conditions,
 *       final_response_contract.
 *
 *   Mandatory Steps (every runbook must include, IN ORDER):
 *     inspect_worktree, read_canonical_docs_and_packet, confirm_allowed_and_
 *     forbidden_files, confirm_execution_policy, implement_only_if_asked,
 *     run_focused_tests, run_docs_health_if_docs_changed,
 *     run_architecture_validate_if_architecture_changed, run_scope_validator,
 *     report_evidence_and_residual_risk.
 *
 *   Conditional gate rules:
 *     - "run docs-health when docs changed" — docs-health is required IFF the
 *       packet touches docs/.
 *     - "run architecture validate when architecture/governance docs changed" —
 *       architecture-validate is required IFF the packet touches an architecture
 *       or governance doc.
 *
 *   Evidence Contract — the final response MUST include all seven fields:
 *     selected_packet_id, files_changed, gates_run, pass_fail_status,
 *     scope_validator_status, known_external_blockers, what_remains_blocked.
 *
 *   Stop Conditions — the AI MUST stop when ANY holds:
 *     selected packet missing, packet hash changed, forbidden file changed,
 *     unknown file changed, hot external file in assigned scope, required gate
 *     failed, user request conflicts with packet scope.
 *
 *   Non Goals honoured (this service never widens authority): it does NOT
 *   persist claims, does NOT authorize writes, does NOT replace Decision
 *   Receipt, does NOT allow broad "continue everything" behaviour, and does NOT
 *   accept completion without machine-readable evidence.
 *
 * @see docs/engineering-knowledge-base/self-construction/packet-consumption-runbook-contract.md
 */
final class AtlasPacketConsumptionRunbookContractRtService
{
    /** Stable runbook schema id, exactly as declared in the doc. */
    public const SCHEMA_VERSION = 'atlas.self_construction_packet_consumption_runbook.v1';

    public const MODE = 'read_only_packet_consumption_runbook_builder';

    /**
     * Mandatory Steps, in the canonical order the doc lists them. A runbook is
     * structurally invalid if any is missing or out of order.
     *
     * @var list<string>
     */
    public const MANDATORY_STEPS = [
        'inspect_worktree',
        'read_canonical_docs_and_packet',
        'confirm_allowed_and_forbidden_files',
        'confirm_execution_policy',
        'implement_only_if_asked',
        'run_focused_tests',
        'run_docs_health_if_docs_changed',
        'run_architecture_validate_if_architecture_changed',
        'run_scope_validator',
        'report_evidence_and_residual_risk',
    ];

    /**
     * Evidence Contract — the seven fields the final response must carry.
     *
     * @var list<string>
     */
    public const EVIDENCE_CONTRACT_FIELDS = [
        'selected_packet_id',
        'files_changed',
        'gates_run',
        'pass_fail_status',
        'scope_validator_status',
        'known_external_blockers',
        'what_remains_blocked',
    ];

    /**
     * The seven documented Stop Conditions (closed set of codes).
     *
     * @var list<string>
     */
    public const STOP_CONDITION_CODES = [
        'selected_packet_missing',
        'packet_hash_changed',
        'forbidden_file_changed',
        'unknown_file_changed',
        'hot_external_file_in_scope',
        'required_gate_failed',
        'user_request_conflicts_with_packet_scope',
    ];

    /** Gates that are always part of the ritual regardless of touched paths. */
    public const ALWAYS_GATES = ['focused_tests', 'scope_validator'];

    /** Verdicts for a built runbook. */
    public const VERDICT_READY = 'ready';
    public const VERDICT_STOP = 'stop';
    public const VERDICT_INVALID = 'invalid';

    /**
     * Build the deterministic runbook for a selected packet and assignment.
     *
     * The runbook is ALWAYS read-only (execution_allowed=false, claim_persisted=
     * false). It computes the required gates from the touched files, lists the
     * mandatory steps in order, attaches the evidence contract, and evaluates the
     * stop conditions against the supplied live state.
     *
     * @param array<string,mixed> $input {
     *   assignment_id           : string,
     *   selected_packet_id      : ?string  (null/empty => selected packet missing),
     *   files_in_scope          : list<string>  paths the packet may write,
     *   forbidden_files         : list<string>,
     *   hot_external_files      : list<string>  files owned by another session,
     *   implementation_requested: bool   has the user/governance asked to implement,
     *   live                    : array<string,mixed> {
     *       packet_hash_at_selection : ?string,
     *       packet_hash_now          : ?string,
     *       changed_files            : list<string>  what the AI actually touched,
     *       failed_gates             : list<string>  gates already known to have failed,
     *       user_request_conflicts   : bool,
     *   },
     *   runbook_id              : ?string,
     * }
     *
     * @return array<string,mixed> the runbook document
     */
    public function build(array $input): array
    {
        $assignmentId = $this->str($input['assignment_id'] ?? null) ?? 'ASSIGN-UNKNOWN';
        $selectedPacketId = $this->str($input['selected_packet_id'] ?? null);
        $runbookId = $this->str($input['runbook_id'] ?? null) ?? 'RUNBOOK-UNKNOWN';

        $filesInScope = $this->paths($input['files_in_scope'] ?? []);
        $forbidden = $this->paths($input['forbidden_files'] ?? []);
        $hotFiles = $this->paths($input['hot_external_files'] ?? []);
        $implementationRequested = ($input['implementation_requested'] ?? false) === true;

        $requiredGates = $this->requiredGates($filesInScope);
        $stops = $this->evaluateStopConditions($input);
        $hasStop = $stops !== [];

        // The runbook is "ready" only if it is structurally well-formed AND no
        // stop condition fired. A missing selected packet is itself a stop.
        $verdict = $hasStop ? self::VERDICT_STOP : self::VERDICT_READY;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'runbook_id' => $runbookId,
            'assignment_id' => $assignmentId,
            'selected_packet_id' => $selectedPacketId,
            // Non Goals: never self-authorize, never persist a claim.
            'execution_allowed' => false,
            'claim_persisted' => false,
            'implementation_requested' => $implementationRequested,
            'steps' => self::MANDATORY_STEPS,
            'required_gates' => $requiredGates,
            'required_evidence' => self::EVIDENCE_CONTRACT_FIELDS,
            'stop_conditions' => self::STOP_CONDITION_CODES,
            'final_response_contract' => self::EVIDENCE_CONTRACT_FIELDS,
            'verdict' => $verdict,
            'must_stop' => $hasStop,
            'triggered_stops' => $stops,
            'context' => [
                'files_in_scope' => $filesInScope,
                'forbidden_files' => $forbidden,
                'hot_external_files' => $hotFiles,
                'docs_touched' => $this->touchesDocs($filesInScope),
                'architecture_touched' => $this->touchesArchitecture($filesInScope),
            ],
        ];
    }

    /**
     * Compute required gates from touched files (the conditional gate rules).
     * focused_tests + scope_validator are always required; docs_health is added
     * IFF docs changed; architecture_validate IFF architecture/governance docs
     * changed.
     *
     * @param list<string> $filesInScope
     * @return list<string>
     */
    public function requiredGates(array $filesInScope): array
    {
        $gates = self::ALWAYS_GATES;
        if ($this->touchesDocs($filesInScope)) {
            $gates[] = 'docs_health';
        }
        if ($this->touchesArchitecture($filesInScope)) {
            $gates[] = 'architecture_validate';
        }

        return array_values(array_unique($gates));
    }

    /**
     * Evaluate the seven documented Stop Conditions against live state.
     * Returns the list of fired stop conditions (empty => safe to proceed).
     *
     * @param array<string,mixed> $input same shape as build()
     * @return list<array{code:string,message:string}>
     */
    public function evaluateStopConditions(array $input): array
    {
        $live = is_array($input['live'] ?? null) ? $input['live'] : [];
        $stops = [];

        // 1. selected packet is missing.
        if ($this->str($input['selected_packet_id'] ?? null) === null) {
            $stops[] = $this->reason('selected_packet_missing',
                'Selected packet id is missing; nothing to consume.');
        }

        // 2. packet hash changed between selection and now.
        $hashAt = $this->str($live['packet_hash_at_selection'] ?? null);
        $hashNow = $this->str($live['packet_hash_now'] ?? null);
        if ($hashAt !== null && $hashNow !== null && $hashAt !== $hashNow) {
            $stops[] = $this->reason('packet_hash_changed',
                'Packet hash changed since selection; the packet is no longer the one that was read.');
        }

        $forbidden = $this->paths($input['forbidden_files'] ?? []);
        $scope = $this->paths($input['files_in_scope'] ?? []);
        $hot = $this->paths($input['hot_external_files'] ?? []);
        $changed = $this->paths($live['changed_files'] ?? []);

        // 3. a forbidden file was changed.
        foreach ($this->intersect($changed, $forbidden) as $f) {
            $stops[] = $this->reason('forbidden_file_changed',
                "Forbidden file '{$f}' was changed.");
        }

        // 4. an unknown file (outside the assigned write set) was changed.
        foreach ($changed as $f) {
            if (! $this->inList($f, $scope)) {
                $stops[] = $this->reason('unknown_file_changed',
                    "File '{$f}' was changed but is not in the assigned scope.");
            }
        }

        // 5. a hot external file appears in the assigned scope.
        foreach ($this->intersect($scope, $hot) as $f) {
            $stops[] = $this->reason('hot_external_file_in_scope',
                "Hot external file '{$f}' (owned by another session) is inside the assigned scope.");
        }

        // 6. a required gate failed.
        $failed = $this->paths($live['failed_gates'] ?? []);
        foreach ($failed as $g) {
            $stops[] = $this->reason('required_gate_failed',
                "Required gate '{$g}' failed.");
        }

        // 7. the user request conflicts with packet scope.
        if (($live['user_request_conflicts'] ?? false) === true) {
            $stops[] = $this->reason('user_request_conflicts_with_packet_scope',
                'User request conflicts with the packet scope; stop and reconcile.');
        }

        return $stops;
    }

    /**
     * Validate that a final response satisfies the Evidence Contract: all seven
     * fields present and non-empty. files_changed / gates_run may be empty arrays
     * (a packet can legitimately change nothing) but the KEY must be present.
     *
     * @param array<string,mixed> $response
     * @return array<string,mixed> {accepted:bool, missing_fields:list<string>, verdict:string}
     */
    public function validateEvidenceContract(array $response): array
    {
        $missing = [];
        foreach (self::EVIDENCE_CONTRACT_FIELDS as $field) {
            if (! array_key_exists($field, $response)) {
                $missing[] = $field;

                continue;
            }
            // List-typed fields just need the key; string/status fields need a value.
            if (in_array($field, ['files_changed', 'gates_run', 'known_external_blockers', 'what_remains_blocked'], true)) {
                if (! is_array($response[$field])) {
                    $missing[] = $field;
                }

                continue;
            }
            if ($this->str($response[$field]) === null) {
                $missing[] = $field;
            }
        }

        $accepted = $missing === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'accepted' => $accepted,
            'verdict' => $accepted ? 'accepted' : 'rejected',
            'missing_fields' => array_values(array_unique($missing)),
        ];
    }

    /**
     * Are the mandatory steps present, complete and in the canonical order?
     *
     * @param list<string> $steps
     */
    public function stepsValid(array $steps): bool
    {
        return array_values($steps) === self::MANDATORY_STEPS;
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /** @param list<string> $files */
    private function touchesDocs(array $files): bool
    {
        foreach ($files as $f) {
            if (str_starts_with(strtolower($f), 'docs/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Architecture / governance docs: anything under docs/ap/, an AP-*.md file,
     * or a governance/architecture knowledge doc. The doc says architecture
     * validate runs "when architecture/governance docs changed".
     *
     * @param list<string> $files
     */
    private function touchesArchitecture(array $files): bool
    {
        foreach ($files as $f) {
            $low = strtolower($f);
            if (str_starts_with($low, 'docs/ap/')) {
                return true;
            }
            if (str_contains($low, '/ap-') || str_contains($low, 'governance') || str_contains($low, 'architecture')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     * @return list<string>
     */
    private function intersect(array $a, array $b): array
    {
        $lowerB = array_map('strtolower', $b);
        $out = [];
        foreach ($a as $entry) {
            if (in_array(strtolower($entry), $lowerB, true)) {
                $out[] = $entry;
            }
        }

        return array_values(array_unique($out));
    }

    /** @param list<string> $list */
    private function inList(string $needle, array $list): bool
    {
        return in_array(strtolower($needle), array_map('strtolower', $list), true);
    }

    /** @return array{code:string,message:string} */
    private function reason(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }

    /**
     * @param mixed $paths
     * @return list<string>
     */
    private function paths(mixed $paths): array
    {
        if (! is_array($paths)) {
            return [];
        }

        $clean = [];
        foreach ($paths as $p) {
            if (is_string($p) && trim($p) !== '') {
                $clean[] = trim($p);
            }
        }

        return array_values(array_unique($clean));
    }

    private function str(mixed $v): ?string
    {
        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }

        return null;
    }
}
