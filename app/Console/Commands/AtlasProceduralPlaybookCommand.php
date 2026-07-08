<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Procedural\AtlasProceduralPlaybookApplier;
use App\Services\Ai\Kernel\Procedural\AtlasProceduralPlaybookLedger;
use App\Services\Ai\Kernel\Procedural\ProceduralPlaybook;
use Illuminate\Console\Command;

/**
 * ATLAS BUILD #3 — operator/loop surface for the general procedural playbook
 * (the consumer face of {@see AtlasProceduralPlaybookLedger} + applier).
 *
 *   atlas:playbook define --json-in='{"task_category":"backend_bugfix", ...}'
 *   atlas:playbook show backend_bugfix --json
 *   atlas:playbook apply backend_bugfix --json            # match + inject
 *   atlas:playbook outcome --application-id=... --status=success --tests-run=3 --assertions=9
 *   atlas:playbook rate backend_bugfix --json             # measured follow rate
 */
class AtlasProceduralPlaybookCommand extends Command
{
    protected $signature = 'atlas:playbook
        {action : define|show|apply|outcome|rate}
        {category? : task category (for show|apply|rate)}
        {--json-in= : JSON playbook payload (for action=define)}
        {--application-id= : application id (for action=outcome)}
        {--status= : real outcome status success|failed|... (for action=outcome)}
        {--tests-run=0 : execution evidence — test cases run (for action=outcome)}
        {--assertions=0 : execution evidence — assertions executed (for action=outcome)}
        {--command=* : execution evidence — command(s) run (for action=outcome)}
        {--json : machine-readable output}';

    protected $description = 'Define / retrieve / apply a general procedural playbook and record its proven-real follow outcome by task category (ATLAS BUILD #3).';

    public function handle(AtlasProceduralPlaybookLedger $ledger, AtlasProceduralPlaybookApplier $applier): int
    {
        return match ((string) $this->argument('action')) {
            'define' => $this->define($ledger),
            'show' => $this->show($ledger),
            'apply' => $this->apply($applier),
            'outcome' => $this->outcome($ledger),
            'rate' => $this->rate($ledger),
            default => $this->bail('Unknown action. Use: define | show | apply | outcome | rate'),
        };
    }

    private function apply(AtlasProceduralPlaybookApplier $applier): int
    {
        $category = (string) ($this->argument('category') ?? '');
        if (trim($category) === '') {
            return $this->bail('apply requires a task category argument.');
        }

        $result = $applier->apply($category);
        if ($result === null) {
            return $this->report(['task_category' => $category, 'status' => 'no_match', 'injection' => null],
                sprintf('[playbook] apply category=%s status=no_match', $category));
        }

        return $this->report($result + ['status' => 'applied'],
            $result['injection']."\n\n(application_id=".$result['application_id'].')');
    }

    private function outcome(AtlasProceduralPlaybookLedger $ledger): int
    {
        $applicationId = (string) ($this->option('application-id') ?? '');
        $status = (string) ($this->option('status') ?? '');
        if (trim($applicationId) === '' || trim($status) === '') {
            return $this->bail('outcome requires --application-id and --status.');
        }

        $commands = array_values(array_filter(array_map(
            static fn (mixed $v): string => is_scalar($v) ? trim((string) $v) : '',
            (array) $this->option('command'),
        )));
        $execution = [];
        $testsRun = (int) $this->option('tests-run');
        $assertions = (int) $this->option('assertions');
        if ($commands !== [] || $testsRun > 0 || $assertions > 0) {
            $execution = ['commands' => $commands, 'tests_run' => $testsRun, 'assertions_executed' => $assertions];
        }

        $verdict = $ledger->recordOutcome($applicationId, $status, $execution);

        return $this->report(['application_id' => $applicationId, 'status' => $status] + $verdict,
            sprintf('[playbook] outcome id=%s status=%s proven_real=%s fake_green=%s credited=%s reason=%s',
                $applicationId, $status,
                $verdict['proven_real'] ? 'true' : 'false',
                $verdict['fake_green'] ? 'true' : 'false',
                $verdict['credited'] ? 'true' : 'false',
                $verdict['reason']));
    }

    private function rate(AtlasProceduralPlaybookLedger $ledger): int
    {
        $category = (string) ($this->argument('category') ?? '');
        if (trim($category) === '') {
            return $this->bail('rate requires a task category argument.');
        }

        $report = $ledger->rateFor($category);

        return $this->report($report, sprintf(
            '[playbook] rate category=%s status=%s attempts=%d successes=%d success_rate=%.2f fake_green_suppressed=%d',
            $report['task_category'], $report['status'], $report['attempts'], $report['successes'],
            $report['success_rate'], $report['fake_green_suppressed'],
        ));
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function report(array $report, string $human): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->line($human);

        return self::SUCCESS;
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
