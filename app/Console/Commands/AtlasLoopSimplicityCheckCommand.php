<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\TaskServing\AtlasTaskSimplicityContractSentinel;
use Illuminate\Console\Command;

/**
 * Arms the dormant orphan {@see AtlasTaskSimplicityContractSentinel::check()} at the operator surface: checks
 * delivered/claimable task-packet records against the default simplicity contract (anti-over-engineering) and
 * emits the verdict — pass/fail, the blocking violations, and a bounded sample of failing findings.
 *
 * Read-only + facts-only: it audits the supplied records and reports; it never mutates the queue.
 */
final class AtlasLoopSimplicityCheckCommand extends Command
{
    protected $signature = 'atlas:loop:simplicity-check {--records=} {--json}';

    protected $description = 'Read-only simplicity-contract check over task-packet records (anti-over-engineering verdict).';

    public function handle(): int
    {
        $raw = trim((string) $this->option('records'));
        if ($raw === '') {
            return $this->refuse('simplicity-check requires --records=<JSON array of records or a path>');
        }
        if (is_file($raw) && is_readable($raw)) {
            $raw = (string) file_get_contents($raw);
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return $this->refuse('--records must be a JSON array/object');
        }
        $records = isset($decoded['records']) && is_array($decoded['records']) ? $decoded['records'] : $decoded;
        if (! array_is_list($records)) {
            return $this->refuse('--records must be a JSON array of records');
        }

        $verdict = app(AtlasTaskSimplicityContractSentinel::class)->check(array_values($records));

        if ($this->option('json')) {
            $this->line((string) json_encode($verdict, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('status: '.$verdict['status'].'  inspected: '.$verdict['inspected_count'].'  missing: '.$verdict['missing_count'].'  drift: '.$verdict['drift_count']);
            foreach ($verdict['blockers'] as $b) {
                $this->line('  blocker: '.$b);
            }
        }

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        $this->line((string) json_encode([
            'outcome' => 'refused',
            'reason' => 'usage_error',
            'message' => $message,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::FAILURE;
    }
}
