<?php

namespace App\Console\Commands;

use App\Console\Concerns\EmitsCanonicalJson;
use App\Services\Ai\Programming\Governance\ProgrammingGovernanceService;
use Illuminate\Console\Command;
use Throwable;
use App\Support\YesNo;

/**
 * Run the Programming Governance gate pipeline for a work item.
 *
 * @see docs/engineering-knowledge-base/atlas-programming-governance-system-runbook.md
 */
class AtlasProgrammingVerifyCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:programming:verify
        {work_item : Work item code or UUID}
        {--gate=* : Run only these named gates (default: all required for scope_mode)}
        {--strict : Exit non-zero when a blocking gate fails}
        {--json : Print machine-readable JSON}';

    protected $description = 'Run governance gates against a programming work item.';

    public function handle(ProgrammingGovernanceService $governance): int
    {
        try {
            $workItem = $governance->find((string) $this->argument('work_item'));
            $gates = array_values(array_filter((array) $this->option('gate'), 'is_string'));
            $strict = (bool) $this->option('strict');
            $payload = $governance->verify($workItem, $gates === [] ? null : $gates, $strict);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line($this->encodeOrEmptyObject($payload));

            return $this->resolveExit($payload, $strict);
        }

        $summary = $payload['gate_summary'];
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Programming Verify</>', $payload['code']);
        $this->components->twoColumnDetail('Scope mode', $payload['scope_mode']);
        $this->components->twoColumnDetail('All green', YesNo::format($summary['all_green']));
        $this->components->twoColumnDetail('Passed / Failed / Skipped / Waived', sprintf(
            '%d / %d / %d / %d',
            $summary['totals']['passed'],
            $summary['totals']['failed'],
            $summary['totals']['skipped'],
            $summary['totals']['waived'],
        ));
        if ($summary['blocking_failures'] !== []) {
            $this->newLine();
            $this->warn('Blocking failures: '.implode(', ', $summary['blocking_failures']));
        }
        if (! empty($summary['gates_missing'])) {
            $this->components->info('Gates not registered: '.implode(', ', $summary['gates_missing']));
        }
        $this->newLine();
        $this->table(['gate', 'status', 'blocking', 'reason'], array_map(static fn (array $run): array => [
            $run['gate_name'],
            $run['status'],
            YesNo::format($run['blocking']),
            (string) ($run['reason'] ?? ''),
        ], $summary['gate_runs']));

        return $this->resolveExit($payload, $strict);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function resolveExit(array $payload, bool $strict): int
    {
        if (! $strict) {
            return self::SUCCESS;
        }

        $allGreen = (bool) data_get($payload, 'gate_summary.all_green', false);

        return $allGreen ? self::SUCCESS : self::FAILURE;
    }

}
