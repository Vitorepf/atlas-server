<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Obra\AtlasBlastRadiusService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * WO-17-T3 — deterministic blast radius for a change.
 *
 *   atlas:blast-radius app/Services/Ai/Foo.php app/Services/Ai/Bar.php
 *   atlas:blast-radius --measure-dir=docs/work-orders   # gate: coverage over real slices
 *
 * Reports the affected call graph + covering tests + governed decisions a change
 * touches. --measure-dir reads slice descriptors ({allowed_files:[...]}) and measures,
 * per slice, whether the radius from the FIRST changed file covers the rest actually
 * touched (the ≥85%-of-touched-files gate) — real slices, no fabrication.
 */
final class AtlasBlastRadiusCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:blast-radius
        {files?* : repo-relative files the change touches}
        {--measure-dir= : dir of *-slice-descriptor.json to measure coverage over}
        {--json : machine-readable output}';

    protected $description = 'Deterministic blast radius (affected call graph + covering tests + decisions touched) for a change, or a coverage measurement over real slices.';

    public function handle(AtlasBlastRadiusService $service): int
    {
        $measureDir = trim((string) $this->option('measure-dir'));
        if ($measureDir !== '') {
            return $this->measure($service, $measureDir);
        }

        $files = array_values(array_filter((array) $this->argument('files')));
        if ($files === []) {
            $this->warn('Passe arquivos ou --measure-dir=<dir>.');

            return self::SUCCESS;
        }

        $radius = $service->radiusFor($files);
        if ((bool) $this->option('json')) {
            $this->line($this->encode($radius));

            return self::SUCCESS;
        }

        $this->info('Raio de explosão ('.$radius['counts']['changed'].' arquivo(s) mudado(s)):');
        $this->line('  call graph afetado ('.$radius['counts']['affected'].'): '.implode(', ', array_slice($radius['affected'], 0, 8)));
        $this->line('  testes que cobrem ('.$radius['counts']['tests'].'): '.implode(', ', array_slice($radius['tests'], 0, 8)));
        $this->line('  decisões tocadas ('.$radius['counts']['decisions'].'): '.implode(' | ', array_slice($radius['decisions'], 0, 4)));

        return self::SUCCESS;
    }

    private function measure(AtlasBlastRadiusService $service, string $dir): int
    {
        $descriptors = glob(rtrim($dir, '/').'/*-slice-descriptor.json') ?: [];
        $rows = [];
        $coverages = [];
        foreach ($descriptors as $path) {
            $desc = json_decode((string) file_get_contents($path), true);
            $files = array_values(array_filter((array) ($desc['allowed_files'] ?? []), static fn ($f): bool => str_ends_with((string) $f, '.php')));
            if (count($files) < 2) {
                continue; // a coverage gate needs ≥2 touched files (entry → the rest)
            }
            $entry = $files[0];
            $rest = array_slice($files, 1);
            $radius = $service->radiusFor([$entry]);
            $predicted = array_merge($radius['affected'], $radius['tests'], $radius['changed']);
            $covered = array_values(array_filter($rest, static fn (string $f): bool => in_array($f, $predicted, true)));
            $coverage = count($rest) > 0 ? count($covered) / count($rest) : 1.0;
            $coverages[] = $coverage;
            $rows[] = [
                'slice' => basename($path),
                'entry' => basename($entry),
                'touched_rest' => count($rest),
                'covered' => count($covered),
                'coverage' => round($coverage, 2),
            ];
        }

        $avg = $coverages === [] ? 0.0 : round(array_sum($coverages) / count($coverages), 4);
        $meetsGate = $coverages !== [] && $avg >= 0.85;
        $result = ['slices_measured' => count($rows), 'avg_coverage' => $avg, 'gate_85pct' => $meetsGate, 'per_slice' => $rows];

        if ((bool) $this->option('json')) {
            $this->line($this->encode($result));

            return self::SUCCESS;
        }

        $this->table(['slice', 'entry', 'touched', 'covered', 'coverage'], $rows);
        $this->line(sprintf('cobertura média=%.2f sobre %d slices  gate≥0.85=%s', $avg, count($rows), $meetsGate ? 'SIM' : 'NÃO'));

        return self::SUCCESS;
    }
}
