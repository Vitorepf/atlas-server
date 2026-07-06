<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\EmitsCanonicalJson;
use App\Services\Engineering\EngineeringRefactorCensusService;
use Illuminate\Console\Command;

final class AtlasEngineeringRefactorCensusCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:engineering:refactor-census
        {path : Diretório ou arquivo .php a censar}
        {--min-cluster=4 : Mínimo de sites byte-shape idênticos para formar cluster}
        {--json : Emite o envelope canônico em JSON}';

    protected $description = 'Shape-census de métodos por hash + conta líquida honesta (órgão Refactor Intelligence, read-only)';

    public function handle(EngineeringRefactorCensusService $census): int
    {
        $report = $census->census(
            (string) $this->argument('path'),
            max(2, (int) $this->option('min-cluster')),
        );

        if ($this->option('json')) {
            $this->jsonLine($report);

            return self::SUCCESS;
        }

        $m = $report['metrics'];
        $this->info(sprintf(
            'refactor-census %s — %d arquivos, %d clusters, %d pagantes, líquido estimado %d LOC',
            $report['path'],
            $m['files_scanned'],
            $m['clusters'],
            $m['candidatos_pagantes'],
            $m['loc_liquida_estimada'],
        ));
        foreach ($report['clusters'] as $cluster) {
            $this->line(sprintf(
                '  [%s] %dx%d linhas líquido=%d métodos=%s arquivos=%d',
                $cluster['flag'],
                $cluster['sites'],
                $cluster['body_lines_per_site'],
                $cluster['loc_liquida_estimada'],
                implode(',', array_slice($cluster['methods'], 0, 3)),
                count($cluster['files']),
            ));
        }
        $this->comment($report['note']);

        return self::SUCCESS;
    }
}
