<?php

namespace App\Console\Commands;

use App\Services\Ai\RouterRuntime\AtlasHyperflowSpecialistFlowsReadinessService;
use Illuminate\Console\Command;
use App\Support\YesNo;

/**
 * Etapa 1 — Atlas AI Hyperflow + Specialist Flows readiness gate.
 *
 * Stand-alone command paired with
 * {@see AtlasHyperflowSpecialistFlowsReadinessService}. The single supported
 * action is `readiness`, which emits the canonical
 * `atlas.ai.hyperflow_specialist_flows_readiness.v1` envelope.
 *
 * The command never invokes a provider, never runs the rivals battery and
 * never declares Atlas complete. Exit code mirrors the readiness status:
 * 0 when `passed`, 1 when `blocked`.
 */
class AtlasAiHyperflowSpecialistsCommand extends Command
{
    protected $signature = 'atlas:ai:hyperflow-specialists
        {action=readiness : readiness}
        {--json : Emit JSON only}';

    protected $description = 'Etapa 1 readiness gate for Atlas AI Hyperflow + Specialist Flows. Never runs a benchmark.';

    public function handle(AtlasHyperflowSpecialistFlowsReadinessService $service): int
    {
        $action = strtolower(trim((string) $this->argument('action')));
        if ($action !== 'readiness') {
            $this->error('unsupported action ['.$action.']; supported: readiness');

            return self::FAILURE;
        }

        $report = $service->report();

        if ((bool) $this->option('json')) {
            $this->line(json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ) ?: '{}');
        } else {
            $this->renderHuman($report);
        }

        return ($report['status'] ?? 'blocked') === 'passed' ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function renderHuman(array $report): void
    {
        $this->line(sprintf(
            '[hyperflow-specialists] schema=%s status=%s',
            (string) $report['schema_version'],
            (string) $report['status'],
        ));
        $summary = (array) ($report['summary'] ?? []);
        $this->line(sprintf(
            'checks: total=%d passed=%d failed=%d',
            (int) ($summary['total'] ?? 0),
            (int) ($summary['passed'] ?? 0),
            (int) ($summary['failed'] ?? 0),
        ));
        $this->line(sprintf(
            'claim_policy: benchmark_not_run=%s allows_external_superiority_claim=%s',
            YesNo::trueFalse($report['claim_policy']['benchmark_not_run'] ?? true),
            YesNo::trueFalse($report['claim_policy']['allows_external_superiority_claim'] ?? false),
        ));
        foreach ((array) ($report['checks'] ?? []) as $check) {
            $this->line(sprintf(
                '  [%s] %s',
                (string) ($check['status'] ?? '?'),
                (string) ($check['id'] ?? 'unknown'),
            ));
        }
        if (! empty($report['remaining_blockers'])) {
            $this->line('blockers:');
            foreach ((array) $report['remaining_blockers'] as $blocker) {
                $this->line('  - '.(string) $blocker);
            }
        }
    }
}
