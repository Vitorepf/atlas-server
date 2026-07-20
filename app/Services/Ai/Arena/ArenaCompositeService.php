<?php

namespace App\Services\Ai\Arena;

final class ArenaCompositeService
{
    public function __construct(private readonly ArenaMeasurementStore $store = new ArenaMeasurementStore) {}

    /** @return array<string, mixed> */
    public function composite(): array
    {
        $this->store->assertWeightsValid();

        $weights = $this->store->weights();
        $suites = $this->store->suites();
        $measurements = $this->publishable(array_values(array_filter(
            $this->store->measurements(),
            fn (array $row): bool => isset($weights[$row['suite']]) && in_array($row['suite'], $suites, true)
        )));
        $suitesMeasured = array_values(array_unique(array_column($measurements, 'suite')));

        $byEngine = [];
        foreach ($measurements as $row) {
            $byEngine[$row['engine']][] = $row;
        }

        $engines = [];
        foreach ($byEngine as $engine => $rows) {
            $latest = $this->latestBySuiteArm($rows);
            $baseline = $this->weightedComposite($latest, $weights, 'baseline');
            $withAtlas = $this->weightedComposite($latest, $weights, 'with_atlas');
            $coverageSuites = [];
            foreach ($latest as $suite => $arms) {
                if ($arms !== []) {
                    $coverageSuites[$suite] = true;
                }
            }
            $coverageWeight = array_sum(array_intersect_key($weights, $coverageSuites));
            $history = $this->history($rows);
            $composite = $withAtlas['score'] ?? $baseline['score'];
            $previous = count($history) >= 2 ? $history[count($history) - 2]['composite'] : null;

            $engines[] = [
                'engine' => (string) $engine,
                'composite' => $composite,
                'previous' => $previous,
                'delta' => $composite !== null && $previous !== null ? round($composite - $previous, 4) : null,
                'with_atlas_composite' => $withAtlas['score'],
                'without_atlas_composite' => $baseline['score'],
                'atlas_multiplier' => $this->pairedMultiplier($latest, $weights),
                'coverage' => round($coverageWeight, 4),
                'history' => $history,
            ];
        }

        usort($engines, static fn (array $a, array $b): int => ($b['composite'] ?? -1) <=> ($a['composite'] ?? -1));

        return [
            'schema_version' => 'atlas.arena.composite.v1',
            'generated_at' => now()->toIso8601String(),
            'suites_total' => count($suites),
            'suites_measured' => count($suitesMeasured),
            'weights_public' => $weights,
            'engines' => $engines,
        ];
    }

