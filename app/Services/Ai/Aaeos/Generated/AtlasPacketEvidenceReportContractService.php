<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Self-Construction Packet Evidence Report — pure, deterministic completion decider.
 *
 * Decides whether a selected implementation packet has enough evidence to be
 * considered complete. It compares the selected packet, assignment preview,
 * runbook, scope validator, required gates, evidence items and external
 * blockers, then emits a machine-readable report so that another AI can know
 * if its packet is complete, blocked or needs review WITHOUT trusting any
 * free-form "done" text.
 *
 * Contract (from the doc Report Schema + Completion Rules + Blocked States):
 *   Entrada: selected_packet_id, runbook_exists, assignment_preview_exists,
 *            scope_validator_status (pass|fail|blocked),
 *            required_gates[] each {id, status: pass|fail|missing},
 *            required_evidence[] (ids), present_evidence[] (ids),
 *            packet_owned_files[] each classification (allowed|forbidden|
 *            unknown|hot_external|...), residual_risk (low|medium|high),
 *            residual_risk_accepted_by_review (bool), external_blockers[].
 *   Saida:   status (completion_ready|blocked), execution_allowed=false,
 *            completion_allowed (true ONLY when every completion rule holds),
 *            evidence_ledger_write_allowed=false, gate_results[],
 *            evidence_results[], blocking_reasons[], external_blockers[],
 *            required_next_action.
 *
 * Documented rules this code genuinely enforces:
 *   - Completion Rules (ALL required): selected packet exists; runbook exists;
 *     Scope Validator passes; required gates present; required evidence present;
 *     no forbidden/unknown/hot external file owned by the packet; residual risk
 *     low OR accepted by review. Any miss => completion_allowed=false.
 *   - Blocked States (ANY triggers status=blocked): Scope Validator blocked;
 *     a required gate is missing or failed (tests / architecture validation /
 *     docs-health / `git diff --check`); required evidence missing; external hot
 *     files mixed with packet-owned scope.
 *   - Decision: "A narrative 'done' response is never sufficient evidence."
 *       => evidence is decided ONLY from present_evidence ids, never from prose.
 *   - Decision: "External hot blockers must be reported separately from
 *     packet-owned failures."
 *       => external_blockers stay in their own array and produce their own
 *          blocking_reason; they never merge into gate/evidence failures.
 *   - Read-Only Phase: completion_allowed=false and
 *     evidence_ledger_write_allowed=false are ALWAYS emitted; status flips to
 *     blocked whenever the worktree contains hot external blockers.
 *
 * Non-goals honoured (read-only): it does NOT write the Evidence Ledger, does
 * NOT mark a packet completed, does NOT override failed gates, does NOT ignore
 * hot external files and does NOT create a durable claim. It only decides and
 * emits evidence.
 *
 * @see docs/engineering-knowledge-base/self-construction/packet-evidence-report-contract.md
 */
final class AtlasPacketEvidenceReportContractService
{
    /** Stable report schema id this decider emits. */
    public const SCHEMA = 'atlas.self_construction_packet_evidence_report.v1';

    /** Output statuses (closed set, from Report Schema). */
    public const STATUS_COMPLETION_READY = 'completion_ready';
    public const STATUS_BLOCKED = 'blocked';

    /** Per-gate statuses (closed set). */
    public const GATE_PASS = 'pass';
    public const GATE_FAIL = 'fail';
    public const GATE_MISSING = 'missing';

    /** Required-next-action verbs (closed set). */
    public const NEXT_HUMAN_REVIEW = 'human_review_before_completion';
    public const NEXT_RESOLVE_BLOCKERS = 'resolve_blockers_and_rerun_evidence_report';

    /**
     * File classifications that are forbidden inside packet-owned scope.
     * Mirrors the Scope Validator contract: forbidden/unknown/hot_external are
     * never safe to own.
     *
     * @var list<string>
     */
    private const UNSAFE_OWNED_CLASSIFICATIONS = ['forbidden', 'unknown', 'hot_external'];

    /** Residual-risk levels that are safe without explicit review acceptance. */
    private const RISK_LOW = 'low';

