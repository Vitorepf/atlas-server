<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas Engineering Blueprint Maturity And DoD decider.
 *
 * Pure, deterministic gate that turns the maturity index doc into a contract.
 * It answers two distinct, documented questions and never lies about either:
 *
 *  1. Final DoD Rule (doc "Final DoD Rule"): the Engineering Blueprint System
 *     is *complete* ONLY when a new AI session can take a project objective and
 *     walk the full path end to end — generate/freeze the blueprint, produce
 *     tasks, run harnesses, collect evidence, pass review gates and promote
 *     memory — *without relying on hidden chat context*. This is modelled as a
 *     fixed, ordered 8-stage pipeline. The pipeline is sequential: the first
 *     stage that is not proven blocks everything downstream, so the verdict
 *     names exactly the earliest blocking stage. The "no hidden chat context"
 *     clause is a separate hard invariant — if a stage only passes because of
 *     out-of-band chat context, it does NOT count as passed.
 *
 *  2. Remaining Product Maturity (doc table): five named areas — UX, Missing
 *     evidence, Wireframes, Calibration, Postgres — still carry remaining work.
 *     The product is only *mature* when every one of those areas is cleared.
 *     Until then it is, per the doc decision, a "strong operational base, not
 *     the final mature product" — reported as `operational_base`.
 *
 * Evidence gate (doc frontmatter `forbidden_changes` + `maintenance`):
 * "Do not mark complete without tests, docs, CLI/API/app coverage when
 * applicable and usage evidence" and "Declarar runtime, maturidade ou prontidao
 * sem evidencia verificavel e gates verdes" is forbidden. So a stage / area that
 * is asserted `passed=true` but carries NO verifiable evidence is treated as
 * NOT passed. Completeness and maturity can never be claimed on bare assertion.
 *
 * The service is pure: it consumes already-normalized stage/area results and
 * emits a verdict. It never runs a harness, reads a doc, or touches a DB.
 *
 * @see docs/engineering-knowledge-base/engineering-blueprint-maturity-dod.md
 */
final class AtlasEngineeringBlueprintMaturityDodService
{
    /** Stable receipt schema id for the verdict this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.engineering.blueprint_maturity_dod.v1';

    /** Closed set of DoD verdicts for the Final DoD Rule. */
    public const DOD_COMPLETE = 'complete';
    public const DOD_INCOMPLETE = 'incomplete';

    /** Closed set of product maturity verdicts. */
    public const MATURITY_MATURE = 'mature';
    public const MATURITY_OPERATIONAL_BASE = 'operational_base';

    /**
     * The Final DoD pipeline, in strict doc order. A new AI session must be able
     * to traverse every stage from a bare project objective with no hidden chat
     * context. Order matters: an earlier failing stage blocks the later ones.
     *
     * @var array<string,string>
     */
    public const DOD_STAGES = [
        'objective_intake' => 'Take a project objective (no hidden chat context).',
        'blueprint_generated' => 'Generate the blueprint from the objective.',
        'blueprint_frozen' => 'Freeze the blueprint into a snapshot.',
        'tasks_produced' => 'Produce tasks from the frozen blueprint.',
        'harnesses_run' => 'Run the harnesses over the tasks.',
        'evidence_collected' => 'Collect evidence from the runs.',
        'review_gates_passed' => 'Pass the review gates.',
        'memory_promoted' => 'Promote memory without relying on hidden chat context.',
    ];

    /**
     * The five "Remaining Product Maturity" areas, in doc-table order. Each must
     * be cleared for the product to be `mature`.
     *
     * @var array<string,string>
     */
    public const MATURITY_AREAS = [
        'ux' => 'Unified Blueprint -> Run -> Review -> Evidence -> Promote flow.',
        'missing_evidence' => 'Global app filters for missing evidence/blocking gates.',
        'wireframes' => 'Dedicated wireframe refs and visual baselines.',
        'calibration' => 'Atlas-Bench and Memory Delta calibrated with real run volume.',
        'postgres' => 'Real EXPLAIN/history against target connections.',
    ];

