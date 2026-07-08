<?php

declare(strict_types=1);

namespace App\Services\Ai\Kernel\Procedural;

/**
 * ATLAS BUILD #3 SLICE 2 — the applier: given a task, match its procedural
 * playbook and INJECT it into the work context as a provider-safe markdown
 * block (the same channel/shape as the context pack's `## Obrigatorio` /
 * `## Nao Fazer` sections), then record the attempt so its REAL outcome can
 * update the measured follow rate.
 *
 * Producer: {@see apply()} matches + injects + records `applied`.
 * The consumer half (outcome → measured rate) lives on the ledger's
 * {@see AtlasProceduralPlaybookLedger::recordOutcome()}.
 */
final class AtlasProceduralPlaybookApplier
{
    public function __construct(
        private readonly AtlasProceduralPlaybookLedger $ledger,
    ) {}

    /**
     * Match a task category to its playbook and produce the injection. Records
     * the attempt under a caller-owned (or minted) application id so the later
     * outcome correlates. Returns null when no playbook matches — the caller
     * simply proceeds without procedural injection (never a fabricated one).
     *
     * @return array{application_id:string,task_category:string,injection:string,playbook:array<string,mixed>}|null
     */
    public function apply(string $taskCategory, ?string $applicationId = null): ?array
    {
        $playbook = $this->ledger->retrieve($taskCategory);
        if ($playbook === null) {
            return null;
        }

        $applicationId = ($applicationId !== null && trim($applicationId) !== '')
            ? trim($applicationId)
            : uniqid('pbapp_', true);

        $this->ledger->recordApplied($applicationId, $taskCategory);

        return [
            'application_id' => $applicationId,
            'task_category' => $playbook->taskCategory,
            'injection' => $this->renderInjection($playbook),
            'playbook' => $playbook->toArray(),
        ];
    }

    /**
     * Provider-safe markdown injection block — steps + forbidden actions +
     * postconditions (+ prior corrections when present, folded by Slice 3).
     */
    public function renderInjection(ProceduralPlaybook $playbook): string
    {
        $lines = [
            '## Playbook Procedural (herdado de tarefas anteriores)',
            'Objetivo: '.$playbook->objective,
        ];

        $lines = $this->section($lines, '### Passos', $playbook->steps);
        $lines = $this->section($lines, '### Nao Fazer (acoes proibidas)', $playbook->forbiddenActions);
        $lines = $this->section($lines, '### Pos-condicoes (prova de que a tarefa landou)', $playbook->postconditions);
        $lines = $this->section($lines, '### Correcoes de priors (falhas reais anteriores)', $playbook->priorCorrections);

        return implode("\n", $lines);
    }

    /**
     * @param  list<string>  $lines
     * @param  list<string>  $items
     * @return list<string>
     */
    private function section(array $lines, string $heading, array $items): array
    {
        if ($items === []) {
            return $lines;
        }
        $lines[] = '';
        $lines[] = $heading;
        foreach ($items as $item) {
            $lines[] = '- '.$item;
        }

        return $lines;
    }
}
