<?php

namespace App\Console\Commands;

use App\Services\Ai\RuntimeReleaseGate\AtlasAiRuntimeReleaseGateService;
use Illuminate\Console\Command;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Atlas AI Runtime Release Gate Aggregator CLI.
 *
 *   php artisan atlas:ai:runtime-release-gate --json
 *   php artisan atlas:ai:runtime-release-gate --strict --json
 *
 * Exit codes:
 *   0  — operação executada (status pode ser ready/partial/blocked; em --strict
 *        só zero quando status === ready)
 *   1  — runtime exception
 *   3  — --strict + status != ready (gate failed)
 */
class AtlasAiRuntimeReleaseGateCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:runtime-release-gate
        {--strict : Exit 3 quando status != ready (CI release gate)}
        {--json : Machine-readable JSON output}';

    protected $description = 'Atlas AI Runtime Release Gate · agrega evidência dos macros Hyperflow / Mission / Follow-Through / Approval / Learning / UX em uma decisão única ready|partial|blocked para o macro atlas_ai_hyperflow_runtime_principal.';

    public function handle(AtlasAiRuntimeReleaseGateService $service): int
    {
        try {
            $report = $service->report();
        } catch (Throwable $exception) {
            return $this->renderError($exception);
        }

        $exitCode = 0;
        $status = (string) ($report['status'] ?? AtlasAiRuntimeReleaseGateService::STATUS_BLOCKED);
        if ($this->option('strict') && $status !== AtlasAiRuntimeReleaseGateService::STATUS_READY) {
            $exitCode = 3;
        }

        $payload = [
            'ok' => $status === AtlasAiRuntimeReleaseGateService::STATUS_READY,
            'action' => 'runtime-release-gate',
            'strict' => (bool) $this->option('strict'),
            'report' => $report,
        ];

        if ($this->option('json')) {
            $this->line($this->encode($payload));

            return $exitCode;
        }

        $this->line('[atlas:ai:runtime-release-gate] status='.$status);
        $this->line('macro='.((string) ($report['macro'] ?? '—')));
        $this->line('schema_version='.((string) ($report['schema_version'] ?? '—')));
        $this->line('certification_hash='.((string) ($report['certification_hash'] ?? '—')));
        $summary = (array) ($report['summary'] ?? []);
        $this->line(sprintf(
            'checks: total=%d passed=%d partial=%d failed=%d critical_failed=%d',
            (int) ($summary['total'] ?? 0),
            (int) ($summary['passed'] ?? 0),
            (int) ($summary['partial'] ?? 0),
            (int) ($summary['failed'] ?? 0),
            (int) ($summary['critical_failed'] ?? 0),
        ));
        if (! empty($report['blockers'])) {
            $this->line('blockers: '.implode(', ', (array) $report['blockers']));
        }
        if (! empty($report['warnings'])) {
            $this->line('warnings: '.implode(', ', (array) $report['warnings']));
        }
        $recommendation = (array) ($report['next_macro_recommendation'] ?? []);
        if (! empty($recommendation['next_macro'])) {
            $this->line('next_macro='.(string) $recommendation['next_macro']);
        }

        return $exitCode;
    }

    private function renderError(Throwable $exception): int
    {
        $payload = [
            'ok' => false,
            'action' => 'runtime-release-gate',
            'error' => 'exception',
            'exception_class' => $exception::class,
            'message' => $exception->getMessage(),
        ];
        if ($this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $this->error('[atlas:ai:runtime-release-gate] exception: '.$exception->getMessage());
        }

        return 1;
    }
}
