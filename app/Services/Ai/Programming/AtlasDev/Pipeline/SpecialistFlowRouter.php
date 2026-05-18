<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Pipeline;

use App\Services\Ai\Programming\AtlasDev\Schemas\CodeDiscoveryManifest;
use App\Services\Ai\Programming\AtlasDev\Schemas\CompactSdd;
use App\Services\Ai\Programming\AtlasDev\Schemas\OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;

/**
 * Atlas Dev Superiority Runtime — specialist flow router.
 *
 * Pure function on observable signals: classifies a planned Atlas Dev run
 * into one of 9 canonical specialist flows (plan / code / debug / review /
 * research / explain / test / refactor / forge_escalation) and chooses a
 * path (fast / deep / ask_clarification / escalate). Never asks a model.
 *
 * Sits AFTER {@see RoutingDecisionEngine} so it can read the final routing
 * shape (delegation, forge preview, blocked, fast_path, read_only_answer)
 * and refine the specialist flow without changing the routing kind that
 * downstream services already depend on. The decision is persisted as an
 * artifact (`specialist_flow_decision.json`) and exposed in the Atlas Dev
 * surface payload so the operator sees which flow Atlas Dev believes it is
 * running.
 *
 * Rules (highest precedence wins):
 *
 *   1. Routing already chose DELEGATE_TO_OTHER_FLOW → mirror suggested flow
 *      with `path=fast` (Atlas Dev hands off without picking a path).
 *   2. Routing already chose FORGE_PROMOTION_PREVIEW OR risk in {R4, R5}
 *      → `forge_escalation` / `escalate`.
 *   3. Intent_clarity = blocking AND task implies write → `plan` / `ask`.
 *   4. Intent_clarity = low AND write_implied → `plan` / `deep` (Atlas Dev
 *      drafts a safe plan; operator confirms before code).
 *   5. test/refactor keywords match → `test` or `refactor` (fast or deep
 *      depending on discovery/risk).
 *   6. task_kind = repair → `debug` (fast if R0-R1, deep if R2-R3).
 *   7. task_kind = review → `review` / fast.
 *   8. task_kind = question + workspace_resolved → `explain` / fast.
 *   9. task_kind = question + workspace_unresolved → `research` / deep.
 *  10. task_kind in {patch, frontend, risky} → `code` (fast for R0-R2,
 *      deep for R3).
 *  11. Default fallthrough → `plan` / `ask_clarification` (fail-safe).
 */
final class SpecialistFlowRouter
{
    /**
     * Tokens that strongly imply a test-oriented intent.
     *
     * @var list<string>
     */
    private const TEST_TOKENS = [
        ' test ', ' tests ', ' teste ', ' testes ',
        'phpunit', 'pytest', 'jest ', 'vitest',
        'cobertura', 'coverage',
        'escreva um teste', 'escreva testes', 'write tests', 'add tests',
        'failing test fixture', 'test fixture',
    ];

    /**
     * Tokens that strongly imply a refactor-oriented intent.
     *
     * @var list<string>
     */
    private const REFACTOR_TOKENS = [
        'refactor', 'refator', 'refatorar', 'refatore',
        'rename ', 'renomear', 'renomeie',
        'extract method', 'extrair metodo', 'extrair método',
        'simplify ', 'simplificar',
        'cleanup ', 'limpar ',
        'split into ', 'dividir em',
    ];

    /**
     * Tokens that strongly imply a research-oriented intent (vs explain on
     * the local workspace).
     *
     * @var list<string>
     */
    private const RESEARCH_TOKENS = [
        'pesquise', 'pesquisar', 'pesquisa profunda',
        'estado da arte', 'state of the art',
        'fontes', 'source quality',
        'compare ', 'compara ',
        'benchmark de mercado', 'market research',
    ];

    public function decide(
        OperationEnvelope $envelope,
        TaskClassification $classification,
        CompactSdd $compactSdd,
        CodeDiscoveryManifest $discovery,
        RoutingDecision $routing,
    ): SpecialistFlowDecision {
        $signals = [];
        $reasons = [];

        $flow = $this->classifyFlow($envelope, $classification, $compactSdd, $routing, $signals, $reasons);
        $path = $this->choosePath($flow, $classification, $compactSdd, $routing, $signals, $reasons);

        $ambiguous = $path === SpecialistFlowDecision::PATH_ASK_CLARIFICATION;
        $escalate = $flow === SpecialistFlowDecision::FLOW_FORGE_ESCALATION;

        $payload = [
            'ambiguous' => $ambiguous,
            'escalate_to_forge' => $escalate,
            'matched_signals' => array_values(array_unique($signals)),
            'path' => $path,
            'reasons' => array_values(array_unique($reasons)),
            'risk_level' => $compactSdd->riskLevel,
            'run_id' => $envelope->runId,
            'specialist_flow' => $flow,
            'task_kind' => $classification->taskKind,
        ];
        $hash = CanonicalHasher::hash($payload);

        return new SpecialistFlowDecision(
            runId: $envelope->runId,
            specialistFlow: $flow,
            atlasAiFlowId: SpecialistFlowDecision::FLOW_TO_ATLAS_AI_FLOW_ID[$flow]
                ?? 'atlas_conversation',
            path: $path,
            escalateToForge: $escalate,
            ambiguous: $ambiguous,
            reasons: array_values(array_unique($reasons)),
            matchedSignals: array_values(array_unique($signals)),
            taskKind: $classification->taskKind,
            riskLevel: $compactSdd->riskLevel,
            decisionHash: $hash,
        );
    }

