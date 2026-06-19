<?php

namespace App\Console\Commands;

use App\Services\Ai\Cognitive\Failure\SuiteRedTestHandleHarvester;
use Illuminate\Console\Command;

/**
 * S3 — colhe REDS DETERMINÍSTICOS reais de um relatório phpunit JSON para o store de
 * bug-handles (atlas_loop_failure_handles), que abastece a já-ligada bug-fix reproduction
 * lane. Reusa o classificador determinístico para manter SÓ real_failure (descarta
 * environmental/unknown — o filtro anti-flaky). Gated por
 * ATLAS_LOOP_FAILURE_HANDLE_HARVEST_ENABLED; --force roda uma vez manualmente mesmo com a
 * flag desligada (mirror de atlas:failure:auto-feed).
 */
class AtlasLoopFailureHandleHarvestCommand extends Command
{
    protected $signature = 'atlas:loop:failure-handle-harvest
        {--report-path= : Caminho do relatório phpunit JSON a colher}
        {--force : Executa uma vez mesmo com a flag desligada (run manual do operador)}
        {--json : Saída JSON}';

    protected $description = 'S3 — colhe reds determinísticos reais (real_failure) de um relatório phpunit JSON para o store de bug-handles que alimenta a bug-fix reproduction lane.';

    public function handle(SuiteRedTestHandleHarvester $harvester): int
    {
        $reportPath = $this->option('report-path') !== null ? (string) $this->option('report-path') : null;

        $report = $harvester->harvest($reportPath, (bool) $this->option('force'));

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $status = (string) ($report['status'] ?? 'unknown');
        if ($status === 'disabled') {
            $this->warn('Harvest desligado (ATLAS_LOOP_FAILURE_HANDLE_HARVEST_ENABLED=false). Use --force para um run manual.');

            return self::SUCCESS;
        }
        if ($status !== 'ok') {
            $this->error('Harvest não executou: '.$status);

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Colheita: %d reds examinados, %d handles colhidos.',
            (int) $report['scanned'],
            (int) $report['harvested'],
        ));
        $this->line(sprintf(
            '  descartados: environmental=%d unknown=%d sem-handle=%d alvo-ambíguo=%d (write_failed=%d)',
            (int) $report['dropped_environmental'],
            (int) $report['dropped_unknown'],
            (int) $report['dropped_unrunnable'],
            (int) $report['dropped_ambiguous_target'],
            (int) $report['write_failed'],
        ));

        return self::SUCCESS;
    }
}
