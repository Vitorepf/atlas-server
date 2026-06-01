<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Deterministic acceptance gate for the Atlas Code Multi-Project `/goal` closing prompt.
 *
 * The source doc is a closing `/goal` prompt handed to an external coding agent
 * AFTER the big one-shot multi-project implementation prompt. Its job is to FORCE
 * professional completion, validation and honest residual-risk reporting. This
 * service turns that prompt's contract into runtime: given the agent's final
 * report, it decides whether the report may be ACCEPTED or must be REJECTED, and
 * enumerates exactly which documented obligations are unmet.
 *
 * It is read-only: it never runs the agent, never edits the workspace and never
 * marks an implementation "done" durably — it only renders an accept/reject
 * decision over a self-reported completion claim, so a dishonest "done" without
 * evidence is caught instead of trusted.
 *
 * Documented contract this code genuinely enforces:
 *
 *  - "Regras para IA" (hard rejection rules — non-negotiable):
 *      * "Nao aceitar resposta sem arquivos alterados ou sem validacoes." =>
 *        a final report with zero changed files, OR with zero validations run,
 *        is REJECTED.
 *      * "Nao aceitar conclusao sem riscos e proximos passos." => a report that
 *        omits residual risks OR omits next steps is REJECTED.
 *    These four are the doc's blocking gate: failing ANY of them forces
 *    decision=reject regardless of the Definition-of-Done tally.
 *
 *  - "Obrigatorio" mandatory clauses, each a checkable obligation:
 *      * honest_scaffold_marking — "Nao crie runtime fake. Se algo ficou
 *        scaffold, marque honestamente." If the agent reports unfinished
 *        scaffold work it MUST flag it honestly; an unflagged scaffold claim is
 *        a fake-runtime violation and blocks acceptance.
 *      * workspace_guarded_execution — "Garanta que nenhum comando/execucao rode
 *        sem workspace/repo resolvido." Any command reported as run without a
 *        resolved workspace/repo blocks acceptance.
 *      * regressions_fixed — "Corrija regressoes obvias antes de finalizar."
 *        Known-open obvious regressions block acceptance.
 *
 *  - "Definition of done" — a SEVEN-item checklist the agent must satisfy
 *    (project read-model exists; desktop shows active project; existing Obras
 *    still work; path to Blackink/other projects is clear; visual design stays
 *    consistent with Atlas Code; validations executed; final answer lists files,
 *    commands, risks, next steps). Missing items are reported and lower the
 *    completion ratio; a report can only be ACCEPTED when every hard rule passes
 *    AND every Definition-of-Done item is satisfied.
 *
 *  - Product-identity guards from "Obrigatorio": the design system of the Atlas
 *    Code screen must be preserved (not turned into a generic chat / generic IDE
 *    / landing page / confusing side tree) and the Project-vs-Obra separation
 *    must hold (Projeto/Workspace is where software lives; Obra is governed work
 *    inside a Projeto; Atlas and Blackink are Projects, not Obras). A reported
 *    drift on either is a blocking violation.
 *
 * The decision is a closed set: `reject` (any blocking violation present) or
 * `accept` (all hard rules pass, no blocking violation, full Definition of done).
 *
 * @see docs/engineering-knowledge-base/atlas-code-multi-project-claude-goal-prompt.md
 */
final class AtlasCodeMultiProjectClaudeGoalPromptService
{
    /** Stable schema id this gate emits. */
    public const SCHEMA_VERSION = 'atlas.code_multi_project_claude_goal_prompt_gate.v1';

    /** Closed-set decisions. */
    public const DECISION_ACCEPT = 'accept';
    public const DECISION_REJECT = 'reject';

    /**
     * The seven Definition-of-Done items from the doc, in documented order.
     * Each maps to a boolean key the agent's report is expected to assert.
     *
     * @var array<int, array{key: string, label: string}>
     */
    private const DEFINITION_OF_DONE = [
        ['key' => 'project_read_model_exists', 'label' => 'Project/Workspace read-model existe.'],
        ['key' => 'desktop_shows_active_project', 'label' => 'Desktop mostra Projeto ativo.'],
        ['key' => 'existing_obras_still_work', 'label' => 'Atlas Code ainda funciona para Obras existentes.'],
        ['key' => 'path_to_other_projects_clear', 'label' => 'O caminho para Blackink/outros projetos esta claro.'],
        ['key' => 'visual_design_consistent', 'label' => 'Design visual continua consistente com Atlas Code.'],
        ['key' => 'validations_executed', 'label' => 'Validacoes executadas e reportadas.'],
        ['key' => 'final_answer_complete', 'label' => 'Resposta final lista arquivos, comandos, riscos e proximos passos.'],
    ];