    /**
     * @param  list<string>  $signals
     * @param  list<string>  $reasons
     */
    private function classifyFlow(
        OperationEnvelope $envelope,
        TaskClassification $classification,
        CompactSdd $compactSdd,
        RoutingDecision $routing,
        array &$signals,
        array &$reasons,
    ): string {
        // 1. Router already decided delegation; mirror suggested flow.
        if ($routing->kind === RoutingDecision::DELEGATE_TO_OTHER_FLOW) {
            $reasons[] = 'routing_delegated_to_other_flow';
            $suggested = $routing->delegation?->suggestedFlow;
            if ($suggested !== null) {
                $signals[] = "delegation:{$suggested}";

                return match ($suggested) {
                    'atlas_research' => SpecialistFlowDecision::FLOW_RESEARCH,
                    'atlas_explain' => SpecialistFlowDecision::FLOW_EXPLAIN,
                    'atlas_debug' => SpecialistFlowDecision::FLOW_DEBUG,
                    'atlas_review' => SpecialistFlowDecision::FLOW_REVIEW,
                    'atlas_plan' => SpecialistFlowDecision::FLOW_PLAN,
                    'atlas_forge' => SpecialistFlowDecision::FLOW_FORGE_ESCALATION,
                    default => SpecialistFlowDecision::FLOW_PLAN,
                };
            }

            return SpecialistFlowDecision::FLOW_PLAN;
        }

        // 2. Forge preview OR high risk → forge escalation.
        if ($routing->kind === RoutingDecision::FORGE_PROMOTION_PREVIEW
            || $compactSdd->riskLevel === RiskLevelScorer::R4
            || $compactSdd->riskLevel === RiskLevelScorer::R5) {
            $reasons[] = "high_risk_or_forge_preview:{$compactSdd->riskLevel}";
            $signals[] = "risk:{$compactSdd->riskLevel}";
            $signals[] = "routing:{$routing->kind}";

            return SpecialistFlowDecision::FLOW_FORGE_ESCALATION;
        }

        // 3. Blocking ambiguity with write intent → plan + ask.
        $intentClarity = $envelope->intentClarityLevel;
        if ($intentClarity === IntakeNormalizer::CLARITY_BLOCKING && $classification->writeImplied) {
            $reasons[] = 'intent_clarity_blocking_with_write_intent';
            $signals[] = 'intent_clarity:blocking';

            return SpecialistFlowDecision::FLOW_PLAN;
        }

        // 4. Low clarity + write → plan + deep (Atlas Dev drafts plan).
        if ($intentClarity === IntakeNormalizer::CLARITY_LOW && $classification->writeImplied) {
            $reasons[] = 'intent_clarity_low_with_write_intent';
            $signals[] = 'intent_clarity:low';

            return SpecialistFlowDecision::FLOW_PLAN;
        }

        $normalized = ' '.strtolower($envelope->normalizedIntent).' ';

        // 5. Repair / Review / Question precede generic token overrides — the
        // classifier already detected the dominant intent and "teste falhando"
        // / "review diff" should not be confused with a test/refactor task.
        if ($classification->taskKind === TaskClassification::KIND_REPAIR) {
            $reasons[] = 'task_kind_repair';
            $signals[] = 'task_kind:repair';

            return SpecialistFlowDecision::FLOW_DEBUG;
        }
        if ($classification->taskKind === TaskClassification::KIND_REVIEW) {
            $reasons[] = 'task_kind_review';
            $signals[] = 'task_kind:review';

            return SpecialistFlowDecision::FLOW_REVIEW;
        }

        // 6. Test / refactor token override for non-repair/non-review tasks.
        if ($this->matchAny($normalized, self::TEST_TOKENS)) {
            $reasons[] = 'matched_test_token';
            $signals[] = 'token:test';

            return SpecialistFlowDecision::FLOW_TEST;
        }
        if ($this->matchAny($normalized, self::REFACTOR_TOKENS)) {
            $reasons[] = 'matched_refactor_token';
            $signals[] = 'token:refactor';

            return SpecialistFlowDecision::FLOW_REFACTOR;
        }

        // 7/8. Question routing — explain vs research.
        if ($classification->taskKind === TaskClassification::KIND_QUESTION) {
            $isResearchToken = $this->matchAny($normalized, self::RESEARCH_TOKENS);
            if ($isResearchToken || ! $envelope->preflight->workspaceResolved) {
                $reasons[] = $isResearchToken
                    ? 'question_with_research_token'
                    : 'question_without_workspace';
                $signals[] = $isResearchToken ? 'token:research' : 'workspace:unresolved';

                return SpecialistFlowDecision::FLOW_RESEARCH;
            }
            $reasons[] = 'question_with_workspace_resolved';
            $signals[] = 'task_kind:question';

            return SpecialistFlowDecision::FLOW_EXPLAIN;
        }

        // 10. patch / frontend / risky → code.
        if (in_array($classification->taskKind, [
            TaskClassification::KIND_PATCH,
            TaskClassification::KIND_FRONTEND,
            TaskClassification::KIND_RISKY,
        ], true)) {
            $reasons[] = "task_kind_{$classification->taskKind}_routes_code";
            $signals[] = "task_kind:{$classification->taskKind}";

            return SpecialistFlowDecision::FLOW_CODE;
        }

        // 11. Fallback — safest is to draft a plan and confirm with operator.
        $reasons[] = 'fallback_no_strong_signal';
        $signals[] = 'fallback';

        return SpecialistFlowDecision::FLOW_PLAN;
    }

