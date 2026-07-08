<?php

declare(strict_types=1);

namespace App\Services\Ai\Kernel\Procedural;

/**
 * The PRODUCER that wakes the general procedural playbook up inside the Dev flow
 * (BUILD #3 was the organ; this is what ties it to real cadence).
 *
 * Two halves, correlated by the Dev run id (the application id):
 *   - {@see injectionLinesForTask()} — task-START: match a playbook by task
 *     category and produce provider-safe injection lines (ADVISORY — merged into
 *     the existing knownFailureModes prompt channel, never blocks). Recording the
 *     attempt is a side effect of the applier, so the follow rate has a
 *     denominator.
 *   - {@see recordOutcomeForTask()} — task-END: feed the REAL outcome through the
 *     SAME OutcomeProofGate (proven_real, never fake-green) to move the measured
 *     rate, and derive a prior-correction from a proven failure.
 *
 * Kill-switch: config('atlas_dev.procedural_playbook.enabled') (default on). The
 * Dev call sites additionally skip under phpunit so the broad Dev suite never
 * pollutes the live ledger; this bridge is unit-tested directly with a temp
 * ledger. Fail-open throughout — procedural memory never breaks a Dev run.
 */
final class AtlasProceduralPlaybookDevBridge
{
    private AtlasProceduralPlaybookApplier $applier;

    public function __construct(
        private readonly AtlasProceduralPlaybookLedger $ledger = new AtlasProceduralPlaybookLedger,
        ?AtlasProceduralPlaybookApplier $applier = null,
    ) {
        $this->applier = $applier ?? new AtlasProceduralPlaybookApplier($this->ledger);
    }

    public function enabled(): bool
    {
        if (! function_exists('config')) {
            return true;
        }

        return (bool) config('atlas_dev.procedural_playbook.enabled', true);
    }

    /**
     * Task-START. Returns provider-safe, self-labeled injection lines for the
     * matched playbook (empty when disabled / no playbook for this category).
     * Uses the run id as the application id so the later outcome correlates.
     *
     * @return list<string>
     */
    public function injectionLinesForTask(string $runId, string $taskCategory): array
    {
        if (! $this->enabled() || trim($runId) === '' || trim($taskCategory) === '') {
            return [];
        }

        $result = $this->applier->apply($taskCategory, $runId);
        if ($result === null) {
            return [];
        }

        return $this->lines(ProceduralPlaybook::fromArray($result['playbook']));
    }

    /**
     * Task-END. Feeds the real outcome through the proof gate to move the
     * measured rate, then — on a PROVEN failure — records a prior-correction so
     * the next retrieval for this category changes. No-op when disabled or when
     * no injection opened an application for this run (degrade-safe: never
     * fabricates a measurement).
     *
     * @param  array<string,mixed>  $execution   execution evidence (commands, tests_run, assertions_executed)
     * @param  list<string>  $changedFiles
     */
    public function recordOutcomeForTask(string $runId, string $status, array $execution = [], array $changedFiles = []): void
    {
        if (! $this->enabled() || trim($runId) === '') {
            return;
        }

        $verdict = $this->ledger->recordOutcome($runId, $status, $execution);

        // A proven failure (real, not credited, not a success claim) is the only
        // thing that may seed a prior-correction — the ledger enforces the same
        // gate; recordFailureCorrection no-ops otherwise, so this stays honest.
        if ($verdict['proven_real'] === true && $verdict['credited'] === false) {
            $this->ledger->recordFailureCorrection($runId, $this->correctionFrom($status, $changedFiles));
        }
    }

    /**
     * @return list<string>
     */
    private function lines(ProceduralPlaybook $playbook): array
    {
        $lines = ['[proven playbook] objetivo: '.$playbook->objective];
        foreach ($playbook->steps as $step) {
            $lines[] = '[proven playbook] passo: '.$step;
        }
        foreach ($playbook->forbiddenActions as $forbidden) {
            $lines[] = '[proven playbook] NAO fazer: '.$forbidden;
        }
        foreach ($playbook->postconditions as $postcondition) {
            $lines[] = '[proven playbook] pos-condicao: '.$postcondition;
        }
        foreach ($playbook->priorCorrections as $correction) {
            $lines[] = '[proven playbook] correcao de prior (falha real anterior): '.$correction;
        }

        return $lines;
    }

    /**
     * @param  list<string>  $changedFiles
     */
    private function correctionFrom(string $status, array $changedFiles): string
    {
        $area = $changedFiles === [] ? 'area nao registrada' : implode(', ', array_slice($changedFiles, 0, 5));

        return sprintf('seguir o playbook resultou em outcome "%s" (nao proven-success) — revisar passos para: %s', $status, $area);
    }
}
