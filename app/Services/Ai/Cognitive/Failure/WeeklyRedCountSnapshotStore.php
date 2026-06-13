<?php

namespace App\Services\Ai\Cognitive\Failure;

use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * L5-3 — fonte de verdade do trend de vermelhos da suíte REAL.
 *
 * O gate L5-3 (auto-cura da suíte) só pode destravar quando o número de testes
 * vermelhos cai semana-a-semana SOBRE DADO REAL. O bug que isto fecha: a versão
 * anterior derivava o trend de um array `history` fornecido DENTRO do relatório —
 * qualquer chamador podia forjar um trend decrescente sintético e passar o gate.
 *
 * Este store grava UM snapshot por semana ISO a partir da contagem REAL de vermelhos
 * derivada das linhas do relatório de teste (não de um número arbitrário). É
 * append-only por semana e idempotente por (domain, iso_week): re-rodar na mesma
 * semana ATUALIZA o último valor medido — nunca cunha um segundo ponto "decrescente"
 * falso para a mesma semana. O trend que alimenta o gate é lido DAQUI, não do
 * relatório, então synthetic-history não passa mais.
 *
 * Read-only por construção quanto ao código/testes: este store só persiste medições;
 * jamais corrige ou quarentena testes (isso permanece operator-gated).
 */
class WeeklyRedCountSnapshotStore
{
    public const SCHEMA_VERSION = 'atlas.cognitive.suite_red_snapshot.v1';

    public const TABLE = 'atlas_suite_red_snapshots';

    /** Mínimo de semanas REAIS distintas exigido para julgar o trend. */
    public const MIN_REAL_SNAPSHOTS = 2;

    public function tableReady(): bool
    {
        return DatabaseTableAvailability::has(self::TABLE);
    }

    /**
     * Grava (ou atualiza) o snapshot REAL da semana corrente a partir de uma triagem
     * já computada sobre um relatório real. O `failed_count` é a contagem real de
     * linhas vermelhas — nunca um número fornecido à mão.
     *
     * @param  array<string,mixed>  $triage  saída de SuiteRedTriageHelper::triage (status=triaged)
     * @return array<string,mixed>
     */
    public function recordFromTriage(string $domain, array $triage, ?CarbonInterface $now = null): array
    {
        if (! $this->tableReady()) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'table_missing',
                'recorded' => false,
            ];
        }

        if (($triage['status'] ?? null) !== 'triaged') {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'not_triaged',
                'recorded' => false,
                'reason' => 'snapshot_requires_triaged_real_report',
            ];
        }

        $now = $now ? Carbon::instance($now) : Carbon::now();
        $isoWeek = $this->isoWeek($now);
        $counts = (array) ($triage['counts'] ?? []);
        $failedCount = (int) ($triage['failed_tests_seen'] ?? 0);
        $sourcePath = (string) ($triage['path'] ?? '');
        $sourceHash = $sourcePath !== '' && is_file($sourcePath)
            ? substr(hash('sha256', (string) file_get_contents($sourcePath)), 0, 64)
            : null;

        $row = [
            'domain' => $domain,
            'iso_week' => $isoWeek,
            'failed_count' => $failedCount,
            'total_tests_seen' => (int) ($triage['total_tests_seen'] ?? 0),
            'environmental_count' => (int) ($counts['environmental'] ?? 0),
            'real_failure_count' => (int) ($counts['real_failure'] ?? 0),
            'unknown_count' => (int) ($counts['unknown'] ?? 0),
            'source_report_hash' => $sourceHash,
            'source_report_path' => $sourcePath !== '' ? mb_substr($sourcePath, 0, 512) : null,
            'captured_at' => $now->toDateTimeString(),
            'updated_at' => $now->toDateTimeString(),
        ];

        $existing = DB::table(self::TABLE)
            ->where('domain', $domain)
            ->where('iso_week', $isoWeek)
            ->first();

        if ($existing !== null) {
            DB::table(self::TABLE)
                ->where('id', $existing->id)
                ->update($row);
            $action = 'updated';
        } else {
            $row['created_at'] = $now->toDateTimeString();
            DB::table(self::TABLE)->insert($row);
            $action = 'inserted';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ok',
            'recorded' => true,
            'action' => $action,
            'domain' => $domain,
            'iso_week' => $isoWeek,
            'failed_count' => $failedCount,
            'source_report_hash' => $sourceHash,
            'distinct_weeks_recorded' => $this->distinctWeekCount($domain),
        ];
    }

    /**
     * Trend computado SOBRE OS SNAPSHOTS REAIS persistidos (ordenado por semana).
     * `weekly_reds_decreasing` só é verdade quando há ≥2 semanas reais distintas E
     * a sequência é ESTRITAMENTE não-crescente com queda líquida (último < primeiro).
     *
     * @return array<string,mixed>
     */
    public function trend(string $domain, int $maxWeeks = 12): array
    {
        if (! $this->tableReady()) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'table_missing',
                'source' => 'real_persisted_snapshots',
                'weekly_reds_decreasing' => false,
                'real_snapshot_count' => 0,
                'points' => [],
            ];
        }

        $rows = DB::table(self::TABLE)
            ->where('domain', $domain)
            ->orderBy('iso_week')
            ->orderBy('captured_at')
            ->get();

        // Uma linha por semana (a unique constraint já garante isso, mas defendemos
        // o caso de leitura legada): última medição da semana vence.
        $byWeek = [];
        foreach ($rows as $r) {
            $byWeek[(string) $r->iso_week] = (int) $r->failed_count;
        }
        ksort($byWeek);
        $byWeek = array_slice($byWeek, -$maxWeeks, null, true);

        $points = [];
        foreach ($byWeek as $week => $failed) {
            $points[] = ['iso_week' => (string) $week, 'failed' => (int) $failed];
        }

        $count = count($points);
        if ($count < self::MIN_REAL_SNAPSHOTS) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'insufficient_real_history',
                'source' => 'real_persisted_snapshots',
                'min_required' => self::MIN_REAL_SNAPSHOTS,
                'real_snapshot_count' => $count,
                'weekly_reds_decreasing' => false,
                'points' => $points,
            ];
        }

        $first = $points[0]['failed'];
        $last = $points[$count - 1]['failed'];
        $monotonicNonIncreasing = true;
        for ($i = 1; $i < $count; $i++) {
            if ($points[$i]['failed'] > $points[$i - 1]['failed']) {
                $monotonicNonIncreasing = false;
                break;
            }
        }
        $decreasing = $monotonicNonIncreasing && $last < $first;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $decreasing ? 'decreasing' : ($last > $first ? 'increasing' : 'flat'),
            'source' => 'real_persisted_snapshots',
            'real_snapshot_count' => $count,
            'min_required' => self::MIN_REAL_SNAPSHOTS,
            'monotonic_non_increasing' => $monotonicNonIncreasing,
            'weekly_reds_decreasing' => $decreasing,
            'first_failed' => $first,
            'last_failed' => $last,
            'delta' => $last - $first,
            'points' => $points,
        ];
    }

    private function distinctWeekCount(string $domain): int
    {
        if (! $this->tableReady()) {
            return 0;
        }

        return (int) DB::table(self::TABLE)
            ->where('domain', $domain)
            ->distinct()
            ->count('iso_week');
    }

    private function isoWeek(CarbonInterface $now): string
    {
        return sprintf('%d-W%02d', $now->isoWeekYear, $now->isoWeek);
    }
}
