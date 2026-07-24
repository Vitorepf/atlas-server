<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Cognition\AtlasAcosEvolutionScoreService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Obra #14 — as 3 notas da evolução do ACOS (Execução provada, Inteligência
 * entregue, Autonomia), resolvidas de evidência em runtime. Read-only.
 */
class AtlasCognitionEvolutionScoreCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:cognition:evolution-score
        {--json : Saída JSON canônica}';

    protected $description = 'As 3 notas da evolução do ACOS (execução provada / inteligência entregue / autonomia), função de evidência resolvida — nunca literais.';

    public function handle(AtlasAcosEvolutionScoreService $service): int
    {
        $report = $service->build();

        if ((bool) $this->option('json')) {
            $this->line($this->encode($report));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=cyan>Nota geral</>', (string) $report['overall_out_of_10']);
        foreach ($report['dimensions'] as $name => $dimension) {
            $this->components->twoColumnDetail(str_replace('_', ' ', $name), $dimension['score'].' / '.$dimension['max']);
            foreach ($dimension['signals'] as $signal) {
                $this->components->twoColumnDetail('  · '.$signal['signal'].' ('.$signal['points'].'/'.$signal['max'].')', (string) $signal['evidence']);
            }
        }

        return self::SUCCESS;
    }
}
