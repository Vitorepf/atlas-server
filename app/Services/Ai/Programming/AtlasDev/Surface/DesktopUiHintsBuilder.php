<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Surface;

use App\Services\Ai\Programming\AtlasDev\Pipeline\PlanOnlyResult;
use App\Services\Ai\Programming\AtlasDev\Schemas\CodeDiscoveryManifest;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CodeCandidate;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ContextRef;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\MissingRef;
use App\Services\Ai\Programming\AtlasDev\Schemas\ContextRetrievalPlan;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\MiniProgrammingSpec;
use App\Services\Ai\Programming\AtlasDev\Schemas\OpenBrainProgrammingProjection;

/**
 * Desktop-only hints projection.
 *
 * The Desktop AI surface renders Atlas Dev cockpit panels (Contexto, Plano,
 * Execução). To avoid the React layer reaching into each canonical artifact
 * the adapter ships pre-rolled hints — strictly derived from the artifacts
 * already in the {@see PlanOnlyResult}.
 *
 * Invariant: ui_hints NEVER carries a fact that is not also present in an
 * artifact. If the run has not executed yet, slots are explicitly null with a
 * `state="pending"` flag; they are never filled with speculative data.
 *
 * Surface-specific keys (panel names, tab ids) live in this builder ONLY.
 * No other namespace may know them.
 */
final class DesktopUiHintsBuilder
{
    public function __construct(
        private readonly HttpResponseRedactor $redactor = new HttpResponseRedactor,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(PlanOnlyResult $result): array
    {
        return [
            'panel_contexto' => $this->buildContextoPanel(
                contextPlan: $result->contextPlan,
                discovery: $result->discovery,
                projection: $result->projection,
            ),
            'panel_plano' => $this->buildPlanoPanel(
                miniSpec: $result->miniSpec,
                taskContract: $result->taskContract,
                workspace: $result->envelope->workspace,
            ),
            'inline_indicators' => $this->buildInlineIndicators($result),
            'panel_senior_loop' => $this->buildSeniorLoopPanel($result),
            'execution_placeholders' => [
                // Plan-only run: nothing executed yet. Surface MUST treat null
                // as "not produced" and never invent placeholder facts.
                'diff' => ['state' => 'pending', 'value' => null],
                'tests' => ['state' => 'pending', 'value' => null],
                'receipt' => ['state' => 'pending', 'value' => null],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSeniorLoopPanel(PlanOnlyResult $result): array
    {
        if ($result->seniorLoopAudit === null) {
            return [
                'state' => 'pending',
                'audit' => null,
            ];
        }

        return [
            'state' => $result->seniorLoopAudit->status,
            'audit_hash' => $result->seniorLoopAudit->auditHash,
            'capabilities' => $result->seniorLoopAudit->capabilities,
            'blockers' => $result->seniorLoopAudit->blockers,
            'panels' => $result->seniorLoopAudit->desktopCockpit['panels'] ?? [],
            'learning_handoff' => $result->seniorLoopAudit->learningHandoff,
            'multi_step_plan' => $result->seniorLoopAudit->multiStepPlan,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContextoPanel(
        ContextRetrievalPlan $contextPlan,
        CodeDiscoveryManifest $discovery,
        OpenBrainProgrammingProjection $projection,
    ): array {
        return [
            'selected_tiers' => $contextPlan->selectedTiers,
            'budget' => [
                'max_chars' => $contextPlan->budgetChars,
                'truncation_policy' => $contextPlan->truncationPolicy,
            ],
            'memory_refs' => array_map(static fn (ContextRef $r): array => $r->toCanonicalArray(), $projection->memoryRefs),
            'knowledge_refs' => array_map(static fn (ContextRef $r): array => $r->toCanonicalArray(), $projection->knowledgeRefs),
            'code_refs' => array_map(static fn (ContextRef $r): array => $r->toCanonicalArray(), $projection->codeRefs),
            'discovery' => [
                'confidence' => $discovery->confidence,
                'likely_files' => array_map(static fn (CodeCandidate $c): array => $c->toCanonicalArray(), $discovery->likelyFiles),
                'missing_refs' => array_map(static fn (MissingRef $m): array => $m->toCanonicalArray(), $discovery->missingRefs),
            ],
            'missing_sources' => array_values(array_unique(array_merge(
                $contextPlan->missingSources,
                $projection->missingSources,
            ))),
            'truncation' => $projection->truncation,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPlanoPanel(
        MiniProgrammingSpec $miniSpec,
        LightTaskContract $taskContract,
        string $workspace,
    ): array {
        /** @var array<string, mixed> $panel */
        $panel = [
            'objective' => $miniSpec->goal,
            'non_goals' => $miniSpec->nonGoals,
            'expected_files' => $miniSpec->expectedFiles,
            'allowed_files' => $miniSpec->allowedFiles,
            'forbidden_files' => $miniSpec->forbiddenFiles,
            'watched_files' => $taskContract->watchedFiles,
            'acceptance_criteria' => $miniSpec->acceptanceCriteria,
            'validation_commands' => $miniSpec->verificationPlan->commands,
            'stop_conditions' => $miniSpec->completionCriteria,
            'escalation_conditions' => $taskContract->escalationOn,
            'max_files_changed' => $taskContract->maxFilesChanged,
        ];

        return $this->redactor->redactWorkspaceIn($panel, $workspace);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildInlineIndicators(PlanOnlyResult $result): array
    {
        $projection = $result->projection;
        $openBrainState = ($projection->truncation['truncated'] ?? false) === true
            || $projection->missingSources !== []
            ? 'parcial'
            : 'completo';

        return [
            'open_brain' => [
                'state' => $openBrainState,
                'missing_sources_count' => count($projection->missingSources),
            ],
            'escopo' => [
                'n_arquivos' => count($result->miniSpec->allowedFiles),
                'max_files_changed' => $result->taskContract->maxFilesChanged,
            ],
            'verificacao' => [
                // Plan-only: gate not executed. Adapter renders "pending"
                // until a VerificationReceipt is attached in a later phase.
                'state' => 'pending',
                'value' => null,
            ],
            'repair' => [
                'state' => 'pending',
                'attempts' => null,
                'max_attempts' => $result->taskContract->repairPolicy->maxAttempts,
            ],
        ];
    }
}