    /**
     * Decide a packet evidence report from explicit, pre-collected inputs.
     *
     * Pure: no filesystem, no git, no DB. Every decision is a function of the
     * passed arrays so the same input always yields the same report.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function report(array $input = []): array
    {
        $selectedPacketId = $this->stringOrNull($input['selected_packet_id'] ?? null);
        $reportId = $this->stringOrNull($input['report_id'] ?? null) ?? 'EVIDENCE-REPORT-PACKET-0001';

        $packetExists = $selectedPacketId !== null && ($input['selected_packet_exists'] ?? true) !== false;
        $runbookExists = (bool) ($input['runbook_exists'] ?? false);
        $assignmentExists = (bool) ($input['assignment_preview_exists'] ?? false);

        $scopeStatus = $this->stringOrNull($input['scope_validator_status'] ?? null) ?? 'unknown';
        $scopePassed = $scopeStatus === 'pass';
        $scopeBlocked = $scopeStatus === 'blocked';

        $gateResults = $this->evaluateGates($this->listOfMaps($input['required_gates'] ?? []));
        $evidenceResults = $this->evaluateEvidence(
            $this->listOfStrings($input['required_evidence'] ?? []),
            $this->listOfStrings($input['present_evidence'] ?? []),
        );

        $ownedFiles = $this->classifyOwnedFiles($this->listOfMaps($input['packet_owned_files'] ?? []));
        $externalBlockers = $this->listOfMaps($input['external_blockers'] ?? []);
        $hasExternalBlockers = $externalBlockers !== [];

        $residualRisk = $this->stringOrNull($input['residual_risk'] ?? null) ?? self::RISK_LOW;
        $riskAccepted = (bool) ($input['residual_risk_accepted_by_review'] ?? false);
        $riskOk = $residualRisk === self::RISK_LOW || $riskAccepted;

        // ---- Blocked States (ANY triggers status=blocked) ------------------
        $blockingReasons = [];

        if ($scopeBlocked) {
            $blockingReasons[] = 'scope_validator_blocked';
        }

        foreach ($gateResults as $gate) {
            if ($gate['status'] === self::GATE_MISSING) {
                $blockingReasons[] = 'required_gate_missing:'.$gate['id'];
            } elseif ($gate['status'] === self::GATE_FAIL) {
                $blockingReasons[] = 'required_gate_failed:'.$gate['id'];
            }
        }

        foreach ($evidenceResults as $evidence) {
            if (! $evidence['present']) {
                $blockingReasons[] = 'missing_evidence:'.$evidence['id'];
            }
        }

        if ($ownedFiles['unsafe_count'] > 0) {
            $blockingReasons[] = 'packet_owns_unsafe_file_scope';
        }

        // External hot blockers are reported SEPARATELY (own array + own reason).
        if ($hasExternalBlockers) {
            $blockingReasons[] = 'external_hot_blockers_reported';
        }

        $blockingReasons = array_values(array_unique($blockingReasons));

        // ---- Completion Rules (ALL must hold for completion_ready) ---------
        $completionChecks = [
            'selected_packet_exists' => $packetExists,
            'runbook_exists' => $runbookExists,
            'scope_validator_passed' => $scopePassed,
            'required_gates_present' => $this->allGatesPresent($gateResults),
            'required_gates_passed' => $this->allGatesPassed($gateResults),
            'required_evidence_present' => $this->allEvidencePresent($evidenceResults),
            'no_unsafe_owned_file' => $ownedFiles['unsafe_count'] === 0,
            'no_external_hot_blockers' => ! $hasExternalBlockers,
            'residual_risk_low_or_accepted' => $riskOk,
        ];

        $allRulesHold = ! in_array(false, $completionChecks, true) && $blockingReasons === [];

        // Read-Only Phase: completion_ready is only a REVIEW gate. Durable
        // completion + ledger writes require a future AP, so these stay false.
        $status = $allRulesHold ? self::STATUS_COMPLETION_READY : self::STATUS_BLOCKED;

        $report = [
            'schema_version' => self::SCHEMA,
            'report_id' => $reportId,
            'selected_packet_id' => $selectedPacketId,
            'status' => $status,
            'execution_allowed' => false,
            'completion_allowed' => false,
            'evidence_ledger_write_allowed' => false,
            'assignment_preview_present' => $assignmentExists,
            'runbook_present' => $runbookExists,
            'scope_validator_status' => $scopeStatus,
            'residual_risk' => $residualRisk,
            'residual_risk_accepted_by_review' => $riskAccepted,
            'completion_checks' => $completionChecks,
            'gate_results' => $gateResults,
            'evidence_results' => $evidenceResults,
            'owned_file_classification' => $ownedFiles,
            'blocking_reasons' => $blockingReasons,
            'external_blockers' => $externalBlockers,
            'required_next_action' => $status === self::STATUS_COMPLETION_READY
                ? self::NEXT_HUMAN_REVIEW
                : self::NEXT_RESOLVE_BLOCKERS,
        ];

        return [
            'schema_version' => self::SCHEMA,
            'status' => $status,
            'mode' => 'read_only_packet_evidence_report',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'evidence_ledger_write_allowed' => false,
            'report' => $report,
            'report_hash' => $this->stableHash($report),
            'non_execution_guarantees' => [
                'packet_evidence_report_does_not_write_ledger',
                'packet_evidence_report_does_not_mark_completed',
                'packet_evidence_report_does_not_override_failed_gates',
                'packet_evidence_report_does_not_ignore_hot_external_files',
                'packet_evidence_report_does_not_create_durable_claim',
            ],
            'human_summary' => $status === self::STATUS_COMPLETION_READY
                ? 'Packet evidence agrees across gates, scope, evidence and risk; ready for human completion review (no completion marked, no ledger write).'
                : 'Packet completion is blocked until gates, scope, evidence, owned-file scope and external blockers agree.',
        ];
    }

    /**
     * Classify each required gate as pass|fail|missing.
     *
     * @param  list<array<string, mixed>>  $gates
     * @return list<array{id: string, status: string, passed: bool}>
     */
    private function evaluateGates(array $gates): array
    {
        $results = [];
        foreach ($gates as $gate) {
            $id = $this->stringOrNull($gate['id'] ?? null);
            if ($id === null) {
                continue;
            }

            $raw = $this->stringOrNull($gate['status'] ?? null);
            $status = match ($raw) {
                self::GATE_PASS => self::GATE_PASS,
                self::GATE_FAIL => self::GATE_FAIL,
                default => self::GATE_MISSING,
            };

            $results[] = [
                'id' => $id,
                'status' => $status,
                'passed' => $status === self::GATE_PASS,
            ];
        }

        return $results;
    }