    /** @return array<string, mixed> */
    public function scoreboard(): array
    {
        $measurements = $this->publishable($this->store->measurements());
        $bySuite = [];
        foreach ($measurements as $row) {
            $bySuite[$row['suite']][] = $row;
        }

        $suites = [];
        $adapterRepos = (array) config('atlas_rivals.benchmarks.repos', []);
        foreach ($this->store->suites() as $suite) {
            $rows = $bySuite[$suite] ?? [];
            $latestByEngine = [];
            foreach ($rows as $row) {
                $latestByEngine[$row['engine']][] = $row;
            }
            $engines = [];
            foreach ($latestByEngine as $engine => $engineRows) {
                $latest = $this->latestBySuiteArm($engineRows);
                $arms = $latest[$suite] ?? [];
                $baseline = $arms['baseline'] ?? null;
                $withAtlas = $arms['with_atlas'] ?? null;
                $score = is_array($baseline) ? (float) $baseline['score'] : null;
                $previous = $this->previousScore($engineRows, $suite, 'baseline');
                // Trio coerente de UMA linha (max() cruzando braços fabricava
                // "ok 2 · falha 9 · de 9"): baseline quando existe, senão with_atlas.
                $primary = is_array($baseline) ? $baseline : (array) $withAtlas;
                $casesPassed = (int) ($primary['cases_passed'] ?? 0);
                $casesFailed = (int) ($primary['cases_failed'] ?? 0);
                $casesTotal = (int) ($primary['cases_total'] ?? 0);
                $durations = array_values(array_filter([
                    $baseline['duration_avg_ms'] ?? null,
                    $withAtlas['duration_avg_ms'] ?? null,
                ], 'is_numeric'));
                $engines[] = [
                    'engine' => (string) $engine,
                    'score' => $score,
                    'previous_score' => $previous,
                    'delta' => $score !== null && $previous !== null ? round($score - $previous, 4) : null,
                    'with_atlas_score' => is_array($withAtlas) ? (float) $withAtlas['score'] : null,
                    'without_atlas_score' => $score,
                    'atlas_multiplier' => $this->pairedMultiplier([$suite => $arms], [$suite => 1.0]),
                    'cases_passed' => $casesPassed,
                    'cases_failed' => $casesFailed,
                    'cases_total' => $casesTotal,
                    'duration_avg_ms' => $durations === [] ? null : (int) round(array_sum($durations) / count($durations)),
                    'regressed' => $score !== null && $previous !== null && $score < $previous,
                    'history' => $this->history($engineRows),
                ];
            }
            usort($engines, static fn (array $a, array $b): int => strcmp($a['engine'], $b['engine']));
            $lastRunAt = $rows === [] ? null : max(array_column($rows, 'round_at'));
            $suites[] = [
                'suite' => $suite,
                'runs_total' => count(array_unique(array_column($rows, 'run_id_public'))),
                'last_run_at' => $lastRunAt,
                'adapter_installed' => isset($adapterRepos[$suite]),
                'engines' => $engines,
            ];
        }

        return [
            'schema_version' => 'atlas.arena.scoreboard.v1',
            'generated_at' => now()->toIso8601String(),
            'suites' => $suites,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function latestBySuiteArm(array $rows): array
    {
        $latest = [];
        foreach ($rows as $row) {
            $suite = (string) $row['suite'];
            $arm = (string) $row['arm'];
            if (! isset($latest[$suite][$arm]) || strcmp((string) $row['round_at'], (string) $latest[$suite][$arm]['round_at']) > 0) {
                $latest[$suite][$arm] = $row;
            }
        }

        return $latest;
    }

    /**
     * Guarda de seleção (mesma régua do perfil de capacidades): linha cujo braço
     * teve descarte alto no setup NÃO publica nota — o que sobrou não é amostra,
     * é seleção. Provado no app em 20/07: aider with_atlas 1,0 com 81% das
     * unidades descartadas virava "+8,9" no widget "último par" — vitória falsa
     * A FAVOR do Atlas construída só de sobreviventes. Vale para os DOIS braços.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function publishable(array $rows): array
    {
        $cap = (float) config('atlas_arena.max_exclusion_rate_unmeasured', 0.5);
        // Mesmo piso do perfil: linha com N minúsculo não vira nota publicada.
        // Sem isto, um braço atlas de N=1 (1 acerto genuíno) virava "10" no
        // widget contra um bare de N=18 — par incomparável vendido como +8,9.
        $floor = max(1, (int) config('atlas_arena.min_cases_for_confidence', 10));

        return array_values(array_filter($rows, static function (array $row) use ($cap, $floor): bool {
            $total = (int) ($row['cases_total'] ?? 0);
            $excluded = (int) ($row['cases_excluded'] ?? 0);
            $denominator = $excluded + $total;
            if ($total < $floor) {
                return false;
            }

            return $denominator <= 0 || ($excluded / $denominator) < $cap;
        }));
    }

    /**
     * @param  array<string, array<string, array<string, mixed>>>  $latest
     * @param  array<string, float>  $weights
     * @return array{score:?float, denominator:float}
     */
    private function weightedComposite(array $latest, array $weights, string $arm): array
    {
        $sum = 0.0;
        $denominator = 0.0;
        foreach ($latest as $suite => $arms) {
            $row = $arms[$arm] ?? null;
            if (! is_array($row) || ! isset($weights[$suite])) {
                continue;
            }
            $sum += ((float) $row['score']) * $weights[$suite];
            $denominator += $weights[$suite];
        }

        return [
            'score' => $denominator > 0.0 ? round($sum / $denominator, 4) : null,
            'denominator' => round($denominator, 6),
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function previousScore(array $rows, string $suite, string $arm): ?float
    {
        $matching = array_values(array_filter(
            $rows,
            fn (array $row): bool => $row['suite'] === $suite && $row['arm'] === $arm
        ));
        usort($matching, static fn (array $a, array $b): int => strcmp((string) $a['round_at'], (string) $b['round_at']));
        if (count($matching) < 2) {
            return null;
        }

        return round((float) $matching[count($matching) - 2]['score'], 4);
    }

    /**
     * @param  array<string, array<string, array<string, mixed>>>  $latest
     * @param  array<string, float>  $weights
     */
    private function pairedMultiplier(array $latest, array $weights): ?float
    {
        $windowSeconds = max(0, (int) config('atlas_arena.arm_pair_window_minutes', 120)) * 60;
        $baselineSum = 0.0;
        $withAtlasSum = 0.0;
        $denominator = 0.0;

        foreach ($latest as $suite => $arms) {
            $baseline = $arms['baseline'] ?? null;
            $withAtlas = $arms['with_atlas'] ?? null;
            if (! is_array($baseline) || ! is_array($withAtlas) || ! isset($weights[$suite])) {
                continue;
            }
            $baselineAt = strtotime((string) $baseline['round_at']);
            $withAtlasAt = strtotime((string) $withAtlas['round_at']);
            if ($baselineAt === false || $withAtlasAt === false || abs($baselineAt - $withAtlasAt) > $windowSeconds) {
                continue;
            }
            $baselineSum += ((float) $baseline['score']) * $weights[$suite];
            $withAtlasSum += ((float) $withAtlas['score']) * $weights[$suite];
            $denominator += $weights[$suite];
        }

        if ($denominator <= 0.0) {
            return null;
        }
        $baselineScore = $baselineSum / $denominator;
        if ($baselineScore <= 0.0) {
            return null;
        }

        return round(($withAtlasSum / $denominator) / $baselineScore, 4);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{round_at:string, composite:float, with_atlas:?float, without_atlas:?float}>
     */
    private function history(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['round_at'], (string) $b['round_at']));

        return array_values(array_map(static function (array $row): array {
            $score = round((float) $row['score'], 4);

            return [
                'round_at' => (string) $row['round_at'],
                'composite' => $score,
                'with_atlas' => $row['arm'] === 'with_atlas' ? $score : null,
                'without_atlas' => $row['arm'] === 'baseline' ? $score : null,
            ];
        }, $rows));
    }
}
