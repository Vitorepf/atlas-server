<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Cognition\Acos\AcosDeltaSeriesJsonl;
use App\Console\Concerns\EmitsCanonicalJson;
use App\Services\Ai\Cognition\AtlasCognitionScoreCardService;
use Illuminate\Console\Command;
use Throwable;

/**
 * MAXL-04 — Série v2 por-área do scorecard ACOS.
 *
 * Onde a v1 ({@see AtlasAcosDeltaSeriesCommand}) mantém o AGREGADO diário do delta N×M,
 * a v2 mantém o breakdown POR-ÁREA (Cognitive Immune / Memory Core / AUCRI / … /
 * Evidence Ledger). Escreve num arquivo SEPARADO (`acos-delta-series.v2.jsonl`); a v1
 * fica byte-idêntica.
 *
 * Regras pétreas herdadas da v1:
 * - append-only por data (idempotente: substitui a linha do mesmo dia, nunca duplica);
 * - `--date` retroativo recusado, salvo opt-in `--allow-past-date=1` (só testes / EVI-04);
 * - `recorded_at` ISO com `date == snapshot.date` (o dia da série é o dia real);
 * - provenance `resolved-evidence`: lê do scorecard vivo, jamais auto-declarado.
 *
 * O breakdown por área usa o campo `group` já publicado por
 * {@see AtlasCognitionScoreCardService::build()} e a MESMA função de pontos
 * (ready=10 / partial=6 / building=3 / blocked=0) que já governa o `overall_out_of_10`
 * da v1 — assim as áreas nunca dizem uma coisa e o agregado outra.
 */
class AtlasAcosDeltaSeriesV2Command extends Command
{
    use EmitsCanonicalJson;

    public const SCHEMA_VERSION = 'atlas.acos.delta_series.v2';

    protected $signature = 'atlas:acos:delta-series-v2
        {--series= : Caminho do JSONL v2 da série (default: storage evidence)}
        {--date= : Data do snapshot YYYY-MM-DD (default: hoje)}
        {--allow-past-date= : Passe 1 para permitir --date ≠ hoje (SÓ TESTES — EVI-04)}
        {--json : Saída JSON canônica}';

    protected $description = 'MAXL-04 — appenda a linha v2 do dia com breakdown POR-ÁREA do scorecard ACOS (arquivo separado, v1 intocada).';

    /**
     * Mirror of AtlasCognitionScoreCardService::STATUS_POINTS (private const).
     * Kept in sync by construction: the by-area score MUST use the exact same
     * ladder that the v1 aggregate uses, or the areas would drift from the
     * overall — the whole point of MAXL-04 is that "the weakest area becomes
     * visible over time" using the SAME ruler as the aggregate.
     */
    public const STATUS_POINTS = [
        AtlasCognitionScoreCardService::STATUS_READY => 10,
        AtlasCognitionScoreCardService::STATUS_PARTIAL => 6,
        AtlasCognitionScoreCardService::STATUS_BUILDING => 3,
        AtlasCognitionScoreCardService::STATUS_BLOCKED => 0,
    ];

    /** Consumer buckets — visible in v4 rollup but outside the ACOS cognitive boundary. */
    private const CONSUMER_GROUPS = [
        'self_improvement',
        'self_construction',
        'cartography',
        'programming',
        'research_domain',
    ];

    public function __construct(
        private readonly AtlasCognitionScoreCardService $scorecard,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $seriesPath = trim((string) $this->option('series'))
            ?: storage_path('app/atlas/evidence/acos-delta-series.v2.jsonl');

        try {
            $date = $this->resolveDate();
        } catch (\InvalidArgumentException $e) {
            return $this->blocked('past_date_refused', $e->getMessage());
        }

        try {
            $envelope = $this->scorecard->build();
            $supplemental = $this->scorecard->v4SupplementalRows();
        } catch (Throwable $e) {
            return $this->blocked('scorecard_unavailable', $e->getMessage());
        }

        $subsystems = is_array($envelope['subsystems'] ?? null) ? $envelope['subsystems'] : [];
        // MAXL-04: v4 supplementals (context_cache, aemor, long_horizon, open_brain, evidence…)
        // are ACOS areas even though the v1 aggregate scores them via the v4 rollup, not
        // via the SUBSYSTEMS array. We merge them into the per-area view so the ≥14 areas
        // named in the plan (Cognitive Immune / Memory Core / … / Evidence) are visible.
        $rows = array_merge($subsystems, $supplemental);
        $snapshot = $this->snapshot($date, $rows, $envelope);

        $series = $this->appendSnapshot($seriesPath, $snapshot);
        if ($series === null) {
            return $this->blocked('series_unreadable', 'Série v2 ilegível/não-gravável: '.$seriesPath);
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ok',
            'today' => $snapshot,
            'series_length' => count($series),
            'series_path' => $seriesPath,
        ];

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Data', $snapshot['date']);
        $this->components->twoColumnDetail('Série v2 (dias)', (string) count($series));
        $this->components->twoColumnDetail('Aggregate overall', (string) $snapshot['aggregate']['overall']);
        foreach ($snapshot['by_area'] as $area => $scores) {
            $this->components->twoColumnDetail(
                'area:'.$area,
                'overall='.$scores['overall'].' code='.$scores['code'].' doc='.$scores['doc'].' pipeline='.$scores['pipeline'],
            );
        }

        return self::SUCCESS;
    }

