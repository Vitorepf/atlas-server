<?php

namespace App\Console\Commands;

use App\Services\Ai\ProgrammingRuntime\ProgrammingRuntimeReadinessCanon;
use App\Services\Ai\ProgrammingRuntime\ProgrammingRuntimeReadinessService;
use Illuminate\Console\Command;

class AtlasAiProgrammingRuntimeCommand extends Command
{
    protected $signature = 'atlas:ai:programming-runtime
        {--action=readiness : readiness}
        {--json : output JSON only}';

    protected $description = 'Honest readiness/certification for the Atlas AI Programming Runtime (atlas.programming.runtime_readiness.v1).';

    public function handle(ProgrammingRuntimeReadinessService $readiness): int
    {
        $action = (string) $this->option('action');
        if ($action !== 'readiness') {
            $this->error("invalid action [{$action}]; supported: readiness");

            return Command::FAILURE;
        }

        $report = $readiness->report();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->renderHuman($report);
        }

        // Exit code matches operational status:
        // - green  -> 0
        // - partial-> 0 (CI-soft) but visible
        // - blocked-> 1 (CI hard fail)
        return $report['status'] === ProgrammingRuntimeReadinessCanon::STATUS_BLOCKED
            ? Command::FAILURE
            : Command::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function renderHuman(array $report): void
    {
        $this->line(sprintf(
            '[%s] schema=%s generated_at=%s',
            strtoupper((string) $report['status']),
            (string) $report['schema'],
            (string) $report['generated_at'],
        ));
        $summary = (array) $report['summary'];
        $this->line(sprintf(
            'total=%d green=%d warn=%d blocked=%d (p0_blocked=%d, p1_blocked=%d)',
            (int) ($summary['total_checks'] ?? 0),
            (int) ($summary['green'] ?? 0),
            (int) ($summary['warn'] ?? 0),
            (int) ($summary['blocked'] ?? 0),
            (int) ($summary['p0_blocked'] ?? 0),
            (int) ($summary['p1_blocked'] ?? 0),
        ));
        foreach ((array) $report['checks'] as $check) {
            $this->line(sprintf(
                '  [%s/%s] %s — %s',
                (string) $check['severity'],
                strtoupper((string) $check['status']),
                (string) $check['id'],
                (string) $check['detail'],
            ));
        }
        if (! empty($report['next_actions'])) {
            $this->line('next_actions:');
            foreach ((array) $report['next_actions'] as $action) {
                $this->line('  - '.$action);
            }
        }
    }
}