    /**
     * Evaluate the Final DoD Rule.
     *
     * @param array<string,mixed> $report
     *   stages: array<string,mixed> keyed by DOD_STAGES keys. Each value may be
     *           a bool (true = passed) or an array {
     *             passed: bool,
     *             evidence: list<string>|string|null,  // verifiable refs
     *             hidden_chat_context: bool?            // true => relied on chat
     *           }. A missing stage => not passed.
     *
     * @return array{
     *   schema: string,
     *   verdict: string,
     *   is_complete: bool,
     *   total_stages: int,
     *   passed_count: int,
     *   stages: array<string,bool>,
     *   blocking_stage: ?string,
     *   blocking_reason: ?string,
     *   pending_stages: list<string>,
     *   hidden_chat_context_violations: list<string>,
     *   reasons: list<string>,
     *   auditable: true
     * }
     */
    public function evaluateDod(array $report): array
    {
        $stagesInput = is_array($report['stages'] ?? null) ? $report['stages'] : [];

        $stages = [];
        $pending = [];
        $hiddenViolations = [];
        $blockingStage = null;
        $blockingReason = null;

        foreach (array_keys(self::DOD_STAGES) as $key) {
            $resolution = $this->resolveStage($stagesInput[$key] ?? null);
            $stages[$key] = $resolution['passed'];

            if ($resolution['hidden_chat_context']) {
                $hiddenViolations[] = $key;
            }

            if (! $resolution['passed']) {
                $pending[] = $key;
                // The FIRST not-passed stage (doc order) is the blocking one,
                // because the pipeline is sequential.
                if ($blockingStage === null) {
                    $blockingStage = $key;
                    $blockingReason = $resolution['reason'];
                }
            }
        }

        $passedCount = count(self::DOD_STAGES) - count($pending);
        $isComplete = $pending === [];

        $reasons = [];
        if ($isComplete) {
            $reasons[] = 'dod:all_stages_passed_with_evidence';
        } else {
            foreach ($pending as $key) {
                $reasons[] = 'dod_pending:' . $key;
            }
        }
        foreach ($hiddenViolations as $key) {
            $reasons[] = 'hidden_chat_context:' . $key;
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'verdict' => $isComplete ? self::DOD_COMPLETE : self::DOD_INCOMPLETE,
            'is_complete' => $isComplete,
            'total_stages' => count(self::DOD_STAGES),
            'passed_count' => $passedCount,
            'stages' => $stages,
            'blocking_stage' => $blockingStage,
            'blocking_reason' => $blockingReason,
            'pending_stages' => array_values($pending),
            'hidden_chat_context_violations' => array_values($hiddenViolations),
            'reasons' => $reasons,
            'auditable' => true,
        ];
    }

    /**
     * Evaluate the Remaining Product Maturity table.
     *
     * @param array<string,mixed> $report
     *   areas: array<string,mixed> keyed by MATURITY_AREAS keys. Each value may
     *          be a bool (true = cleared) or an array {
     *            cleared: bool,
     *            evidence: list<string>|string|null
     *          }. A missing area => not cleared.
     *
     * @return array{
     *   schema: string,
     *   verdict: string,
     *   is_mature: bool,
     *   total_areas: int,
     *   cleared_count: int,
     *   areas: array<string,bool>,
     *   remaining_areas: list<string>,
     *   reasons: list<string>,
     *   auditable: true
     * }
     */
    public function evaluateProductMaturity(array $report): array
    {
        $areasInput = is_array($report['areas'] ?? null) ? $report['areas'] : [];

        $areas = [];
        $remaining = [];

        foreach (array_keys(self::MATURITY_AREAS) as $key) {
            $cleared = $this->resolveArea($areasInput[$key] ?? null);
            $areas[$key] = $cleared;
            if (! $cleared) {
                $remaining[] = $key;
            }
        }

        $clearedCount = count(self::MATURITY_AREAS) - count($remaining);
        $isMature = $remaining === [];

        $reasons = [];
        if ($isMature) {
            $reasons[] = 'maturity:all_areas_cleared_with_evidence';
        } else {
            // Doc decision: "strong operational base, not the final mature product".
            $reasons[] = 'maturity:operational_base';
            foreach ($remaining as $key) {
                $reasons[] = 'maturity_remaining:' . $key;
            }
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'verdict' => $isMature ? self::MATURITY_MATURE : self::MATURITY_OPERATIONAL_BASE,
            'is_mature' => $isMature,
            'total_areas' => count(self::MATURITY_AREAS),
            'cleared_count' => $clearedCount,
            'areas' => $areas,
            'remaining_areas' => array_values($remaining),
            'reasons' => $reasons,
            'auditable' => true,
        ];
    }

