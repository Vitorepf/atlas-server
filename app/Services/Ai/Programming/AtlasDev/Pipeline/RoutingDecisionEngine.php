<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Pipeline;

use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\CodeDiscoveryManifest;
use App\Services\Ai\Programming\AtlasDev\Schemas\CompactSdd;

/**
 * Decides the canonical routing for an Atlas Dev run.
 *
 * Pure function on observable signals: workspace state, intent clarity,
 * task_kind, risk level, and discovery confidence. Never asks a model. The
 * decision is the final guardrail before the orchestrator returns: any
 * R4/R5 task and any task with blocking discovery confidence must be kept
 * out of the fast path.
 *
 * Out-of-scope intents (research/conversation/debug/explain/forge) take
 * precedence over the regular flow and produce a delegation decision so the
 * surface can forward the operator to the right Atlas AI flow instead of
 * Atlas Dev faking patch/answer behavior.
 */
class RoutingDecisionEngine
{
    public function __construct(
        private readonly ?OutOfScopeDelegationDetector $delegationDetector = null,
    ) {}

    public function decide(
        OperationEnvelope $envelope,
        TaskClassification $classification,
        CompactSdd $compactSdd,
        CodeDiscoveryManifest $discovery,
    ): RoutingDecision {
        $reasons = [];
        $blockers = [];

        $delegation = ($this->delegationDetector ?? new OutOfScopeDelegationDetector)
            ->detect($envelope, $classification);

        if ($delegation !== null) {
            $reasons[] = "delegate_to_{$delegation->suggestedFlow}_because_{$delegation->reason}";

            return new RoutingDecision(
                kind: RoutingDecision::DELEGATE_TO_OTHER_FLOW,
                reasons: $reasons,
                blockers: $blockers,
                delegation: $delegation,
            );
        }

        if (! $envelope->preflight->workspaceResolved) {
            $blockers[] = 'workspace_unresolved';
            $reasons[] = 'workspace_unresolved';
        }
        if ($envelope->intentClarityLevel === IntakeNormalizer::CLARITY_BLOCKING) {
            $blockers[] = 'intent_clarity_blocking';
            $reasons[] = 'intent_clarity_blocking';
        }
        // Ambiguidade de discovery só bloqueia ESCRITA (não pode patchear sem
        // saber onde). Pergunta/review/planejamento são read-only: o provider
        // agêntico explora o workspace sozinho — exigir arquivos confirmados
        // matava metade do dia a dia do operador (matriz 03/07: planejamento/
        // evolução/análise conceituais bloqueados com zero tokens extraídos).
        $readOnlyKinds = [
            TaskClassification::KIND_QUESTION,
            TaskClassification::KIND_REVIEW,
        ];
        if ($discovery->confidence === CodeDiscoveryManifest::CONFIDENCE_BLOCKING_AMBIGUITY
            && ! in_array($classification->taskKind, $readOnlyKinds, true)
        ) {
            $blockers[] = 'discovery_blocking_ambiguity';
            $reasons[] = 'discovery_blocking_ambiguity';
        }

        // High-risk levels never patch on the fast path. They go to preview.
        $risk = $compactSdd->riskLevel;
        if ($risk === RiskLevelScorer::R4 || $risk === RiskLevelScorer::R5) {
            if ($this->allowsRivalsIsolatedRuntimeExecution($envelope)) {
                $reasons[] = "risk_level={$risk}_rivals_isolated_runtime_execution";

                return new RoutingDecision(
                    kind: RoutingDecision::ATLAS_DEV_FAST_PATH,
                    reasons: $reasons,
                    blockers: $blockers,
                );
            }

            $reasons[] = "risk_level={$risk}_requires_forge_preview";

            return new RoutingDecision(
                kind: RoutingDecision::FORGE_PROMOTION_PREVIEW,
                reasons: $reasons,
                blockers: $blockers,
            );
        }

        // Read-only style task kinds answer in place.
        if ($classification->taskKind === TaskClassification::KIND_QUESTION
            || $classification->taskKind === TaskClassification::KIND_REVIEW) {
            $reasons[] = "task_kind={$classification->taskKind}_routes_read_only";

            return new RoutingDecision(
                kind: $blockers === [] ? RoutingDecision::READ_ONLY_ANSWER : RoutingDecision::BLOCKED,
                reasons: $reasons,
                blockers: $blockers,
            );
        }

        // Anything that needs a write and has unresolved blockers cannot run.
        if ($blockers !== []) {
            $reasons[] = 'write_kind_with_blockers';

            return new RoutingDecision(
                kind: RoutingDecision::BLOCKED,
                reasons: $reasons,
                blockers: $blockers,
            );
        }

        // Low-clarity write intents stay in plan-only via read_only_answer so
        // the operator can answer the clarifying question before tokens burn.
        if ($envelope->intentClarityLevel === IntakeNormalizer::CLARITY_LOW
            && $classification->writeImplied) {
            $reasons[] = 'intent_clarity_low_with_write_implied';

            return new RoutingDecision(
                kind: RoutingDecision::READ_ONLY_ANSWER,
                reasons: $reasons,
                blockers: $blockers,
            );
        }

        // Hypothesis-confidence discovery on a write goes plan-only — EXCETO
        // quando o operador deu allowed_files= explícito: tarefa de CRIAÇÃO
        // pura (todos os alvos são arquivos NOVOS) sempre tem discovery
        // hypothesis (não há o que confirmar no disco), e o escopo explícito
        // do operador É a confirmação (lote real 03/07: "crie X.php e o teste"
        // com allowed_files nomeando os 2 alvos era demovido a read-only).
        // Rivals isolated runtime (atlas_dev bridge) also bypasses: SWE/HAL
        // worktrees are disposable and discovery cannot confirm upstream files.
        if ($discovery->confidence === CodeDiscoveryManifest::CONFIDENCE_HYPOTHESIS
            && $classification->writeImplied
            && ! $this->hasExplicitAllowedFilesConstraint($envelope)
            && ! $this->allowsRivalsIsolatedRuntimeExecution($envelope)) {
            $reasons[] = 'discovery_hypothesis_with_write_implied';

            return new RoutingDecision(
                kind: RoutingDecision::READ_ONLY_ANSWER,
                reasons: $reasons,
                blockers: $blockers,
            );
        }

        // Default fast path: write intent + strong/confirmed discovery + clear intent.
        $reasons[] = "write_kind={$classification->taskKind}_risk={$risk}_routes_fast_path";

        return new RoutingDecision(
            kind: RoutingDecision::ATLAS_DEV_FAST_PATH,
            reasons: $reasons,
            blockers: $blockers,
        );
    }

