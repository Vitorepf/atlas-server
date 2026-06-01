<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Deterministic admission gate for the Atlas Code Multi-Project Claude ONE-SHOT
 * implementation prompt.
 *
 * The source doc is the BIG one-shot prompt handed to an external coding agent to
 * deliver the first solid version of multi-project Project/Workspace scoping for
 * Atlas Code and Cartografia. Unlike the sibling `/goal` gate
 * ({@see AtlasCodeMultiProjectClaudeGoalPromptService}), which judges a FINAL
 * report after the work, this gate judges the agent's PROPOSED implementation plan
 * BEFORE the work runs: it admits the plan only when it satisfies the doc's
 * "Critérios de aceite" AND the section-6 "Safety/risk" admission rules, and it
 * refuses any plan that would run an unscoped command, treat a Project as an Obra,
 * or ship fake runtime.
 *
 * It is pure and read-only: it never runs the agent, never edits the workspace,
 * never creates an Obra. It only renders a typed admit/reject decision over a
 * proposed plan + the active Project/Workspace profile, so an unsafe or
 * dishonest plan is caught before it touches code.
 *
 * Documented contract this code genuinely enforces:
 *
 *  - "Critérios de aceite" (10 named criteria, lines 224-234) — each is a checkable
 *    obligation the plan must assert. A plan is admissible only when every criterion
 *    holds. Missing criteria are enumerated and lower the satisfied/total tally.
 *
 *  - "Conceitos obrigatorios" + "Critérios de aceite": Project is not Obra. A plan
 *    that frames the whole product/repository (Atlas, Blackink, ...) as a single
 *    Obra is rejected with `project_treated_as_obra` — "Blackink e Atlas sao
 *    Projetos/Workspaces, nao Obras."
 *
 *  - "Nao ha runtime fake dizendo que algo esta implementado sem estar": a plan that
 *    claims something is implemented while leaving it as unflagged scaffold is a
 *    fake-runtime violation and is rejected.
 *
 *  - Section 6 "Safety/risk" — the admission rules neither sibling combines:
 *      * "Nunca rodar comando sem workspace_path/repo_root resolvido." Any planned
 *        command without a resolved workspace path is blocked.
 *      * "Se workspace_path ausente, bloquear execucao e permitir apenas
 *        Consulta/Descoberta." With no resolved workspace path the ONLY admissible
 *        work types are Consulta and Candidato de Obra (discovery). Planning an
 *        Intervencao Rapida or an Obra without a resolved workspace is blocked.
 *      * "Para project production_status=production, UI deve destacar risco padrao
 *        maior para Intervencao Rapida/Obra." A production project floors the
 *        effective risk to at least `high` for change work and flags that the plan
 *        must surface the elevated risk.
 *
 * The decision is a closed set: `admit` (every criterion satisfied, no blocking
 * safety/identity violation) or `reject` (any criterion missing or any blocker).
 *
 * @see docs/engineering-knowledge-base/atlas-code-multi-project-claude-one-shot-prompt.md
 */
final class AtlasCodeMultiProjectClaudeOneShotPromptService
{
    /** Stable schema id this gate emits. */
    public const SCHEMA_VERSION = 'atlas.code_multi_project_claude_one_shot_prompt_gate.v1';

    /** Closed-set decisions. */
    public const DECISION_ADMIT = 'admit';
    public const DECISION_REJECT = 'reject';

    /** Documented work types ("Conceitos obrigatorios"). Only these are routed. */
    public const WORK_CONSULTA = 'consulta';
    public const WORK_INTERVENCAO_RAPIDA = 'intervencao_rapida';
    public const WORK_CANDIDATO_DE_OBRA = 'candidato_de_obra';
    public const WORK_OBRA = 'obra';

    /**
     * The ten "Critérios de aceite" from the doc, in documented order. Each maps to
     * a boolean key the plan is expected to assert.
     *
     * @var array<int, array{key: string, label: string}>
     */
    private const ACCEPTANCE_CRITERIA = [
        ['key' => 'project_read_model_exists', 'label' => 'Existe um Project/Workspace read-model real.'],
        ['key' => 'desktop_shows_active_project', 'label' => 'Desktop mostra o Projeto ativo.'],
        ['key' => 'existing_obras_still_work', 'label' => 'Atlas Code continua funcionando para Obras existentes.'],
        ['key' => 'create_select_obra_unbroken', 'label' => 'Criar/selecionar Obra nao quebra.'],
        ['key' => 'no_giant_side_tree', 'label' => 'UI nao vira arvore lateral gigante.'],
        ['key' => 'design_system_preserved', 'label' => 'Design system atual foi preservado.'],
        ['key' => 'atlas_blackink_are_projects', 'label' => 'Atlas e Blackink sao tratados como Projetos, nao Obras.'],
        ['key' => 'path_to_other_work_types_clear', 'label' => 'Caminho claro para Consulta, Intervencao Rapida e Candidato de Obra.'],
        ['key' => 'no_fake_runtime', 'label' => 'Nao ha runtime fake dizendo que algo esta implementado sem estar.'],
        ['key' => 'validations_reported', 'label' => 'Testes/validacoes relatados no final.'],
    ];