    /**
     * Full assessment: the system is only "DONE" (DoD complete AND mature) when
     * both the Final DoD Rule and the Remaining Product Maturity table are
     * satisfied. This mirrors the doc separating "operational base" (DoD work)
     * from "final mature product" (remaining areas).
     *
     * @param array<string,mixed> $report  { stages: ..., areas: ... }
     * @return array<string,mixed>
     */
    public function assess(array $report): array
    {
        $dod = $this->evaluateDod($report);
        $maturity = $this->evaluateProductMaturity($report);

        $systemDone = $dod['is_complete'] && $maturity['is_mature'];

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'dod' => $dod,
            'product_maturity' => $maturity,
            'system_done' => $systemDone,
            'overall_state' => $systemDone
                ? 'done'
                : ($dod['is_complete'] ? self::MATURITY_OPERATIONAL_BASE : self::DOD_INCOMPLETE),
            'auditable' => true,
        ];
    }

    /**
     * Convenience predicate: may a session declare the Engineering Blueprint
     * System complete per the Final DoD Rule? Only a fully proven pipeline may.
     *
     * @param array<string,mixed> $report
     */
    public function isDodComplete(array $report): bool
    {
        return $this->evaluateDod($report)['is_complete'];
    }

    /**
     * Resolve one DoD stage to { passed, hidden_chat_context, reason }.
     *
     * Rules:
     *  - bool true with no evidence array form => passed (legacy/simple form
     *    only when the value is literally the boolean true; see below).
     *  - array form: passed requires passed=true AND at least one verifiable
     *    evidence ref (forbidden_changes / maintenance evidence gate) AND no
     *    hidden_chat_context reliance.
     *
     * To keep the evidence gate honest, the *bare boolean true* shortcut is NOT
     * accepted as proof on its own: a stage must carry evidence. A plain `true`
     * therefore resolves to NOT passed with reason "no_evidence". This enforces
     * "do not mark complete without ... usage evidence".
     *
     * @param mixed $entry
     * @return array{passed: bool, hidden_chat_context: bool, reason: ?string}
     */
    private function resolveStage(mixed $entry): array
    {
        if ($entry === null) {
            return ['passed' => false, 'hidden_chat_context' => false, 'reason' => 'missing'];
        }

        if (is_bool($entry)) {
            // A bare boolean carries no evidence. Per the evidence gate it can
            // never prove a stage; only an explicit false is meaningful.
            return [
                'passed' => false,
                'hidden_chat_context' => false,
                'reason' => $entry ? 'no_evidence' : 'not_passed',
            ];
        }

        if (! is_array($entry)) {
            return ['passed' => false, 'hidden_chat_context' => false, 'reason' => 'invalid'];
        }

        $passedFlag = (bool) ($entry['passed'] ?? false);
        $hidden = (bool) ($entry['hidden_chat_context'] ?? false);
        $hasEvidence = $this->hasEvidence($entry['evidence'] ?? null);

        if (! $passedFlag) {
            return ['passed' => false, 'hidden_chat_context' => $hidden, 'reason' => 'not_passed'];
        }
        if (! $hasEvidence) {
            // Evidence gate: cannot declare complete without verifiable evidence.
            return ['passed' => false, 'hidden_chat_context' => $hidden, 'reason' => 'no_evidence'];
        }
        if ($hidden) {
            // "without relying on hidden chat context" — a stage that leaned on
            // out-of-band chat does not satisfy the Final DoD Rule.
            return ['passed' => false, 'hidden_chat_context' => true, 'reason' => 'hidden_chat_context'];
        }

        return ['passed' => true, 'hidden_chat_context' => false, 'reason' => null];
    }

    /**
     * Resolve one maturity area to a cleared boolean. Same evidence gate as the
     * DoD stages: an area is only cleared when cleared=true AND it carries
     * verifiable evidence. A bare boolean true is not accepted as proof.
     *
     * @param mixed $entry
     */
    private function resolveArea(mixed $entry): bool
    {
        if (is_bool($entry)) {
            // Bare boolean carries no evidence => never clears the area.
            return false;
        }
        if (! is_array($entry)) {
            return false;
        }

        $clearedFlag = (bool) ($entry['cleared'] ?? false);
        $hasEvidence = $this->hasEvidence($entry['evidence'] ?? null);

        return $clearedFlag && $hasEvidence;
    }

    /**
     * A stage/area carries verifiable evidence when it provides at least one
     * non-empty evidence ref (a path, command, receipt id or report).
     *
     * @param mixed $evidence
     */
    private function hasEvidence(mixed $evidence): bool
    {
        if (is_string($evidence)) {
            return trim($evidence) !== '';
        }
        if (is_array($evidence)) {
            foreach ($evidence as $ref) {
                if (is_string($ref) && trim($ref) !== '') {
                    return true;
                }
            }
        }

        return false;
    }
}
