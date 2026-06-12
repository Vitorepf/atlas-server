<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AtlasLoopProposal;
use App\Services\Ai\Cognition\AtlasCognitionScoreCardService;
use App\Services\Ai\RuntimeBoundary\SemanticRetrievalRuntime;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Console\Command;
use Throwable;

/**
 * L3-14 — a SÉRIE TEMPORAL do exponencial + o relatório final N×M da campanha Fable.
 *
 * Onde {@see AtlasFableDeltaCommand} dá uma fotografia única (HOJE vs Marco Zero), este
 * comando MANTÉM uma série append-only (uma linha por dia) num JSONL durável no Evidence
 * Ledger. Cada snapshot é resolvido das MESMAS fontes vivas (scorecard resolved-evidence,
 * tabela do Loop, runtime semântico, modo do capture gate) — nunca declarado.
 *
 * O --report emite a tendência primeiro-vs-último de cada métrica: o número que prova
 * (ou refuta) que a campanha compôs. Sem série, "está crescendo" é narrativa; com ela,
 * é trajetória auditável.
 */
class AtlasFableDeltaSeriesCommand extends Command
{
    protected $signature = 'atlas:fable:delta-series
        {--baseline= : Caminho do JSON do Marco Zero (default: o congelado de 12/06)}
        {--series= : Caminho do JSONL da série (default: storage evidence)}
        {--date= : Data do snapshot YYYY-MM-DD (default: hoje) — determinístico p/ teste}
        {--report : Emite o relatório final N×M (tendência primeiro-vs-último)}
        {--json : Saída JSON canônica}';

    protected $description = 'Mantém a série temporal de deltas da campanha Fable (append-only, idempotente por data) e emite o relatório final N×M de tendência por evidência resolvida.';