    /**
     * Decide evidence presence ONLY from declared present ids — never from prose.
     *
     * @param  list<string>  $required
     * @param  list<string>  $present
     * @return list<array{id: string, present: bool}>
     */
    private function evaluateEvidence(array $required, array $present): array
    {
        $presentSet = array_fill_keys($present, true);

        $results = [];
        foreach ($required as $id) {
            $results[] = [
                'id' => $id,
                'present' => isset($presentSet[$id]),
            ];
        }

        return $results;
    }

    /**
     * Count packet-owned files whose classification is unsafe to own.
     *
     * @param  list<array<string, mixed>>  $files
     * @return array{total: int, unsafe_count: int, unsafe_paths: list<string>}
     */
    private function classifyOwnedFiles(array $files): array
    {
        $unsafePaths = [];
        foreach ($files as $file) {
            $classification = $this->stringOrNull($file['classification'] ?? null);
            if ($classification !== null && in_array($classification, self::UNSAFE_OWNED_CLASSIFICATIONS, true)) {
                $path = $this->stringOrNull($file['path'] ?? null);
                $unsafePaths[] = $path ?? $classification;
            }
        }

        return [
            'total' => count($files),
            'unsafe_count' => count($unsafePaths),
            'unsafe_paths' => array_values($unsafePaths),
        ];
    }

    /**
     * @param  list<array{id: string, status: string, passed: bool}>  $gates
     */
    private function allGatesPresent(array $gates): bool
    {
        foreach ($gates as $gate) {
            if ($gate['status'] === self::GATE_MISSING) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array{id: string, status: string, passed: bool}>  $gates
     */
    private function allGatesPassed(array $gates): bool
    {
        foreach ($gates as $gate) {
            if (! $gate['passed']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array{id: string, present: bool}>  $evidence
     */
    private function allEvidencePresent(array $evidence): bool
    {
        foreach ($evidence as $item) {
            if (! $item['present']) {
                return false;
            }
        }

        return true;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return list<string>
     */
    private function listOfStrings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            $str = $this->stringOrNull($item);
            if ($str !== null) {
                $out[] = $str;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listOfMaps(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $out[] = $item;
            }
        }

        return array_values($out);
    }

    /**
     * Stable, order-independent hash of a report payload for evidence.
     *
     * @param  array<string, mixed>  $payload
     */
    private function stableHash(array $payload): string
    {
        $normalized = $this->normalizeForHash($payload);

        return 'sha256:'.hash('sha256', (string) json_encode($normalized, JSON_UNESCAPED_SLASHES));
    }

    private function normalizeForHash(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = $this->normalizeForHash($item);
        }

        if (! $isList) {
            ksort($out);
        }

        return $out;
    }
}