    /**
     * Evaluate an external agent's PROPOSED implementation plan for the one-shot
     * prompt against the documented admission contract.
     *
     * @param array<string, mixed> $activeProject Active Project/Workspace profile. Recognised keys:
     *   workspace_path     (string)  resolved => execution admissible
     *   repo_root          (string)  alternative resolved path
     *   production_status  (string)  'production' floors change-work risk to >= high
     *   default_risk       (string)  low|medium|high (default medium)
     * @param array<string, mixed> $plan The agent's proposed plan. Recognised keys:
     *   work_type                 (string)  consulta|intervencao_rapida|candidato_de_obra|obra
     *   planned_commands          (array)   each {command, workspace_resolved?:bool}
     *   treats_project_as_obra    (bool)    plan frames whole product/repo as one Obra
     *   claims_done_but_scaffold  (bool)    plan claims implemented while leaving unflagged scaffold
     *   acceptance_criteria       (array<string,bool>) per-criterion plan assertions
     *
     * @return array{
     *   schema_version: string,
     *   decision: string,
     *   admitted: bool,
     *   work_type: string,
     *   execution_allowed: bool,
     *   execution_blocked_reason: string|null,
     *   effective_risk: string,
     *   risk_elevated_for_production: bool,
     *   blocking_violations: array<int, string>,
     *   missing_acceptance_criteria: array<int, string>,
     *   acceptance_ratio: array{satisfied:int, total:int},
     *   rationale: array<int, string>,
     *   required_next_action: string
     * }
     */
    public function admitPlan(array $activeProject, array $plan): array
    {
        $rationale = [];
        $blocking = [];

        $workType = $this->normalizeWorkType($plan['work_type'] ?? self::WORK_CONSULTA);
        $isChangeWork = in_array($workType, [self::WORK_INTERVENCAO_RAPIDA, self::WORK_OBRA], true);

        $workspacePath = $this->str($activeProject, 'workspace_path');
        $repoRoot = $this->str($activeProject, 'repo_root');
        $workspaceResolved = $workspacePath !== '' || $repoRoot !== '';
        $isProduction = strtolower($this->str($activeProject, 'production_status')) === 'production';

        // ── Section 6: "Se workspace_path ausente, bloquear execucao e permitir
        //    apenas Consulta/Descoberta." Discovery == Consulta | Candidato de Obra.
        $executionAllowed = $workspaceResolved;
        $executionBlockedReason = null;
        $discoveryOnly = [self::WORK_CONSULTA, self::WORK_CANDIDATO_DE_OBRA];
        if (! $workspaceResolved) {
            $executionBlockedReason = 'unresolved_workspace: sem workspace_path/repo_root resolvido; apenas Consulta/Descoberta liberada.';
            $rationale[] = 'Workspace nao resolvido: execucao bloqueada (regra section 6).';
            if (! in_array($workType, $discoveryOnly, true)) {
                $blocking[] = 'execution_without_workspace: plano "' . $workType . '" exige workspace resolvido; sem ele so Consulta/Descoberta e admissivel.';
            }
        }

        // ── Section 6: "Nunca rodar comando sem workspace_path/repo_root resolvido."
        $plannedCommands = $this->asList($plan['planned_commands'] ?? []);
        foreach ($plannedCommands as $i => $cmd) {
            $resolved = is_array($cmd) ? (bool) ($cmd['workspace_resolved'] ?? false) : false;
            if (! $resolved) {
                $label = is_array($cmd) ? (string) ($cmd['command'] ?? "#$i") : (string) $cmd;
                $blocking[] = "unscoped_command: comando planejado sem workspace/repo resolvido ($label).";
            }
        }

        // ── "Conceitos obrigatorios": Project is not Obra.
        if (($plan['treats_project_as_obra'] ?? false) === true) {
            $blocking[] = 'project_treated_as_obra: plano trata o Projeto/Workspace inteiro como Obra (Atlas/Blackink sao Projetos, nao Obras).';
        }

        // ── "Nao ha runtime fake": claiming done while leaving unflagged scaffold.
        if (($plan['claims_done_but_scaffold'] ?? false) === true) {
            $blocking[] = 'fake_runtime: plano declara algo implementado mantendo scaffold nao marcado.';
        }

        // ── Section 6: production floors change-work risk to >= high.
        $baseRisk = $this->normalizeRisk($this->str($activeProject, 'default_risk', 'medium'));
        $effectiveRisk = $baseRisk;
        $riskElevated = false;
        if ($isProduction && $isChangeWork) {
            $effectiveRisk = $this->maxRisk($baseRisk, 'high');
            if ($effectiveRisk !== $baseRisk || $baseRisk === 'high') {
                $riskElevated = true;
            }
            $rationale[] = 'Projeto em producao: risco padrao de Intervencao Rapida/Obra elevado para no minimo "high".';
        }

        // ── "Critérios de aceite": every criterion must hold to admit.
        $criteria = $plan['acceptance_criteria'] ?? [];
        $criteria = is_array($criteria) ? $criteria : [];
        $missing = [];
        $satisfied = 0;
        foreach (self::ACCEPTANCE_CRITERIA as $item) {
            if (($criteria[$item['key']] ?? false) === true) {
                $satisfied++;
            } else {
                $missing[] = $item['key'] . ': ' . $item['label'];
            }
        }
        $total = count(self::ACCEPTANCE_CRITERIA);

        // Final decision: admit only when nothing blocks AND all criteria satisfied.
        $admitted = $blocking === [] && $satisfied === $total;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'decision' => $admitted ? self::DECISION_ADMIT : self::DECISION_REJECT,
            'admitted' => $admitted,
            'work_type' => $workType,
            'execution_allowed' => $executionAllowed,
            'execution_blocked_reason' => $executionBlockedReason,
            'effective_risk' => $effectiveRisk,
            'risk_elevated_for_production' => $riskElevated,
            'blocking_violations' => array_values($blocking),
            'missing_acceptance_criteria' => array_values($missing),
            'acceptance_ratio' => ['satisfied' => $satisfied, 'total' => $total],
            'rationale' => array_values($rationale),
            'required_next_action' => $this->requiredNextAction($admitted, $blocking, $missing),
        ];
    }

    /**
     * The ten "Critérios de aceite" keys, in documented order (read-only).
     *
     * @return array<int, string>
     */
    public function acceptanceCriteriaKeys(): array
    {
        return array_map(static fn (array $i): string => $i['key'], self::ACCEPTANCE_CRITERIA);
    }

    /**
     * The four documented work types ("Conceitos obrigatorios"), escalation order.
     *
     * @return array<int, string>
     */
    public function workTypes(): array
    {
        return [
            self::WORK_CONSULTA,
            self::WORK_INTERVENCAO_RAPIDA,
            self::WORK_CANDIDATO_DE_OBRA,
            self::WORK_OBRA,
        ];
    }

    /**
     * @param array<int, string> $blocking
     * @param array<int, string> $missing
     */
    private function requiredNextAction(bool $admitted, array $blocking, array $missing): string
    {
        if ($admitted) {
            return 'Admitir plano: cumpre Critérios de aceite e regras de Safety/risk; pode iniciar a primeira fatia.';
        }
        if ($blocking !== []) {
            return 'Rejeitar plano: corrigir violacoes de Safety/identidade antes de iniciar (workspace/Projeto-vs-Obra/runtime fake).';
        }

        return 'Rejeitar plano: completar Critérios de aceite faltantes (' . count($missing) . ') antes de admitir.';
    }

    private function normalizeWorkType($value): string
    {
        $value = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($value, [
            self::WORK_CONSULTA,
            self::WORK_INTERVENCAO_RAPIDA,
            self::WORK_CANDIDATO_DE_OBRA,
            self::WORK_OBRA,
        ], true) ? $value : self::WORK_CONSULTA;
    }

    private function normalizeRisk(string $risk): string
    {
        $risk = strtolower(trim($risk));

        return in_array($risk, ['low', 'medium', 'high'], true) ? $risk : 'medium';
    }

    private function maxRisk(string $a, string $b): string
    {
        $rank = ['low' => 1, 'medium' => 2, 'high' => 3];
        $na = $this->normalizeRisk($a);
        $nb = $this->normalizeRisk($b);

        return ($rank[$na] ?? 2) >= ($rank[$nb] ?? 2) ? $na : $nb;
    }

    /**
     * @param array<string, mixed> $source
     */
    private function str(array $source, string $key, string $default = ''): string
    {
        $value = $source[$key] ?? $default;

        return is_string($value) ? trim($value) : $default;
    }

    /**
     * @param mixed $value
     * @return array<int, mixed>
     */
    private function asList($value): array
    {
        return is_array($value) ? array_values($value) : [];
    }
}
