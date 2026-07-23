<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas Forge Operating System Runbook — pure, deterministic operational decider.
 *
 * The runbook is the authoring boundary for HOW the Forge factory must operate
 * once Programming Governance decides full Forge is needed. This service turns
 * the runbook's operational rules into runtime. It is read-only: it routes,
 * classifies and emits blocking reasons; it never runs a provider, never writes
 * evidence, never relaxes a gate and never expands scope.
 *
 * Three documented decision surfaces are implemented, one cluster of methods
 * each:
 *
 *   1. Forge Intake ("Modulos Operacionais > Forge Intake" + "Contratos").
 *      "Trabalho pequeno nao aciona Forge completo por reflexo." The 9 canonical
 *      intake types are split: `small_patch` (and only it) runs under compact
 *      governance; the eight complex types enter the full factory. The runbook
 *      is explicit that multiagente, brownfield pesado, self-construction and
 *      high-criticality demand the full flow, so a high `criticality` signal is
 *      allowed to escalate an otherwise-small task into the factory.
 *
 *   2. Quality Gate Matrix ("Quality Gate Matrix"). "Cada gate precisa de
 *      evidence id. Gate pulado sem motivo vira falha." A gate passes only with
 *      a non-empty evidence id; a skipped gate is acceptable ONLY when it carries
 *      a skip reason; anything else fails the matrix.
 *
 *   3. Rerun Failed / Repair Loop ("Rerun Failed E Repair Loop"). The five
 *      documented rules are enforced: rerun reuses the same packet contract OR
 *      creates a governed repair delta; rerun must register new evidence; auto
 *      retry has a limit; repeated failure becomes a learning proposal; high-risk
 *      rerun requires review/escalation.
 *
 * It also exposes the runbook's two static facts as canonical constants: the
 * intake taxonomy and the canonical integrated flow order ("Fluxo Final
 * Integrado"), so callers and tests pin the exact documented sets.
 *
 * Non-goals honoured (from "Regras para IA" / "Escopo de Implementacao"):
 *   - does NOT execute an external provider (no approval/cost/data-boundary here);
 *   - does NOT accept a screenshot/log/diff as sole evidence — it only checks an
 *     evidence id is present, leaving sufficiency to the gate definition;
 *   - does NOT allow infinite rerun (the retry cap flips into learning proposal);
 *   - does NOT publish cartography (it is a decider, not a publisher).
 *
 * @see docs/engineering-knowledge-base/atlas-forge-operating-system-runbook.md
 */
final class AtlasForgeOperatingSystemRunbookService
{
    /** Stable evidence schema id this decider emits. */
    public const SCHEMA = 'atlas.forge.runbook.v1';

    /** Routing verdicts for intake (closed set). */
    public const ROUTE_FULL_FORGE = 'full_forge';
    public const ROUTE_COMPACT_GOVERNANCE = 'compact_governance';

    /** Gate verdicts (closed set). */
    public const GATE_PASS = 'pass';
    public const GATE_SKIPPED_OK = 'skipped_ok';
    public const GATE_FAIL = 'fail';

    /** Rerun verdicts (closed set). */
    public const RERUN_ALLOW = 'allow';
    public const RERUN_REVIEW = 'review';
    public const RERUN_LEARNING_PROPOSAL = 'learning_proposal';
    public const RERUN_BLOCK = 'block';

    /**
     * The 9 canonical Forge Intake types (doc "Forge Intake" bullet list, in
     * documented order).
     *
     * @var list<string>
     */
    public const INTAKE_TYPES = [
        'small_patch',
        'structural_change',
        'brownfield_delta',
        'multi_agent_project',
        'repair_loop',
        'refactor',
        'documentation_cartography_update',
        'release_integration',
        'self_construction',
    ];

    /**
     * The single intake type that runs under compact governance. Everything else
     * in INTAKE_TYPES enters the full factory. ("tarefas pequenas usam governanca
     * compacta; tarefas complexas entram em fabrica.")
     *
     * @var list<string>
     */
    public const COMPACT_TYPES = [
        'small_patch',
    ];

    /**
     * Criticality signals that force the full factory even for a small task
     * ("alta criticidade" in the contracts; the runbook lists it alongside
     * multiagente / brownfield pesado / self-construction).
     *
     * @var list<string>
     */
    public const HIGH_CRITICALITY = [
        'high',
        'critical',
    ];

    /**
     * The 5 documented rerun scopes ("rerun failed packet / tests / docs gate /
     * cartography publish / integration queue item").
     *
     * @var list<string>
     */
    public const RERUN_SCOPES = [
        'packet',
        'tests',
        'docs_gate',
        'cartography_publish',
        'integration_queue_item',
    ];

    /**
     * Default cap on automatic retries before a repeated failure must become a
     * learning proposal ("retry automatico tem limite; falha repetida vira
     * learning proposal"). Callers may override via input.max_auto_retries.
     */
    public const DEFAULT_MAX_AUTO_RETRIES = 3;

    /**
     * Canonical integrated flow order ("Fluxo Final Integrado"). Exposed so the
     * sequence can be pinned and consumed; the runbook says this doc "vence a
     * ordem operacional interna do Forge OS".
     *
     * @var list<string>
     */
    public const CANONICAL_FLOW = [
        'intake',
        'constitution_steering',
        'sovereign_epistemic_preflight',
        'mother_spec',
        'spec_lookup',
        'code_intelligence',
        'delta',
        'splitter',
        'dependency_dag',
        'packet_contract',
        'reservation',
        'role_model_router',
        'capability_permission_dry_run',
        'branch_worktree',
        'runtime',
        'patch',
        'scope_validator',
        'verification',
        'completion_evidence',
        'event_evidence',
        'artifact_checkpoint',
        'review',
        'integration',
        'quality_ci',
        'rollback_migration',
        'release',
        'learning',
        'prompt_recipes_evals',
        'docs_code_intelligence_cartography',
    ];

    // ---------------------------------------------------------------------
    // 1. Forge Intake
    // ---------------------------------------------------------------------

    /**
     * Route an intake request to full Forge or compact governance.
     *
     * @param array<string,mixed> $task
     *        type        : string  one of INTAKE_TYPES (unknown => full Forge, fail-safe)
     *        criticality : string  low|medium|high|critical (default low)
     *        multi_agent : bool    several agents on the work (default false)
     *
     * @return array<string,mixed> the routing decision + audit fields
     */
    public function routeIntake(array $task): array
    {
        $rawType = $task['type'] ?? null;
        $type = is_string($rawType) ? strtolower(trim($rawType)) : '';
        $known = in_array($type, self::INTAKE_TYPES, true);

        $criticality = is_string($task['criticality'] ?? null)
            ? strtolower(trim((string) $task['criticality']))
            : 'low';
        $isHighCriticality = in_array($criticality, self::HIGH_CRITICALITY, true);
        $multiAgent = (bool) ($task['multi_agent'] ?? false);

        $reasons = [];

        // Fail-safe: an unknown / missing type never silently downgrades to
        // compact governance — it enters the factory so it gets full gates.
        if (! $known) {
            $reasons[] = 'unknown_intake_type_defaults_to_full_forge';
            $route = self::ROUTE_FULL_FORGE;

            return $this->intakeResult($type, $route, $known, $criticality, $multiAgent, $reasons);
        }

        $isCompactType = in_array($type, self::COMPACT_TYPES, true);

        if (! $isCompactType) {
            // One of the eight complex types: full factory by definition.
            $reasons[] = "complex_type_requires_full_forge:{$type}";
            $route = self::ROUTE_FULL_FORGE;

            return $this->intakeResult($type, $route, $known, $criticality, $multiAgent, $reasons);
        }

        // From here: type is small_patch. It runs compact UNLESS an escalation
        // signal applies ("alta criticidade" / multiagente force the full flow).
        if ($isHighCriticality) {
            $reasons[] = "small_task_escalated_by_criticality:{$criticality}";
            $route = self::ROUTE_FULL_FORGE;

            return $this->intakeResult($type, $route, $known, $criticality, $multiAgent, $reasons);
        }

        if ($multiAgent) {
            $reasons[] = 'small_task_escalated_by_multi_agent';
            $route = self::ROUTE_FULL_FORGE;

            return $this->intakeResult($type, $route, $known, $criticality, $multiAgent, $reasons);
        }

        $reasons[] = 'small_patch_uses_compact_governance';

        return $this->intakeResult($type, self::ROUTE_COMPACT_GOVERNANCE, $known, $criticality, $multiAgent, $reasons);
    }

    /** Convenience predicate: does this task need the full factory? */
    public function requiresFullForge(array $task): bool
    {
        return $this->routeIntake($task)['route'] === self::ROUTE_FULL_FORGE;
    }

    /**
     * @param list<string> $reasons
     * @return array<string,mixed>
     */
    private function intakeResult(
        string $type,
        string $route,
        bool $known,
        string $criticality,
        bool $multiAgent,
        array $reasons,
    ): array {
        return [
            'schema' => self::SCHEMA,
            'surface' => 'forge_intake',
            'type' => $type === '' ? null : $type,
            'known_type' => $known,
            'criticality' => $criticality,
            'multi_agent' => $multiAgent,
            'route' => $route,
            'full_forge' => $route === self::ROUTE_FULL_FORGE,
            'reasons' => $reasons,
        ];
    }

    // ---------------------------------------------------------------------
    // 2. Quality Gate Matrix
    // ---------------------------------------------------------------------

    /**
     * Evaluate a single gate against the matrix rule:
     *   - passed gate needs a non-empty evidence id;
     *   - a skipped gate is acceptable ONLY with a skip reason;
     *   - everything else is a failure ("Gate pulado sem motivo vira falha").
     *
     * @param array<string,mixed> $gate
     *        name        : string  gate identifier (default 'gate')
     *        status      : string  passed|skipped|failed (default 'passed')
     *        evidence_id : string  evidence reference (required when passed)
     *        skip_reason : string  justification (required when skipped)
     *
     * @return array<string,mixed>
     */
    public function evaluateGate(array $gate): array
    {
        $name = is_string($gate['name'] ?? null) && trim((string) $gate['name']) !== ''
            ? trim((string) $gate['name'])
            : 'gate';
        $status = is_string($gate['status'] ?? null)
            ? strtolower(trim((string) $gate['status']))
            : 'passed';
        $evidenceId = is_string($gate['evidence_id'] ?? null) ? trim((string) $gate['evidence_id']) : '';
        $skipReason = is_string($gate['skip_reason'] ?? null) ? trim((string) $gate['skip_reason']) : '';

        $reasons = [];

        if ($status === 'failed') {
            $reasons[] = 'gate_reported_failed';

            return $this->gateResult($name, $status, self::GATE_FAIL, $evidenceId !== '', $reasons);
        }

        if ($status === 'skipped') {
            if ($skipReason === '') {
                $reasons[] = 'skipped_without_reason_is_failure';

                return $this->gateResult($name, $status, self::GATE_FAIL, false, $reasons);
            }
            $reasons[] = 'skipped_with_reason';

            return $this->gateResult($name, $status, self::GATE_SKIPPED_OK, $evidenceId !== '', $reasons);
        }

        // Treated as a pass attempt (any non-failed, non-skipped status).
        if ($evidenceId === '') {
            $reasons[] = 'passed_gate_missing_evidence_id';

            return $this->gateResult($name, 'passed', self::GATE_FAIL, false, $reasons);
        }

        $reasons[] = 'passed_with_evidence';

        return $this->gateResult($name, 'passed', self::GATE_PASS, true, $reasons);
    }

    /**
     * Roll a set of gates into a matrix verdict. The matrix is green only when
     * NO gate fails (passes and reasoned-skips are both acceptable).
     *
     * @param list<array<string,mixed>> $gates
     * @return array<string,mixed>
     */
    public function evaluateGateMatrix(array $gates): array
    {
        $results = [];
        $failing = [];
        $passed = 0;
        $skippedOk = 0;

        foreach ($gates as $gate) {
            if (! is_array($gate)) {
                continue;
            }
            $r = $this->evaluateGate($gate);
            $results[] = $r;
            if ($r['verdict'] === self::GATE_FAIL) {
                $failing[] = $r['name'];
            } elseif ($r['verdict'] === self::GATE_PASS) {
                $passed++;
            } elseif ($r['verdict'] === self::GATE_SKIPPED_OK) {
                $skippedOk++;
            }
        }

        $green = $failing === [];

        return [
            'schema' => self::SCHEMA,
            'surface' => 'quality_gate_matrix',
            'status' => $green ? self::GATE_PASS : self::GATE_FAIL,
            'green' => $green,
            'total' => count($results),
            'passed' => $passed,
            'skipped_ok' => $skippedOk,
            'failing' => array_values($failing),
            'gates' => $results,
        ];
    }

    /**
     * @param list<string> $reasons
     * @return array<string,mixed>
     */
    private function gateResult(
        string $name,
        string $status,
        string $verdict,
        bool $hasEvidence,
        array $reasons,
    ): array {
        return [
            'name' => $name,
            'status' => $status,
            'verdict' => $verdict,
            'has_evidence_id' => $hasEvidence,
            'reasons' => $reasons,
        ];
    }

    // ---------------------------------------------------------------------
    // 3. Rerun Failed / Repair Loop
    // ---------------------------------------------------------------------

    /**
     * Decide what a rerun request may do, enforcing the runbook's five rules.
     *
     * @param array<string,mixed> $rerun
     *        scope             : string  one of RERUN_SCOPES (unknown => block)
     *        new_evidence      : bool    a fresh evidence id is being registered
     *        same_contract     : bool    reuses the existing packet contract
     *        repair_delta      : bool    a governed repair delta is created
     *        auto_attempt      : int     1-based automatic retry about to run (default 1)
     *        max_auto_retries  : int     cap override (default DEFAULT_MAX_AUTO_RETRIES)
     *        repeated_failure  : bool    same failure signature seen again (default false)
     *        risk              : string  low|medium|high|critical (default low)
     *
     * @return array<string,mixed>
     */
    public function decideRerun(array $rerun): array
    {
        $rawScope = $rerun['scope'] ?? null;
        $scope = is_string($rawScope) ? strtolower(trim($rawScope)) : '';
        $knownScope = in_array($scope, self::RERUN_SCOPES, true);

        $newEvidence = (bool) ($rerun['new_evidence'] ?? false);
        $sameContract = (bool) ($rerun['same_contract'] ?? false);
        $repairDelta = (bool) ($rerun['repair_delta'] ?? false);
        $repeatedFailure = (bool) ($rerun['repeated_failure'] ?? false);

        $risk = is_string($rerun['risk'] ?? null) ? strtolower(trim((string) $rerun['risk'])) : 'low';
        $highRisk = in_array($risk, self::HIGH_CRITICALITY, true);

        $maxRetries = $this->normalizeMaxRetries($rerun['max_auto_retries'] ?? null);
        $attempt = $this->normalizeAttempt($rerun['auto_attempt'] ?? null);

        $reasons = [];

        // Rule A — rerun must target a known scope; otherwise it cannot run.
        if (! $knownScope) {
            $reasons[] = 'unknown_rerun_scope_blocks';

            return $this->rerunResult($scope, $knownScope, self::RERUN_BLOCK, $attempt, $maxRetries, $highRisk, $reasons);
        }

        // Rule B — rerun must use the same packet contract OR create a governed
        // repair delta. Neither => the rerun has no governed basis and is blocked.
        if (! $sameContract && ! $repairDelta) {
            $reasons[] = 'rerun_needs_same_contract_or_repair_delta';

            return $this->rerunResult($scope, $knownScope, self::RERUN_BLOCK, $attempt, $maxRetries, $highRisk, $reasons);
        }

        // Rule C — rerun must register new evidence ("rerun registra nova
        // evidence"; the runbook Riscos: "Rerun sem evidence nova").
        if (! $newEvidence) {
            $reasons[] = 'rerun_without_new_evidence_blocked';

            return $this->rerunResult($scope, $knownScope, self::RERUN_BLOCK, $attempt, $maxRetries, $highRisk, $reasons);
        }

        // Rule D — repeated failure OR exhausted automatic-retry budget becomes a
        // learning proposal instead of looping ("falha repetida vira learning
        // proposal"; "Nao continuar rerun infinito").
        if ($repeatedFailure) {
            $reasons[] = 'repeated_failure_becomes_learning_proposal';

            return $this->rerunResult($scope, $knownScope, self::RERUN_LEARNING_PROPOSAL, $attempt, $maxRetries, $highRisk, $reasons);
        }
        if ($attempt > $maxRetries) {
            $reasons[] = "auto_retry_budget_exhausted:{$attempt}/{$maxRetries}";

            return $this->rerunResult($scope, $knownScope, self::RERUN_LEARNING_PROPOSAL, $attempt, $maxRetries, $highRisk, $reasons);
        }

        // Rule E — high-risk rerun requires review/escalation before it may run.
        if ($highRisk) {
            $reasons[] = 'high_risk_rerun_requires_review';

            return $this->rerunResult($scope, $knownScope, self::RERUN_REVIEW, $attempt, $maxRetries, $highRisk, $reasons);
        }

        $reasons[] = 'rerun_allowed';

        return $this->rerunResult($scope, $knownScope, self::RERUN_ALLOW, $attempt, $maxRetries, $highRisk, $reasons);
    }

    /**
     * @param list<string> $reasons
     * @return array<string,mixed>
     */
    private function rerunResult(
        string $scope,
        bool $knownScope,
        string $verdict,
        int $attempt,
        int $maxRetries,
        bool $highRisk,
        array $reasons,
    ): array {
        return [
            'schema' => self::SCHEMA,
            'surface' => 'rerun_repair_loop',
            'scope' => $scope === '' ? null : $scope,
            'known_scope' => $knownScope,
            'verdict' => $verdict,
            'may_run' => $verdict === self::RERUN_ALLOW,
            'auto_attempt' => $attempt,
            'max_auto_retries' => $maxRetries,
            'remaining_auto_retries' => max(0, $maxRetries - $attempt),
            'high_risk' => $highRisk,
            'reasons' => $reasons,
        ];
    }

    private function normalizeMaxRetries(mixed $max): int
    {
        if (is_int($max) && $max >= 1) {
            return $max;
        }
        if (is_string($max) && ctype_digit($max) && (int) $max >= 1) {
            return (int) $max;
        }

        return self::DEFAULT_MAX_AUTO_RETRIES;
    }

    private function normalizeAttempt(mixed $attempt): int
    {
        if (is_int($attempt) && $attempt >= 1) {
            return $attempt;
        }
        if (is_string($attempt) && ctype_digit($attempt) && (int) $attempt >= 1) {
            return (int) $attempt;
        }

        return 1;
    }
}
