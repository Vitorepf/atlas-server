<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Quality Gates kernel decider.
 *
 * Pure, deterministic implementation of the kernel `quality-gates` step: given
 * the artifacts of one execution (acceptance evidence, deep-review findings,
 * manual-QA result, postgres-review checks, telemetry context) it returns the
 * single gate verdict — `pass`, `repair_required`, `evidence_required`,
 * `blocked` or `fail` — together with the promotion state and an audit receipt.
 * It turns quality into a contract, not an opinion, and never lets a required
 * gate pass silently.
 *
 * Contract (system-graph "Contratos"):
 *   Entrada: artefatos de execucao, comandos, logs, diffs e contexto do receipt.
 *   Saida:   pass | fail | blocked | repair_required | evidence_required.
 *
 * Promotion states (blueprint "Estados De Decisao"):
 *   resolved   -> can promote
 *   partial    -> only with a human decision
 *   unresolved -> no
 *   blocked    -> no
 *   unsafe     -> no
 *
 * Rules grounded in the canonical docs:
 *   - system-graph/quality-gates.md
 *       "IA nao pode declarar pronto quando gate obrigatorio falhou ou nao rodou"
 *       "Gate sem evidencia persistida nao deve promover estado"
 *       "Proibido: bypass silencioso"
 *   - engineering-blueprint-quality-gates.md
 *       Acceptance gate: "Criterio sem evidencia bloqueia conclusao";
 *         "Evidencia com failed bloqueia"; "needs_review bloqueia ate decisao
 *         humana"; "not_applicable so passa com justificativa".
 *       Deep review threshold: P0 aberto bloqueia sempre; P1 aberto com
 *         confidence >= 0.80 bloqueia; P1 menor e advisory salvo
 *         seguranca/dados; accepted_risk so com aprovacao humana.
 *       Manual QA / Visual / Postgres / Telemetry gates.
 *       Regra de ouro: "Sem evidencia persistida ... nao pode dizer pronto".
 *
 * The service NEVER runs a test, scan, migration or command; it consumes their
 * already-normalized results and emits the verdict. Callers decide whether to
 * promote, repair, request evidence or stop.
 *
 * @see docs/engineering-knowledge-base/system-graph/quality-gates.md
 * @see docs/engineering-knowledge-base/engineering-blueprint-quality-gates.md
 */
final class AtlasQualityGatesService
{
    /** Stable receipt schema id for the verdict this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.kernel.quality_gates.v1';

    /** Canonical gate verdicts (closed set, from system-graph "Contratos"). */
    public const VERDICT_PASS = 'pass';
    public const VERDICT_REPAIR_REQUIRED = 'repair_required';
    public const VERDICT_EVIDENCE_REQUIRED = 'evidence_required';
    public const VERDICT_BLOCKED = 'blocked';
    public const VERDICT_FAIL = 'fail';

    /** Canonical promotion states (blueprint "Estados De Decisao"). */
    public const STATE_RESOLVED = 'resolved';
    public const STATE_PARTIAL = 'partial';
    public const STATE_UNRESOLVED = 'unresolved';
    public const STATE_BLOCKED = 'blocked';
    public const STATE_UNSAFE = 'unsafe';

    /**
     * Deep-review P1 blocking threshold: a P1 finding blocks once its
     * confidence reaches this value ("P1 aberto com confidence >= 0.80
     * bloqueia").
     */
    public const P1_BLOCK_CONFIDENCE = 0.80;

    /**
     * Finding categories that the blueprint keeps blocking even for a low
     * confidence P1 ("Advisory, salvo categoria seguranca/dados").
     *
     * @var list<string>
     */
    private const SAFETY_CATEGORIES = ['security', 'data_integrity'];

    /**
     * Finding statuses that close a finding so it no longer blocks
     * (`fixed`, `false_positive`). `accepted_risk` is handled separately
     * because it only closes with explicit human approval.
     *
     * @var list<string>
     */
    private const CLOSED_FINDING_STATUSES = ['fixed', 'false_positive'];