    public function handle(): int
    {
        $baselinePath = trim((string) $this->option('baseline'))
            ?: storage_path('app/atlas/evidence/marco-zero-fable-2026-06-11.json');
        $seriesPath = trim((string) $this->option('series'))
            ?: storage_path('app/atlas/evidence/fable-delta-series.jsonl');

        $baseline = $this->readBaseline($baselinePath);
        if ($baseline === null) {
            return $this->blocked('no_baseline', 'Marco Zero ausente ou ilegível: '.$baselinePath);
        }

        $date = $this->resolveDate();

        // 1. Snapshot de hoje, resolvido de fontes vivas.
        $snapshot = $this->snapshot($date, $baseline);

        // 2. Append idempotente por data (substitui a linha do dia, nunca duplica).
        $series = $this->appendSnapshot($seriesPath, $snapshot);
        if ($series === null) {
            return $this->blocked('series_unreadable', 'Série ilegível/não-gravável: '.$seriesPath);
        }

        // 3. Tendência primeiro-vs-último.
        $trend = $this->trend($series);

        if ((bool) $this->option('report')) {
            return $this->emitReport($snapshot, $series, $trend);
        }

        $payload = [
            'schema_version' => 'atlas.fable.delta_series.v1',
            'status' => 'ok',
            'today' => $snapshot,
            'series_length' => count($series),
            'series_path' => $seriesPath,
            'trend' => $trend,
        ];

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Data', $snapshot['date']);
        $this->components->twoColumnDetail('Série (dias)', (string) count($series));
        foreach ($snapshot['metrics'] as $name => $value) {
            $this->components->twoColumnDetail($name, $this->stringify($value));
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readBaseline(string $path): ?array
    {
        try {
            if (! is_file($path)) {
                return null;
            }
            $decoded = json_decode((string) file_get_contents($path), true);

            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function resolveDate(): string
    {
        $date = trim((string) $this->option('date'));
        if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            return $date;
        }

        return date('Y-m-d');
    }

    /**
     * Snapshot do dia — métricas resolvidas das MESMAS fontes vivas do delta one-shot.
     *
     * @param  array<string,mixed>  $baseline
     * @return array<string,mixed>
     */
    private function snapshot(string $date, array $baseline): array
    {
        $b = $baseline['baseline'] ?? [];
        $now = $this->currentState();

        $metrics = [
            'scorecard_overall' => $now['scorecard_overall'],
            'loop_proposals_merged_to_main' => $now['merged_to_main'],
            'capture_gate_mode' => (string) config('atlas.ai.capture_quality_gate.mode'),
            'semantic_recall_real' => $now['semantic_available'],
        ];

        return [
            'date' => $date,
            'recorded_at' => date('c'),
            'baseline_recorded_at' => $baseline['recorded_at'] ?? null,
            'baseline_scorecard_overall' => (float) data_get($b, 'maturity_scorecard.acos_overall', 7.86),
            'metrics' => $metrics,
            'sources' => [
                'scorecard_overall' => 'AtlasCognitionScoreCardService::build() (resolved-evidence)',
                'loop_proposals_merged_to_main' => 'atlas_loop_proposals.merged_to_main=true',
                'capture_gate_mode' => 'config(atlas.ai.capture_quality_gate.mode)',
                'semantic_recall_real' => 'SemanticRetrievalRuntime::available()',
            ],
        ];
    }

    /**
     * @return array{scorecard_overall: float, merged_to_main: float, semantic_available: bool}
     */
    private function currentState(): array
    {
        $scorecard = 0.0;
        try {
            $scorecard = (float) data_get(app(AtlasCognitionScoreCardService::class)->build(), 'score.overall_out_of_10', 0.0);
        } catch (Throwable) {
        }

        $merged = 0.0;
        try {
            if (DatabaseTableAvailability::has('atlas_loop_proposals')) {
                $merged = (float) AtlasLoopProposal::query()->where('merged_to_main', true)->count();
            }
        } catch (Throwable) {
        }

        $semantic = false;
        try {
            $semantic = app(SemanticRetrievalRuntime::class)->available();
        } catch (Throwable) {
        }

        return ['scorecard_overall' => $scorecard, 'merged_to_main' => $merged, 'semantic_available' => $semantic];
    }

    /**
     * Append-only JSONL, idempotente por data: se a data já existe, substitui a linha
     * (re-medição do mesmo dia), nunca duplica. Ordenado por data.
     *
     * @param  array<string,mixed>  $snapshot
     * @return list<array<string,mixed>>|null
     */
    private function appendSnapshot(string $path, array $snapshot): ?array
    {
        try {
            $series = $this->readSeries($path);
            if ($series === null) {
                return null;
            }

            $date = (string) ($snapshot['date'] ?? '');
            $series = array_values(array_filter(
                $series,
                static fn (array $row): bool => (string) ($row['date'] ?? '') !== $date,
            ));
            $series[] = $snapshot;

            usort($series, static fn (array $a, array $b): int => strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? '')));

            $dir = dirname($path);
            if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
                return null;
            }

            $lines = array_map(fn (array $row): string => $this->encodeLine($row), $series);
            if (@file_put_contents($path, implode("\n", $lines)."\n") === false) {
                return null;
            }

            return $series;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<array<string,mixed>>|null  null = ilegível (não confundir com vazio)
     */
    private function readSeries(string $path): ?array
    {
        if (! is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $rows = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    /**
     * Tendência primeiro-vs-último de cada métrica numérica/booleana. Resolved-evidence:
     * lê só o que a série gravou, jamais re-narra.
     *
     * @param  list<array<string,mixed>>  $series
     * @return array<string,mixed>
     */
    private function trend(array $series): array
    {
        if ($series === []) {
            return ['available' => false, 'reason' => 'empty_series'];
        }

        $first = $series[0];
        $latest = $series[count($series) - 1];
        $firstMetrics = is_array($first['metrics'] ?? null) ? $first['metrics'] : [];
        $latestMetrics = is_array($latest['metrics'] ?? null) ? $latest['metrics'] : [];

        $metrics = [];
        $keys = array_unique(array_merge(array_keys($firstMetrics), array_keys($latestMetrics)));
        foreach ($keys as $key) {
            $from = $firstMetrics[$key] ?? null;
            $to = $latestMetrics[$key] ?? null;
            $metrics[$key] = $this->trendEntry($from, $to);
        }

        return [
            'available' => true,
            'first_date' => (string) ($first['date'] ?? ''),
            'latest_date' => (string) ($latest['date'] ?? ''),
            'span_days' => count($series),
            'metrics' => $metrics,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function trendEntry(mixed $from, mixed $to): array
    {
        if (is_bool($from) || is_bool($to)) {
            return [
                'first' => $from,
                'latest' => $to,
                'direction' => $from === $to ? 'flat' : (((bool) $to) ? 'up' : 'down'),
            ];
        }

        if (is_numeric($from) && is_numeric($to)) {
            $delta = round((float) $to - (float) $from, 3);

            return [
                'first' => (float) $from,
                'latest' => (float) $to,
                'delta' => $delta,
                'direction' => $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat'),
            ];
        }

        return [
            'first' => $from,
            'latest' => $to,
            'direction' => $from === $to ? 'flat' : 'changed',
        ];
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @param  list<array<string,mixed>>  $series
     * @param  array<string,mixed>  $trend
     */
    private function emitReport(array $snapshot, array $series, array $trend): int
    {
        $report = [
            'schema_version' => 'atlas.fable.delta_series.report.v1',
            'status' => 'ok',
            'title' => 'Relatório final N×M — campanha Fable (tendência resolvida por evidência)',
            'series_length' => count($series),
            'today' => $snapshot,
            'trend' => $trend,
            'provenance' => 'resolved-evidence: fontes vivas por snapshot, jamais auto-declarado',
        ];

        if ((bool) $this->option('json')) {
            $this->line($this->encode($report));

            return self::SUCCESS;
        }

        $this->components->info($report['title']);
        if (($trend['available'] ?? false) !== true) {
            $this->components->warn('Série insuficiente para tendência: '.($trend['reason'] ?? 'unknown'));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Janela', $trend['first_date'].' → '.$trend['latest_date'].' ('.$trend['span_days'].' dias)');
        foreach ($trend['metrics'] as $name => $t) {
            $arrow = match ($t['direction'] ?? 'flat') {
                'up' => '↑',
                'down' => '↓',
                default => '→',
            };
            $line = isset($t['delta'])
                ? $this->stringify($t['first']).' → '.$this->stringify($t['latest']).' (Δ '.($t['delta'] >= 0 ? '+' : '').$t['delta'].') '.$arrow
                : $this->stringify($t['first']).' → '.$this->stringify($t['latest']).' '.$arrow;
            $this->components->twoColumnDetail($name, $line);
        }

        return self::SUCCESS;
    }

    private function blocked(string $status, string $message): int
    {
        $payload = [
            'schema_version' => 'atlas.fable.delta_series.v1',
            'status' => 'blocked',
            'reason' => $status,
            'message' => $message,
        ];

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $this->components->warn('['.$status.'] '.$message);
        }

        // Fail-safe: ausência de baseline/série é estado honesto, não crash.
        return self::SUCCESS;
    }

    private function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function encodeLine(array $row): string
    {
        return (string) json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
