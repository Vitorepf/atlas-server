<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Self-Construction Packet Completion Gate — pure, deterministic completion decider.
 *
 * Converts a packet evidence report into a controlled completion decision. It
 * answers: can this packet be completed, what blocks completion, which evidence
 * is missing, whether human review is required, and what the next action must
 * be. It is read-only: it may RECOMMEND a completion candidate but it never
 * persists durable completion, never writes the Evidence Ledger and never
 * authorizes execution.
 *
 * Contract (from the doc Gate Schema + Decision Rules + Required Evidence):
 *   Entrada: selected_packet_id, evidence_report_status (blocked|clean),
 *            evidence_report_hash, scope_validator_status (pass|fail|blocked),
 *            blocking_reasons[], external_blockers[] (each {path}),
 *            required_gate_statuses[] (each {id, status: pass|fail|missing}),
 *            required_evidence_statuses[] (each {id, status: present|missing}),
 *            human_review_present (bool).
 *   Saida:   schema_version, gate_id, selected_packet_id,
 *            status (blocked|human_review_required|completion_candidate),
 *            execution_allowed=false, completion_allowed=false,
 *            durable_completion_written=false,
 *            decision (block|request_human_review|candidate_only),
 *            blocking_reasons[], required_next_action.
 *
 * Documented Decision Rules this code genuinely enforces:
 *   - "If evidence report is blocked, status must be `blocked`." => any blocked
 *     evidence report (or a scope validator that is fail/blocked, a failing or
 *     missing required gate, a missing required evidence item, or ANY external
 *     blocker) forces status=blocked / decision=block. External blockers must
 *     prevent automated completion claims (frontmatter decision).
 *   - "If evidence report is clean but no human review exists, status must be
 *     `human_review_required`." => a clean report without human_review_present
 *     yields status=human_review_required / decision=request_human_review.
 *   - "If evidence report is clean and human review exists, read-only status may
 *     be `completion_candidate`." => only then status=completion_candidate /
 *     decision=candidate_only.
 *   - "`completion_allowed` remains false until durable completion persistence is
 *     implemented by a future AP." => completion_allowed is ALWAYS false here.
 *
 * Non-goals honoured (read-only): it does NOT persist packet completion
 * (durable_completion_written=false always), does NOT write evidence ledger
 * events, does NOT override the packet evidence report (a blocked report can
 * never be promoted), does NOT authorize execution (execution_allowed=false
 * always) and does NOT merge or clean up external hot changes.
 *
 * @see docs/engineering-knowledge-base/self-construction/packet-completion-gate-contract.md
 */
final class AtlasPacketCompletionGateContractService
{
    /** Stable gate schema id this decider emits (from Gate Schema). */
    public const SCHEMA = 'atlas.self_construction_packet_completion_gate.v1';

    /** Output statuses (closed set, from Gate Schema). */
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_HUMAN_REVIEW_REQUIRED = 'human_review_required';
    public const STATUS_COMPLETION_CANDIDATE = 'completion_candidate';

    /** Output decisions (closed set, from Gate Schema). */
    public const DECISION_BLOCK = 'block';
    public const DECISION_REQUEST_HUMAN_REVIEW = 'request_human_review';
    public const DECISION_CANDIDATE_ONLY = 'candidate_only';

    /** Upstream evidence-report status this gate reads (closed set). */
    public const REPORT_BLOCKED = 'blocked';
    public const REPORT_CLEAN = 'clean';

    /** Scope validator statuses (closed set). */
    public const SCOPE_PASS = 'pass';
    public const SCOPE_FAIL = 'fail';
    public const SCOPE_BLOCKED = 'blocked';

    /** Per required-gate statuses (closed set). */
    public const GATE_PASS = 'pass';
    public const GATE_FAIL = 'fail';
    public const GATE_MISSING = 'missing';