    /**
     * Telemetry fields a run/gate must carry for auditability
     * (blueprint "Telemetry Gate"). `attempt_id` is optional by the doc and is
     * intentionally NOT required here.
     *
     * @var list<string>
     */
    private const REQUIRED_TELEMETRY = ['trace_id', 'run_id', 'command', 'status', 'decision'];

    /**
     * Evaluate every required gate for one execution and return the single
     * verdict + promotion state + per-gate breakdown + audit receipt.
     *
     * @param array<string,mixed> $execution
     *   acceptance_criteria : list<array> each { evidence:string?, status:string?,
     *                          justification:string? } — status in
     *                          passed|failed|needs_review|not_applicable.
     *   review_findings     : list<array> each { severity:p0|p1|p2|p3,
     *                          status:open|fixed|false_positive|accepted_risk,
     *                          confidence:float?, category:string?,
     *                          human_approved:bool? }.
     *   manual_qa           : array { required:bool, status:string? } status in
     *                          passed|failed|needs_review (only used when required).
     *   postgres_gate       : array { required:bool, checks:list<array{name,blocking:bool}> }.
     *   telemetry           : array<string,mixed> run/gate audit context.
     *   evidence_persisted  : bool  was gate evidence actually persisted (golden rule).
     *   required_tests_ran  : bool  did the mandatory gates actually run at all.
     *
     * @return array<string,mixed> verdict + state + gates + receipt
     */
    public function evaluate(array $execution): array
    {
        $acceptance = $this->evaluateAcceptanceGate($execution['acceptance_criteria'] ?? []);
        $review = $this->evaluateDeepReviewGate($execution['review_findings'] ?? []);
        $manualQa = $this->evaluateManualQaGate($execution['manual_qa'] ?? []);
        $postgres = $this->evaluatePostgresGate($execution['postgres_gate'] ?? []);
        $telemetry = $this->evaluateTelemetryGate($execution['telemetry'] ?? []);

        $evidencePersisted = (bool) ($execution['evidence_persisted'] ?? false);
        // A run that never executed the mandatory gates cannot be declared done
        // ("IA nao pode declarar pronto quando gate obrigatorio ... nao rodou").
        $requiredTestsRan = (bool) ($execution['required_tests_ran'] ?? true);

        $reasons = [];
        $verdict = null;

        // --- Rule 1: required gates must have run at all. ---
        if (! $requiredTestsRan) {
            $verdict = self::VERDICT_BLOCKED;
            $reasons[] = 'required_gate_did_not_run';
        }

        // --- Rule 2: golden rule — no persisted evidence cannot promote. ---
        // ("Gate sem evidencia persistida nao deve promover estado.")
        if ($verdict === null && ! $evidencePersisted) {
            $verdict = self::VERDICT_EVIDENCE_REQUIRED;
            $reasons[] = 'no_persisted_evidence';
        }

        // --- Rule 3: an unsafe failure (P0, blocking P1, failed evidence,
        // destructive/locking postgres check) is a hard stop. ---
        if ($verdict === null && $review['unsafe']) {
            $verdict = self::VERDICT_FAIL;
            $reasons[] = $review['reason'];
        }
        if ($verdict === null && $postgres['unsafe']) {
            $verdict = self::VERDICT_FAIL;
            $reasons[] = $postgres['reason'];
        }

        // --- Rule 4: acceptance / manual-QA gaps that a controlled repair or
        // re-run can still close => repair_required (not a silent pass). ---
        if ($verdict === null && $acceptance['blocking']) {
            // A criterion whose evidence is literally missing is an evidence
            // gap; a failing/needs-review criterion is a repair gap.
            $verdict = $acceptance['reason'] === 'acceptance_criterion_missing_evidence'
                ? self::VERDICT_EVIDENCE_REQUIRED
                : self::VERDICT_REPAIR_REQUIRED;
            $reasons[] = $acceptance['reason'];
        }
        if ($verdict === null && $manualQa['blocking']) {
            $verdict = self::VERDICT_REPAIR_REQUIRED;
            $reasons[] = $manualQa['reason'];
        }

        // --- Rule 5: telemetry must be complete enough to audit the pass. ---
        if ($verdict === null && ! $telemetry['complete']) {
            $verdict = self::VERDICT_EVIDENCE_REQUIRED;
            $reasons[] = $telemetry['reason'];
        }

        // --- Rule 6: everything required is green => pass. ---
        if ($verdict === null) {
            $verdict = self::VERDICT_PASS;
            $reasons[] = 'all_required_gates_passed';
        }

        $state = $this->promotionState($verdict, $review, $acceptance);
        $canPromote = $this->canPromote($state);

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'verdict' => $verdict,
            'promotion_state' => $state,
            'can_promote' => $canPromote,
            'evidence_persisted' => $evidencePersisted,
            'required_tests_ran' => $requiredTestsRan,
            'gates' => [
                'acceptance' => $acceptance,
                'deep_review' => $review,
                'manual_qa' => $manualQa,
                'postgres' => $postgres,
                'telemetry' => $telemetry,
            ],
            'auditable' => true,
            'reasons' => $reasons,
        ];
    }

    /**
     * Convenience predicate for the promotion driver: may this execution be
     * promoted? (false => repair, request evidence, escalate or stop).
     *
     * @param array<string,mixed> $execution
     */
    public function canPromoteExecution(array $execution): bool
    {
        return $this->evaluate($execution)['can_promote'] === true;
    }

    /**
     * Deep-review threshold gate (blueprint "Deep Review Gate").
     *
     * @param mixed $findings
     * @return array<string,mixed>
     */
    private function evaluateDeepReviewGate(mixed $findings): array
    {
        $blockers = [];
        $advisories = [];

        foreach ($this->normalizeList($findings) as $finding) {
            $severity = strtolower((string) ($finding['severity'] ?? ''));
            $status = strtolower((string) ($finding['status'] ?? 'open'));
            $category = strtolower((string) ($finding['category'] ?? ''));
            $confidence = $this->normalizeConfidence($finding['confidence'] ?? null);
            $humanApproved = (bool) ($finding['human_approved'] ?? false);

            // Closed findings (fixed / false_positive) never block.
            if (in_array($status, self::CLOSED_FINDING_STATUSES, true)) {
                continue;
            }
            // accepted_risk only stops blocking WITH explicit human approval.
            if ($status === 'accepted_risk') {
                if ($humanApproved) {
                    continue;
                }
                $blockers[] = 'accepted_risk_without_human_approval';

                continue;
            }

            // From here the finding is open.
            if ($severity === 'p0') {
                $blockers[] = 'p0_open';

                continue;
            }
            if ($severity === 'p1') {
                if ($confidence >= self::P1_BLOCK_CONFIDENCE
                    || in_array($category, self::SAFETY_CATEGORIES, true)) {
                    $blockers[] = 'p1_open_blocking';
                } else {
                    $advisories[] = 'p1_advisory';
                }

                continue;
            }
            // P2 -> risk note (advisory), P3 -> ignored.
            if ($severity === 'p2') {
                $advisories[] = 'p2_risk_note';
            }
        }

        $unsafe = $blockers !== [];

        return [
            'name' => 'deep_review',
            'unsafe' => $unsafe,
            'blocking' => $unsafe,
            'blocker_count' => count($blockers),
            'advisory_count' => count($advisories),
            'blockers' => array_values($blockers),
            'reason' => $unsafe ? ($blockers[0]) : 'no_blocking_finding',
        ];
    }

    /**
     * Acceptance gate (blueprint "Acceptance Gate"): every criterion must map
     * to at least one usable piece of evidence.
     *
     * @param mixed $criteria
     * @return array<string,mixed>
     */
    private function evaluateAcceptanceGate(mixed $criteria): array
    {
        $list = $this->normalizeList($criteria);
        $total = count($list);
        $satisfied = 0;
        $missingEvidence = 0;
        $failed = 0;
        $needsReview = 0;
        $notApplicableUnjustified = 0;

        foreach ($list as $criterion) {
            $status = strtolower((string) ($criterion['status'] ?? ''));
            $evidence = trim((string) ($criterion['evidence'] ?? ''));
            $justification = trim((string) ($criterion['justification'] ?? ''));

            // "Criterio sem evidencia bloqueia conclusao."
            if ($status === 'not_applicable') {
                // "not_applicable so passa com justificativa".
                if ($justification === '') {
                    $notApplicableUnjustified++;
                } else {
                    $satisfied++;
                }

                continue;
            }
            if ($evidence === '') {
                $missingEvidence++;

                continue;
            }
            if ($status === 'failed') {
                $failed++; // "Evidencia com failed bloqueia."

                continue;
            }
            if ($status === 'needs_review') {
                $needsReview++; // "needs_review bloqueia ate decisao humana."

                continue;
            }
            // status passed (or unspecified with real evidence present).
            $satisfied++;
        }

        // Decide the single blocking reason in priority order: a hard failed
        // criterion outranks a missing-evidence gap, which outranks
        // needs-review, which outranks an unjustified not_applicable.
        $reason = 'acceptance_complete';
        $blocking = false;
        if ($failed > 0) {
            $reason = 'acceptance_criterion_failed';
            $blocking = true;
        } elseif ($missingEvidence > 0) {
            $reason = 'acceptance_criterion_missing_evidence';
            $blocking = true;
        } elseif ($needsReview > 0) {
            $reason = 'acceptance_criterion_needs_review';
            $blocking = true;
        } elseif ($notApplicableUnjustified > 0) {
            $reason = 'acceptance_not_applicable_without_justification';
            $blocking = true;
        } elseif ($total === 0) {
            // No acceptance criteria declared at all is itself an evidence gap.
            $reason = 'acceptance_criterion_missing_evidence';
            $blocking = true;
        }

        return [
            'name' => 'acceptance',
            'blocking' => $blocking,
            'total' => $total,
            'satisfied' => $satisfied,
            'missing_evidence' => $missingEvidence,
            'failed' => $failed,
            'needs_review' => $needsReview,
            'not_applicable_unjustified' => $notApplicableUnjustified,
            'reason' => $reason,
        ];
    }

    /**
     * Manual QA gate (blueprint "Manual QA Gate"): only blocks when QA was
     * required and did not cleanly pass.
     *
     * @param mixed $manualQa
     * @return array<string,mixed>
     */
    private function evaluateManualQaGate(mixed $manualQa): array
    {
        $manualQa = is_array($manualQa) ? $manualQa : [];
        $required = (bool) ($manualQa['required'] ?? false);
        $status = strtolower((string) ($manualQa['status'] ?? ''));

        if (! $required) {
            return [
                'name' => 'manual_qa',
                'required' => false,
                'blocking' => false,
                'status' => $status !== '' ? $status : 'not_required',
                'reason' => 'manual_qa_not_required',
            ];
        }

        $blocking = $status !== 'passed';
        $reason = match (true) {
            $status === 'passed' => 'manual_qa_passed',
            $status === 'failed' => 'manual_qa_failed',
            $status === 'needs_review' => 'manual_qa_needs_review',
            default => 'manual_qa_missing_result',
        };

        return [
            'name' => 'manual_qa',
            'required' => true,
            'blocking' => $blocking,
            'status' => $status !== '' ? $status : 'missing',
            'reason' => $reason,
        ];
    }

    /**
     * Postgres gate (blueprint "Postgres Gate"): when required, any blocking
     * check (rollback/lock/raw-sql/backfill/etc.) makes the change unsafe.
     *
     * @param mixed $postgres
     * @return array<string,mixed>
     */
    private function evaluatePostgresGate(mixed $postgres): array
    {
        $postgres = is_array($postgres) ? $postgres : [];
        $required = (bool) ($postgres['required'] ?? false);

        if (! $required) {
            return [
                'name' => 'postgres',
                'required' => false,
                'unsafe' => false,
                'blocking_checks' => [],
                'reason' => 'postgres_not_required',
            ];
        }

        $blockingChecks = [];
        foreach ($this->normalizeList($postgres['checks'] ?? []) as $check) {
            if ((bool) ($check['blocking'] ?? false)) {
                $name = trim((string) ($check['name'] ?? 'check'));
                $blockingChecks[] = $name !== '' ? $name : 'check';
            }
        }

        $unsafe = $blockingChecks !== [];

        return [
            'name' => 'postgres',
            'required' => true,
            'unsafe' => $unsafe,
            'blocking_checks' => $blockingChecks,
            'reason' => $unsafe
                ? ('postgres_blocking_check:' . $blockingChecks[0])
                : 'postgres_checks_passed',
        ];
    }

    /**
     * Telemetry gate (blueprint "Telemetry Gate"): a run/gate must carry enough
     * context to be audited.
     *
     * @param mixed $telemetry
     * @return array<string,mixed>
     */
    private function evaluateTelemetryGate(mixed $telemetry): array
    {
        $telemetry = is_array($telemetry) ? $telemetry : [];
        $missing = [];
        foreach (self::REQUIRED_TELEMETRY as $field) {
            $value = $telemetry[$field] ?? null;
            if (! is_string($value) || trim($value) === '') {
                if (! (is_int($value) || (is_string($value) && trim($value) !== ''))) {
                    $missing[] = $field;
                }
            }
        }

        $complete = $missing === [];

        return [
            'name' => 'telemetry',
            'complete' => $complete,
            'missing' => array_values($missing),
            'reason' => $complete ? 'telemetry_complete' : 'telemetry_incomplete',
        ];
    }

    /**
     * Map the verdict + gate signals to one promotion state
     * (blueprint "Estados De Decisao").
     *
     * @param array<string,mixed> $review
     * @param array<string,mixed> $acceptance
     */
    private function promotionState(string $verdict, array $review, array $acceptance): string
    {
        return match ($verdict) {
            self::VERDICT_PASS => self::STATE_RESOLVED,
            // A hard failure (P0 / blocking P1 / destructive DB) is `unsafe`.
            self::VERDICT_FAIL => self::STATE_UNSAFE,
            self::VERDICT_BLOCKED => self::STATE_BLOCKED,
            // Missing evidence / repairable gaps: partial when something real
            // was delivered (some acceptance satisfied), otherwise unresolved.
            self::VERDICT_EVIDENCE_REQUIRED,
            self::VERDICT_REPAIR_REQUIRED => ($acceptance['satisfied'] ?? 0) > 0
                ? self::STATE_PARTIAL
                : self::STATE_UNRESOLVED,
            default => self::STATE_UNRESOLVED,
        };
    }

    /**
     * Only `resolved` may promote without a human decision
     * (blueprint table: every other state is "Nao" / "Nao sem decisao humana").
     */
    private function canPromote(string $state): bool
    {
        return $state === self::STATE_RESOLVED;
    }

    /**
     * @param mixed $value
     * @return list<array<string,mixed>>
     */
    private function normalizeList(mixed $value): array
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

    private function normalizeConfidence(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return max(0.0, min(1.0, (float) $value));
        }
        if (is_string($value) && is_numeric($value)) {
            return max(0.0, min(1.0, (float) $value));
        }

        // Missing confidence is treated as fully confident so a P1 cannot be
        // silently downgraded below the blocking threshold.
        return 1.0;
    }
}