    /**
     * @param  list<string>  $signals
     * @param  list<string>  $reasons
     */
    private function choosePath(
        string $flow,
        TaskClassification $classification,
        CompactSdd $compactSdd,
        RoutingDecision $routing,
        array &$signals,
        array &$reasons,
    ): string {
        // Forge escalation never executes inside Atlas Dev; it hands off.
        if ($flow === SpecialistFlowDecision::FLOW_FORGE_ESCALATION) {
            return SpecialistFlowDecision::PATH_ESCALATE;
        }

        // Ambiguous write intent → ask before any execution path.
        if ($flow === SpecialistFlowDecision::FLOW_PLAN
            && $classification->writeImplied) {
            $reasons[] = 'plan_with_write_intent_requires_clarification';

            return SpecialistFlowDecision::PATH_ASK_CLARIFICATION;
        }

        // Delegation hands the work to another Atlas AI flow; Atlas Dev is
        // just forwarding, so the path is always fast.
        if ($routing->kind === RoutingDecision::DELEGATE_TO_OTHER_FLOW) {
            $reasons[] = 'routing_delegation_routes_fast';

            return SpecialistFlowDecision::PATH_FAST;
        }

        // Research benefits from deep path by default — even when routing
        // landed on READ_ONLY_ANSWER, research still needs source quality
        // and triangulation, so we keep deep here BEFORE the read-only
        // fast-path shortcut.
        if ($flow === SpecialistFlowDecision::FLOW_RESEARCH) {
            $reasons[] = 'research_uses_deep_path';

            return SpecialistFlowDecision::PATH_DEEP;
        }

        // Read-only / blocked routing rarely needs deep path.
        if (in_array($routing->kind, [
            RoutingDecision::READ_ONLY_ANSWER,
            RoutingDecision::BLOCKED,
        ], true)) {
            $reasons[] = "routing_{$routing->kind}_routes_fast";

            return SpecialistFlowDecision::PATH_FAST;
        }

        $risk = $compactSdd->riskLevel;

        // Code-like flows: deep for R3, fast for R0-R2.
        if (in_array($flow, [
            SpecialistFlowDecision::FLOW_CODE,
            SpecialistFlowDecision::FLOW_TEST,
            SpecialistFlowDecision::FLOW_REFACTOR,
            SpecialistFlowDecision::FLOW_DEBUG,
        ], true)) {
            if ($risk === RiskLevelScorer::R3) {
                $reasons[] = "{$flow}_at_R3_uses_deep_path";
                $signals[] = "path:deep:{$risk}";

                return SpecialistFlowDecision::PATH_DEEP;
            }
            $reasons[] = "{$flow}_at_{$risk}_uses_fast_path";
            $signals[] = "path:fast:{$risk}";

            return SpecialistFlowDecision::PATH_FAST;
        }

        // Explain / review are typically fast.
        if (in_array($flow, [
            SpecialistFlowDecision::FLOW_EXPLAIN,
            SpecialistFlowDecision::FLOW_REVIEW,
        ], true)) {
            $reasons[] = "{$flow}_uses_fast_path";

            return SpecialistFlowDecision::PATH_FAST;
        }

        // Plan with read-only / no-write intent → deep so operator gets a
        // structured plan to confirm.
        return SpecialistFlowDecision::PATH_DEEP;
    }

    /**
     * @param  list<string>  $tokens
     */
    private function matchAny(string $normalized, array $tokens): bool
    {
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if (str_contains($normalized, strtolower($token))) {
                return true;
            }
        }

        return false;
    }
}