    /** Per required-evidence statuses (closed set). */
    public const EVIDENCE_PRESENT = 'present';
    public const EVIDENCE_MISSING = 'missing';

    /**
     * Decide the completion gate outcome for one selected packet.
     *
     * @param array<string, mixed> $input
     * @return array{
     *   schema_version: string,
     *   gate_id: string,
     *   selected_packet_id: string,
     *   evidence_report_status: string,
     *   evidence_report_hash: string,
     *   scope_validator_status: string,
     *   status: string,
     *   execution_allowed: bool,
     *   completion_allowed: bool,
     *   durable_completion_written: bool,
     *   decision: string,
     *   human_review_present: bool,
     *   blocking_reasons: list<string>,
     *   external_blockers: list<array{path: string}>,
     *   missing_evidence: list<string>,
     *   required_next_action: string
     * }
     */
    public function decide(array $input): array
    {
        $packetId = $this->str($input['selected_packet_id'] ?? '', 'AIP-SPLIT-UNKNOWN');
        $reportHash = $this->str($input['evidence_report_hash'] ?? '', '');
        $reportStatus = $this->normaliseReportStatus($input['evidence_report_status'] ?? null);
        $scopeStatus = $this->str($input['scope_validator_status'] ?? self::SCOPE_PASS, self::SCOPE_PASS);
        $humanReviewPresent = (bool) ($input['human_review_present'] ?? false);

        $gateStatuses = $this->pairs($input['required_gate_statuses'] ?? [], 'id', 'status');
        $evidenceStatuses = $this->pairs($input['required_evidence_statuses'] ?? [], 'id', 'status');
        $externalBlockers = $this->externalBlockers($input['external_blockers'] ?? []);
        $upstreamReasons = $this->stringList($input['blocking_reasons'] ?? []);

        // ---- Gather blocking reasons (these define whether the report is truly clean) ----
        $blockingReasons = [];

        // 1. Upstream evidence report explicitly blocked.
        if ($reportStatus === self::REPORT_BLOCKED) {
            $blockingReasons[] = 'evidence_report_blocked';
        }

        // 2. Scope validator not passing (fail or blocked both block completion).
        if ($scopeStatus !== self::SCOPE_PASS) {
            $blockingReasons[] = 'scope_validator_' . $scopeStatus;
        }

        // 3. Any required gate failing or missing.
        foreach ($gateStatuses as $gate) {
            if ($gate['status'] === self::GATE_FAIL) {
                $blockingReasons[] = 'required_gate_failed:' . $gate['id'];
            } elseif ($gate['status'] === self::GATE_MISSING) {
                $blockingReasons[] = 'required_gate_missing:' . $gate['id'];
            }
        }

        // 4. Any required evidence missing.
        $missingEvidence = [];
        foreach ($evidenceStatuses as $ev) {
            if ($ev['status'] !== self::EVIDENCE_PRESENT) {
                $missingEvidence[] = $ev['id'];
                $blockingReasons[] = 'required_evidence_missing:' . $ev['id'];
            }
        }

        // 5. External blockers must prevent automated completion claims (frontmatter
        //    decision). They are reported separately AND force a block.
        foreach ($externalBlockers as $blocker) {
            $blockingReasons[] = 'external_blocker:' . $blocker['path'];
        }

        // 6. Preserve any caller-supplied upstream blocking reasons verbatim.
        foreach ($upstreamReasons as $reason) {
            $blockingReasons[] = $reason;
        }

        $blockingReasons = $this->dedupe($blockingReasons);

        // ---- Apply the documented Decision Rules in strict precedence ----
        if ($blockingReasons !== []) {
            // Rule 1: blocked report (or any blocking condition) => status blocked.
            $status = self::STATUS_BLOCKED;
            $decision = self::DECISION_BLOCK;
            $nextAction = 'Resolve blocking reasons (re-run upstream evidence report, fix scope/gates/evidence, clear external blockers) before re-evaluating the completion gate.';
        } elseif (! $humanReviewPresent) {
            // Rule 2: clean report, no human review => human review required.
            $status = self::STATUS_HUMAN_REVIEW_REQUIRED;
            $decision = self::DECISION_REQUEST_HUMAN_REVIEW;
            $nextAction = 'Request human review of the clean evidence report; completion stays unauthorized until a reviewer signs off.';
        } else {
            // Rule 3: clean report AND human review => read-only completion candidate.
            $status = self::STATUS_COMPLETION_CANDIDATE;
            $decision = self::DECISION_CANDIDATE_ONLY;
            $nextAction = 'Record completion candidate only; durable completion remains disabled until a future persistence AP implements it.';
        }

        return [
            'schema_version' => self::SCHEMA,
            'gate_id' => $this->gateId($packetId, $reportHash),
            'selected_packet_id' => $packetId,
            'evidence_report_status' => $reportStatus,
            'evidence_report_hash' => $reportHash,
            'scope_validator_status' => $scopeStatus,
            'status' => $status,
            // Non Goals: gate never authorizes execution, never persists durable
            // completion, and completion_allowed stays false until a future AP.
            'execution_allowed' => false,
            'completion_allowed' => false,
            'durable_completion_written' => false,
            'decision' => $decision,
            'human_review_present' => $humanReviewPresent,
            'blocking_reasons' => $blockingReasons,
            'external_blockers' => $externalBlockers,
            'missing_evidence' => $missingEvidence,
            'required_next_action' => $nextAction,
        ];
    }