    /**
     * Evaluate an external agent's final `/goal` report against the documented
     * completion contract and render a deterministic accept/reject decision.
     *
     * @param array<string, mixed> $report Agent-reported final state. Recognised keys:
     *   changed_files                (array)  files the agent changed
     *   validations_run              (array)  validations/tests the agent executed
     *   residual_risks               (array)  honestly reported residual risks
     *   next_steps                   (array)  reported next steps
     *   commands_ran                 (array)  each {command, workspace_resolved?:bool}
     *   has_unmarked_scaffold        (bool)   unfinished scaffold left unflagged (fake runtime)
     *   open_obvious_regressions     (array)  known obvious regressions still open
     *   design_system_preserved      (bool)   Atlas Code design system kept (not generic chat/IDE/landing/side-tree)
     *   project_obra_separation_held (bool)   Project=software, Obra=governed work; Atlas/Blackink are Projects
     *   definition_of_done           (array<string,bool>) per-item DoD assertions
     *
     * @return array{
     *   schema_version: string,
     *   decision: string,
     *   accepted: bool,
     *   blocking_violations: array<int, string>,
     *   rejection_rules_failed: array<int, string>,
     *   missing_definition_of_done: array<int, string>,
     *   definition_of_done_ratio: array{satisfied:int, total:int},
     *   counts: array{changed_files:int, validations_run:int, residual_risks:int, next_steps:int},
     *   required_next_action: string
     * }
     */
    public function evaluateGoalReport(array $report): array
    {
        $changedFiles = $this->asList($report['changed_files'] ?? []);
        $validations = $this->asList($report['validations_run'] ?? []);
        $risks = $this->asList($report['residual_risks'] ?? []);
        $nextSteps = $this->asList($report['next_steps'] ?? []);
        $commands = $this->asList($report['commands_ran'] ?? []);
        $openRegressions = $this->asList($report['open_obvious_regressions'] ?? []);

        // "Regras para IA" — the doc's hard rejection rules. Each failure is named.
        $rejectionRulesFailed = [];
        if (count($changedFiles) === 0) {
            $rejectionRulesFailed[] = 'no_changed_files: regra "Nao aceitar resposta sem arquivos alterados".';
        }
        if (count($validations) === 0) {
            $rejectionRulesFailed[] = 'no_validations_run: regra "Nao aceitar resposta sem validacoes".';
        }
        if (count($risks) === 0) {
            $rejectionRulesFailed[] = 'no_residual_risks: regra "Nao aceitar conclusao sem riscos".';
        }
        if (count($nextSteps) === 0) {
            $rejectionRulesFailed[] = 'no_next_steps: regra "Nao aceitar conclusao sem proximos passos".';
        }

        // "Obrigatorio" + identity guards — additional blocking violations.
        $blocking = [];

        // Fake runtime / unflagged scaffold.
        if (($report['has_unmarked_scaffold'] ?? false) === true) {
            $blocking[] = 'fake_runtime: scaffold nao marcado honestamente (doc proibe runtime fake).';
        }

        // No command may run without a resolved workspace/repo.
        foreach ($commands as $i => $cmd) {
            $resolved = is_array($cmd) ? (bool) ($cmd['workspace_resolved'] ?? false) : false;
            if (! $resolved) {
                $label = is_array($cmd) ? (string) ($cmd['command'] ?? "#$i") : (string) $cmd;
                $blocking[] = "unscoped_execution: comando rodou sem workspace/repo resolvido ($label).";
            }
        }

        // Obvious regressions must be fixed before finishing.
        if (count($openRegressions) > 0) {
            $blocking[] = 'open_regressions: ' . count($openRegressions) . ' regressao(oes) obvia(s) ainda aberta(s).';
        }

        // Product identity: design system preserved.
        if (($report['design_system_preserved'] ?? true) === false) {
            $blocking[] = 'design_system_drift: tela Atlas Code virou chat/IDE/landing/arvore generica.';
        }

        // Product identity: Project-vs-Obra separation held.
        if (($report['project_obra_separation_held'] ?? true) === false) {
            $blocking[] = 'project_obra_confusion: separacao Projeto(software) vs Obra(trabalho) quebrada.';
        }

        // "Definition of done" — every item must be satisfied to accept.
        $dod = $report['definition_of_done'] ?? [];
        $dod = is_array($dod) ? $dod : [];
        $missingDod = [];
        $satisfied = 0;
        foreach (self::DEFINITION_OF_DONE as $item) {
            if (($dod[$item['key']] ?? false) === true) {
                $satisfied++;
            } else {
                $missingDod[] = $item['key'] . ': ' . $item['label'];
            }
        }
        $totalDod = count(self::DEFINITION_OF_DONE);

        // Final decision: accept only when nothing blocks AND DoD is complete.
        $accepted = count($rejectionRulesFailed) === 0
            && count($blocking) === 0
            && $satisfied === $totalDod;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'decision' => $accepted ? self::DECISION_ACCEPT : self::DECISION_REJECT,
            'accepted' => $accepted,
            'blocking_violations' => array_values($blocking),
            'rejection_rules_failed' => array_values($rejectionRulesFailed),
            'missing_definition_of_done' => array_values($missingDod),
            'definition_of_done_ratio' => ['satisfied' => $satisfied, 'total' => $totalDod],
            'counts' => [
                'changed_files' => count($changedFiles),
                'validations_run' => count($validations),
                'residual_risks' => count($risks),
                'next_steps' => count($nextSteps),
            ],
            'required_next_action' => $accepted
                ? 'Aceitar fechamento: report cumpre Regras para IA, Obrigatorio e Definition of done.'
                : 'Rejeitar fechamento: devolver ao agente as violacoes/itens faltantes antes de aceitar.',
        ];
    }

    /**
     * The seven Definition-of-Done item keys, in documented order (read-only).
     *
     * @return array<int, string>
     */
    public function definitionOfDoneKeys(): array
    {
        return array_map(static fn (array $i): string => $i['key'], self::DEFINITION_OF_DONE);
    }

    /**
     * Normalise a value into a list array (a non-array becomes empty).
     *
     * @param mixed $value
     * @return array<int, mixed>
     */
    private function asList($value): array
    {
        return is_array($value) ? array_values($value) : [];
    }
}
