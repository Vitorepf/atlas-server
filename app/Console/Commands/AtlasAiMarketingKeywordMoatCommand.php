<?php

namespace App\Console\Commands;

use App\Services\Ai\MarketingDomain\Campaign\KeywordOsRunner;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * CLI do MOAT (regra #8 da dissecação) — fabrica o re-finder de amanhã. Dado o ingrediente/benefício da
 * oferta, coina o token a plantar no advertorial + entrega a campanha que POSSUI a busca no instante do
 * plantio (exact + cone de mistype + matriz de descritor) + a chave de tracking. Provider-free, determinístico.
 */
class AtlasAiMarketingKeywordMoatCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:marketing:keyword-moat
        {--ingredient=* : ingrediente/benefício/cor coined pela VSL (ex.: gelatin, "blue salt", coffee)}
        {--category=* : categoria/órgão pra matriz de descritor (ex.: "weight loss", bariatric)}
        {--form=* : forma-fator (ex.: drops, pen, pill)}
        {--json : saída JSON}';

    protected $description = 'Atlas Keyword Moat: ingrediente → token coinável pra plantar + a campanha que pré-possui a busca fabricada.';

    public function handle(KeywordOsRunner $runner): int
    {
        $ingredients = array_values(array_filter((array) $this->option('ingredient')));
        if ($ingredients === []) {
            $this->error('Passe ao menos um --ingredient (ex.: --ingredient=gelatin).');

            return self::FAILURE;
        }

        $r = $runner->moatPlan($ingredients, [
            'categories' => array_values(array_filter((array) $this->option('category'))),
            'forms' => array_values(array_filter((array) $this->option('form'))),
        ]);

        if ($this->option('json')) {
            $this->line($this->encode($r));

            return self::SUCCESS;
        }

        $plan = (array) $r['fabrication_plan'];
        $this->info('TOKENS COINÁVEIS (ranqueados por defensibilidade — quanto mais ownable, mais barato o tráfego futuro):');
        foreach (array_slice((array) $r['candidates'], 0, 10) as $c) {
            $this->line(sprintf('  %-26s defensibilidade=%s', $c['token'] ?? '', $c['ownability'] ?? '?'));
        }

        if (! ($plan['plantable'] ?? false)) {
            return self::SUCCESS;
        }
        $own = (array) ($plan['own_now'] ?? []);
        $this->newLine();
        $this->info("PLANO DE FABRICAÇÃO — plantar '".($plan['token'] ?? '')."':");
        $this->line('  plantio: '.($plan['plant']['seed_count'] ?? 0).'× em '.implode(' / ', (array) ($plan['plant']['positions'] ?? [])));
        $this->line('  POSSE-no-instante: exact + '.count((array) ($own['mistype_cone'] ?? [])).' mistypes + '.count((array) ($own['descriptor_matrix'] ?? [])).' descritores = '.($own['count'] ?? 0).' keywords pré-possuídas');
        $this->line('  tracking: '.($plan['track']['signal'] ?? ''));
        $this->newLine();
        $this->line('<comment>amostra do cone de mistype pré-possuído:</comment> '.implode(', ', array_slice((array) ($own['mistype_cone'] ?? []), 0, 8)));

        return self::SUCCESS;
    }
}