    /**
     * Normalise the upstream evidence-report status into the closed set.
     * Anything that is not explicitly "clean" is treated as blocked — a gate
     * must never optimistically promote an ambiguous upstream signal.
     */
    private function normaliseReportStatus(mixed $raw): string
    {
        $value = is_string($raw) ? strtolower(trim($raw)) : '';

        // The upstream evidence report contract emits completion_ready|blocked.
        // Treat completion_ready as the "clean" signal this gate consumes.
        if ($value === self::REPORT_CLEAN || $value === 'completion_ready' || $value === 'ready') {
            return self::REPORT_CLEAN;
        }

        return self::REPORT_BLOCKED;
    }

    /**
     * Deterministic gate id derived from packet + evidence hash so the same
     * inputs always produce the same id (read-only, no clock dependency).
     */
    private function gateId(string $packetId, string $reportHash): string
    {
        $seed = $packetId . '|' . $reportHash;
        $digest = substr(sha1($seed), 0, 4);

        return 'COMPLETION-GATE-' . strtoupper($digest) . '-0001';
    }

    /**
     * @param mixed $raw
     * @return list<array{path: string}>
     */
    private function externalBlockers(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (is_string($item)) {
                $path = trim($item);
            } elseif (is_array($item) && isset($item['path']) && is_string($item['path'])) {
                $path = trim($item['path']);
            } else {
                continue;
            }

            if ($path !== '') {
                $out[] = ['path' => $path];
            }
        }

        return $out;
    }

    /**
     * Parse a list of {leftKey, rightKey} maps, keeping only well-formed rows.
     *
     * @param mixed $raw
     * @return list<array<string, string>>
     */
    private function pairs(mixed $raw, string $leftKey, string $rightKey): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $left = isset($row[$leftKey]) && is_string($row[$leftKey]) ? trim($row[$leftKey]) : '';
            if ($left === '') {
                continue;
            }
            $right = isset($row[$rightKey]) && is_string($row[$rightKey]) ? trim($row[$rightKey]) : '';
            $out[] = [$leftKey => $left, $rightKey => $right];
        }

        return $out;
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    private function stringList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return $out;
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function dedupe(array $values): array
    {
        return array_values(array_unique($values));
    }

    private function str(mixed $raw, string $default): string
    {
        if (is_string($raw) && trim($raw) !== '') {
            return trim($raw);
        }

        return $default;
    }
}
