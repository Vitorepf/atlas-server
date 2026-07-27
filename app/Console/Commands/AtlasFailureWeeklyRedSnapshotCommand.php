<?php

namespace App\Console\Commands;

use App\Services\Ai\Learning\Failure\SuiteRedTriage;
use App\Services\Ai\Learning\Failure\WeeklyRedCountSnapshotStore;
use Illuminate\Console\Command;
use App\Support\YesNo;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * L5-3 — auto-cura da suíte real: gravador SEMANAL do número REAL de vermelhos.
 *
 * Lê o relatório de teste REAL mais recente (escrito pela suíte/CI) e grava UM
 * snapshot por semana ISO com a contagem real derivada das linhas — a fonte-de-verdade
 * do trend que destrava o claim L5-3. Idempotente por (domain, semana): re-rodar na
 * mesma semana atualiza o valor real, nunca cunha um ponto decrescente falso.
 *
 * Observe-only por construção: só MEDE e persiste. Jamais corrige ou quarentena
 * testes (isso permanece operator-gated no review). Agendável (gated default-OFF);
 * --force roda uma vez mesmo com a flag desligada.
 */
class AtlasFailureWeeklyRedSnapshotCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:failure:weekly-red-snapshot
        {--domain= : Domínio (default config atlas.ai.suite_red_snapshot.domain)}
        {--test-report= : Caminho do relatório real (default config report_path)}
        {--force : Roda mesmo com o schedule desligado}
        {--json : Saída JSON}';

    protected $description = 'L5-3 — grava o snapshot semanal do número REAL de testes vermelhos a partir do último relatório real.';

    public function handle(WeeklyRedCountSnapshotStore $store, SuiteRedTriage $triageHelper): int
    {
        $scheduleEnabled = (bool) config('atlas.ai.suite_red_snapshot.schedule_enabled', false);
        if (! $scheduleEnabled && ! (bool) $this->option('force')) {
            return $this->emit([
                'schema_version' => WeeklyRedCountSnapshotStore::SCHEMA_VERSION,
                'status' => 'disabled',
                'flag' => 'ATLAS_SUITE_RED_SNAPSHOT_SCHEDULE_ENABLED',
                'recorded' => false,
            ]);
        }

        $domain = $this->stringOption('domain')
            ?: (string) config('atlas.ai.suite_red_snapshot.domain', 'programming');
        $reportPath = $this->stringOption('test-report')
            ?: (string) config('atlas.ai.suite_red_snapshot.report_path', storage_path('app/atlas/evidence/suite-red-latest.json'));

        $triage = $triageHelper->triage($reportPath);
        $status = (string) ($triage['status'] ?? 'not_supplied');

        if ($status !== 'triaged') {
            return $this->emit([
                'schema_version' => WeeklyRedCountSnapshotStore::SCHEMA_VERSION,
                'status' => 'no_real_report',
                'reason' => $status,
                'report_path' => $reportPath,
                'recorded' => false,
            ], $status === 'not_supplied' ? self::FAILURE : self::SUCCESS);
        }

        $record = $store->recordFromTriage($domain, $triage);

        return $this->emit([
            'schema_version' => WeeklyRedCountSnapshotStore::SCHEMA_VERSION,
            'status' => (string) ($record['status'] ?? 'unknown'),
            'recorded' => (bool) ($record['recorded'] ?? false),
            'domain' => $domain,
            'report_path' => $reportPath,
            'snapshot' => $record,
            'red_lot_triage' => $triage['red_lot_triage'] ?? null,
            'real_weekly_red_trend' => $store->trend($domain),
            'rules' => [
                'measures_only_never_corrects_or_quarantines' => true,
                'idempotent_per_iso_week' => true,
                'gate_trend_source_is_real_persisted_snapshots' => true,
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, int $exit = self::SUCCESS): int
    {
        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return $exit;
        }

        $this->components->twoColumnDetail('Suite red snapshot', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Recorded', YesNo::format($payload['recorded'] ?? false));

        return $exit;
    }

}
