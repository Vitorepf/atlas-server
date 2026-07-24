<?php

namespace App\Console\Commands;

use App\Services\Ai\Learning\Failure\FailureAutoFeedHarvester;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * AP-819 F1 — colhe falhas reais de runtime para o corpus failure_signatures.
 * Observe-only: escreve só no corpus de falhas + alertas; nenhuma proposta.
 * Agendável (gated por ATLAS_FAILURE_AUTO_FEED_ENABLED); --force roda uma vez
 * manualmente mesmo com a flag desligada.
 */
class AtlasFailureAutoFeedCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:failure:auto-feed
        {--window-hours= : Janela de colheita em horas (default config)}
        {--limit= : Máximo de linhas por execução (default config)}
        {--force : Executa uma vez mesmo com a flag desligada (run manual do operador)}
        {--json : Saída JSON}';

    protected $description = 'AP-819 F1 — auto-feed do cérebro de falhas: ai_job_attempts failed/timeout + ledger OPERATION_FAILED → failure_signatures.';

    public function handle(FailureAutoFeedHarvester $harvester): int
    {
        $windowHours = $this->option('window-hours') !== null ? (int) $this->option('window-hours') : null;
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $report = $harvester->harvest($windowHours, $limit, (bool) $this->option('force'));

        if ($this->option('json')) {
            $this->line($this->encode($report));

            return self::SUCCESS;
        }

        $status = (string) ($report['status'] ?? 'unknown');
        if ($status === 'disabled') {
            $this->warn('Auto-feed desligado (ATLAS_FAILURE_AUTO_FEED_ENABLED=false). Use --force para um run manual.');

            return self::SUCCESS;
        }
        if ($status !== 'ok') {
            $this->error('Auto-feed não executou: '.$status);

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Colheita: %d examinadas, %d colhidas, %d já existentes, %d alertas (janela %dh).',
            (int) $report['scanned'],
            (int) $report['harvested'],
            (int) $report['skipped_existing'],
            (int) $report['alerts_triggered'],
            (int) $report['window_hours'],
        ));
        foreach ((array) $report['skipped_noise'] as $reason => $count) {
            $this->line(sprintf('  ruído barrado [%s]: %d', $reason, (int) $count));
        }

        return self::SUCCESS;
    }
}