    private function resolveDate(): string
    {
        $today = date('Y-m-d');
        $date = trim((string) $this->option('date'));
        if ($date === '') {
            return $today;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new \InvalidArgumentException('atlas:acos:delta-series-v2 --date deve ser YYYY-MM-DD, recebido: '.$date);
        }
        if ($date !== $today && ! filter_var((string) $this->option('allow-past-date'), FILTER_VALIDATE_BOOLEAN)) {
            throw new \InvalidArgumentException(
                'atlas:acos:delta-series-v2 recusa --date='.$date.' (hoje='.$today.'). '
                .'Catch-up é só same-day; dia perdido fica perdido. Testes podem passar --allow-past-date=1.'
            );
        }

        return $date;
    }

    /**
     * Snapshot v2: agregado + breakdown por-área.
     *
     * O breakdown reusa o mesmo ladder de pontos do agregado da v1 — assim a
     * série por-área e o `overall_out_of_10` do scorecard vivo nunca contam
     * histórias diferentes (invariante MAXL-04).
     *
     * @param  list<array<string,mixed>>  $rows
     * @param  array<string,mixed>  $envelope
     * @return array<string,mixed>
     */
    private function snapshot(string $date, array $rows, array $envelope): array
    {
        $scoredRows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => ($row['evidence_alias_of'] ?? null) === null,
        ));

        $byGroup = [];
        foreach ($scoredRows as $row) {
            $group = (string) ($row['group'] ?? 'unknown');
            if (in_array($group, self::CONSUMER_GROUPS, true)) {
                continue;
            }
            $byGroup[$group][] = $row;
        }
        ksort($byGroup);

        $byArea = [];
        foreach ($byGroup as $group => $rows) {
            $byArea[$group] = $this->areaScore($rows);
        }

        return [
            'date' => $date,
            'recorded_at' => gmdate('c'),
            'schema_version' => self::SCHEMA_VERSION,
            'provenance' => 'resolved-evidence',
            'aggregate' => [
                'overall' => (float) data_get($envelope, 'score.overall_out_of_10', 0.0),
                'code' => (float) data_get($envelope, 'score.dimensions.code.score_out_of_10', 0.0),
                'doc' => (float) data_get($envelope, 'score.dimensions.doc.score_out_of_10', 0.0),
                'pipeline' => (float) data_get($envelope, 'score.dimensions.pipeline.score_out_of_10', 0.0),
            ],
            'by_area' => $byArea,
            'sources' => [
                'scorecard' => 'AtlasCognitionScoreCardService::build() (resolved-evidence)',
                'ladder' => 'ready=10 / partial=6 / building=3 / blocked=0 (identical to v1 aggregate)',
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array{overall: float, code: float, doc: float, pipeline: float, subsystem_count: int}
     */
    private function areaScore(array $rows): array
    {
        $dimensions = [
            'code' => 'code_status',
            'doc' => 'doc_status',
            'pipeline' => 'pipeline_status',
        ];
        $count = count($rows);
        $out = ['subsystem_count' => $count];

        $sumScored = 0.0;
        foreach ($dimensions as $key => $statusField) {
            $sum = 0;
            $max = $count * self::STATUS_POINTS[AtlasCognitionScoreCardService::STATUS_READY];
            foreach ($rows as $row) {
                $sum += self::STATUS_POINTS[$row[$statusField] ?? AtlasCognitionScoreCardService::STATUS_BLOCKED] ?? 0;
            }
            $score = $max > 0 ? round(($sum / $max) * 10, 2) : 0.0;
            $out[$key] = $score;
            $sumScored += $score;
        }

        $out['overall'] = $count > 0 ? round($sumScored / 3, 2) : 0.0;

        return [
            'overall' => $out['overall'],
            'code' => $out['code'],
            'doc' => $out['doc'],
            'pipeline' => $out['pipeline'],
            'subsystem_count' => $count,
        ];
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return list<array<string,mixed>>|null
     */
    private function appendSnapshot(string $path, array $snapshot): ?array
    {
        return AcosDeltaSeriesJsonl::appendSnapshot($path, $snapshot);
    }

    /**
     * @return list<array<string,mixed>>|null  null = ilegível (não confundir com vazio)
     */
    private function readSeries(string $path): ?array
    {
        return AcosDeltaSeriesJsonl::readSeries($path);
    }

    private function blocked(string $status, string $message): int
    {
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'blocked',
            'reason' => $status,
            'message' => $message,
        ];

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $this->components->warn('['.$status.'] '.$message);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function encodeLine(array $row): string
    {
        return AcosDeltaSeriesJsonl::encodeLine($row);
    }
}
