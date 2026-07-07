<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Procedural\AtlasProceduralPlaybookLedger;
use App\Services\Ai\Kernel\Procedural\ProceduralPlaybook;
use Illuminate\Console\Command;

/**
 * ATLAS BUILD #3 SLICE 1 — operator/loop surface for the general procedural
 * playbook (the consumer face of {@see AtlasProceduralPlaybookLedger}).
 *
 *   atlas:playbook define --json-in='{"task_category":"backend_bugfix", ...}'
 *   atlas:playbook show backend_bugfix --json
 */
class AtlasProceduralPlaybookCommand extends Command
{
    protected $signature = 'atlas:playbook
        {action : define|show}
        {category? : task category to show (for action=show)}
        {--json-in= : JSON playbook payload (for action=define)}
        {--json : machine-readable output}';

    protected $description = 'Define / retrieve a general procedural playbook (objective, steps, postconditions, forbidden actions, prior corrections) by task category (ATLAS BUILD #3).';

    public function handle(AtlasProceduralPlaybookLedger $ledger): int
    {
        return match ((string) $this->argument('action')) {
            'define' => $this->define($ledger),
            'show' => $this->show($ledger),
            default => $this->bail('Unknown action. Use: define | show'),
        };
    }

    private function define(AtlasProceduralPlaybookLedger $ledger): int
    {
        $raw = (string) ($this->option('json-in') ?? '');
        $payload = json_decode($raw, true);
        if (! is_array($payload) || trim((string) ($payload['task_category'] ?? '')) === '') {
            return $this->bail('--json-in must be a JSON object with a non-empty "task_category".');
        }

        $playbook = ProceduralPlaybook::fromArray($payload);
        $ledger->define($playbook);

        return $this->emit($ledger, $playbook->taskCategory, 'defined');
    }

    private function show(AtlasProceduralPlaybookLedger $ledger): int
    {
        $category = (string) ($this->argument('category') ?? '');
        if (trim($category) === '') {
            return $this->bail('show requires a task category argument.');
        }

        return $this->emit($ledger, $category, 'shown');
    }

    private function emit(AtlasProceduralPlaybookLedger $ledger, string $category, string $verb): int
    {
        $playbook = $ledger->retrieve($category);
        $report = [
            'task_category' => $category,
            'status' => $playbook !== null ? 'found' : 'not_found',
            'playbook' => $playbook?->toArray(),
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if ($playbook === null) {
            $this->line(sprintf('[playbook] %s category=%s status=not_found', $verb, $category));

            return self::SUCCESS;
        }

        $this->line(sprintf('[playbook] %s category=%s', $verb, $playbook->taskCategory));
        $this->line('  objective: '.$playbook->objective);
        $this->line('  steps: '.count($playbook->steps).'; postconditions: '.count($playbook->postconditions)
            .'; forbidden: '.count($playbook->forbiddenActions).'; prior_corrections: '.count($playbook->priorCorrections));

        return self::SUCCESS;
    }

    private function bail(string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(['status' => 'error', 'error' => $message], JSON_PRETTY_PRINT));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