    private function allowsRivalsIsolatedRuntimeExecution(OperationEnvelope $envelope): bool
    {
        // Bridge invokes atlas:cli:dev (surface atlas_cli_dev). Forge rivals
        // path uses atlas_forge_rivals. Both are valid when the operator opts in.
        if (! in_array($envelope->surfaceId, ['atlas_forge_rivals', 'atlas_cli_dev'], true)) {
            return false;
        }
        if (! $envelope->preflight->operatorExplicit) {
            return false;
        }

        foreach ($envelope->userConstraints as $constraint) {
            $normalized = strtolower(trim((string) $constraint));
            if ($normalized === 'rivals_runtime_execution=true') {
                return true;
            }
        }

        return false;
    }

    /**
     * Escopo explícito do operador (allowed_file(s)=...) presente nos
     * constraints: em tarefa de criação pura a discovery nunca confirma
     * (arquivos ainda não existem) — o allowed_files explícito é a âncora
     * de escopo que substitui a confirmação da discovery.
     */
    private function hasExplicitAllowedFilesConstraint($envelope): bool
    {
        foreach ($envelope->userConstraints as $constraint) {
            $normalized = strtolower(trim((string) $constraint));
            if (str_starts_with($normalized, 'allowed_files=') || str_starts_with($normalized, 'allowed_file=')) {
                return trim(substr($normalized, strpos($normalized, '=') + 1)) !== '';
            }
        }

        return false;
    }
}
