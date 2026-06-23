<?php

namespace App\Console\Commands;

use App\Services\Ai\MarketingDomain\Content\LearnedWeightLedger;
use Illuminate\Console\Command;

/**
 * atlas:ai:marketing:weights — shows the learned pattern weights per niche from the ledger. Until
 * real conversion data lands, this prints a friendly "not enough outcomes yet" message; once data
 * accumulates, it shows the top-lifting patterns by CVR-lift (Bayesian-smoothed). The operator's
 * window into the flywheel.
 */
class AtlasAiMarketingWeightsCommand extends Command
{
    protected $signature = 'atlas:ai:marketing:weights
        {niche : niche key (weight_loss, finance, relationship, …)}
        {--page-kind=bridge : bridge|vsl_page|rsa|email}
        {--min-rows=30 : minimum rows required before weights are returned}
        {--top=20 : how many top patterns to show}';

    protected $description = 'Mostra os pesos APRENDIDOS por padrão dentro de um nicho (alimentação do flywheel).';

    public function handle(LearnedWeightLedger $ledger): int
    {
        $niche = (string) $this->argument('niche');
        $kind = (string) $this->option('page-kind');
        $minRows = (int) $this->option('min-rows');
        $top = (int) $this->option('top');

        $weights = $ledger->weights($niche, $kind, $minRows);

        $this->line('');
        $this->line('  <fg=white;options=bold>LEARNED WEIGHTS — '.$niche.' / '.$kind.'</>');

        if ($weights === []) {
            $this->line('  <fg=yellow>Sem dados suficientes ainda</> (mínimo: '.$minRows.' outcomes).');
            $this->line('  <fg=gray>Use atlas:ai:marketing:record-outcome pra alimentar o ledger.</>');
            $this->line('  <fg=gray>Enquanto isso, o motor usa os pesos CRAFT (meus, hand-tuned).</>');
            $this->line('');

            return self::SUCCESS;
        }

        $this->line('  <fg=green>'.count($weights).' padrões com sinal de lift aprendido</> — top '.$top.':');
        $this->line('');

        foreach (array_slice($weights, 0, $top, true) as $pattern => $w) {
            $bar = str_repeat('█', max(1, (int) round($w * 20)));
            $this->line(sprintf('    %-45s %s %.3f', $pattern, $bar, $w));
        }
        $this->line('');

        return self::SUCCESS;
    }
}
