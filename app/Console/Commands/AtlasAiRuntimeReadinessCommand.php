<?php

namespace App\Console\Commands;

use App\Services\Ai\RuntimeReadiness\AtlasAiRuntimeReadinessService;
use Illuminate\Console\Command;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Atlas AI Runtime Readiness & Release Gate CLI.
 *
 *   php artisan atlas:ai:runtime-readiness --json
 *   php artisan atlas:ai:runtime-readiness --strict --json
 *
 * Exit codes:
 *   0  — operação executada (status pode ser ready/partial/blocked; em --strict
 *        só zero quando status === ready)
 *   1  — runtime exception
 *   2  — usage error
 *   3  — --strict + status != ready (gate falhou)
 */
class AtlasAiRuntimeReadinessCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:runtime-readiness
        {--strict : Exit 3 quando status != ready (CI gate)}
        {--json : Machine-readable JSON output}';

    protected $description = 'Atlas AI Runtime Readiness & Release Gate · agrega Product Cert + Control Plane + Router + Mission Foundation + Mission Mode + Follow-Through + Approval Gates + Learning Loop + Desktop Hyperflow Integration em uma decisão única ready|partial|blocked.';

    public function handle(AtlasAiRuntimeReadinessService $service): int
    {
        try {
            $report = $service->report();
        } catch (Throwable $exception) {
            return $this->renderError($exception);
        }

        $exitCode = 0;
        if ($this->option('strict') && ($report['status'] ?? null) !== AtlasAiRuntimeReadinessService::STATUS_READY) {
            $exitCode = 3;
        }

        $payload = [
            'ok' => ($report['status'] ?? null) === AtlasAiRuntimeReadinessService::STATUS_READY,
            'action' => 'runtime-readiness',
            'strict' => (bool) $this->option('strict'),
            'report' => $report,
        ];

        if ($this->option('json')) {
            $this->line($this->encode($payload));

            return $exitCode;
        }

        $this->line('[atlas:ai:runtime-readiness] status='.($report['status'] ?? 'unknown'));
        $this->line('schema_version='.($report['schema_version'] ?? '—'));
        $this->line('certification_hash='.($report['certification_hash'] ?? '—'));
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

        return $exitCode;
    }

    private function renderError(Throwable $exception): int
    {
        $payload = [
            'ok' => false,
            'action' => 'runtime-readiness',
            'error' => 'exception',
            'exception_class' => $exception::class,
            'message' => $exception->getMessage(),
        ];
        if ($this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $this->error('[atlas:ai:runtime-readiness] exception: '.$exception->getMessage());
        }

        return 1;
    }
}
