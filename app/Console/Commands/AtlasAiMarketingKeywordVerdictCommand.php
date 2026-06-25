<?php

namespace App\Console\Commands;

use App\Services\Ai\MarketingDomain\Campaign\KeywordInvestmentGate;
use App\Services\Ai\MarketingDomain\Campaign\KeywordVerdictGate;
use Illuminate\Console\Command;

/**
 * CLI do veredito INVESTIMENTO-vs-GASTO (L4) — o operador digita os números reais pós-lançamento de UMA
 * keyword e o OS decide PROVEN/teste/gasto pelo AND de 3 portas: significância (InvestmentGate) × atribuição
 * (DDA protege o assistente subvalorizado) × lag (janela de maturação). Tira o KeywordVerdictGate do limbo de
 * órfão: agora é a decisão usável "essa keyword já é investimento provado, ainda é teste, ou é gasto pra cortar?".
 */
class AtlasAiMarketingKeywordVerdictCommand extends Command
{
    protected $signature = 'atlas:ai:marketing:keyword-verdict
        {--payout=120 : payout bruto por venda}
        {--refund=0.1 : fração de refund/chargeback (0..1)}
        {--cpc= : CPC observado (se vazio, usa clicks/cost)}
        {--clicks=0 : cliques observados}
        {--conversions=0 : conversões observadas}
        {--revenue=0 : receita observada}
        {--last-click-conv= : conversões por last-click (atribuição)}
        {--dda-conv= : conversões por DDA/data-driven (atribuição — protege o assistente)}
        {--days= : dias desde o lançamento (lag)}
        {--bake-days=14 : janela de maturação esperada (lag)}
        {--json : saída JSON}';

    protected $description = 'Veredito investimento-vs-gasto de uma keyword (3 portas: significância × atribuição × lag) com os números reais.';

    public function handle(KeywordInvestmentGate $invest, KeywordVerdictGate $gate): int
    {
        $econ = [
            'payout' => (float) $this->option('payout'),
            'refund' => (float) $this->option('refund'),
        ];
        if ($this->option('cpc') !== null) {
            $econ['cpc'] = (float) $this->option('cpc');
        }
        $observed = [
            'clicks' => (int) $this->option('clicks'),
            'conversions' => (int) $this->option('conversions'),
            'revenue' => (float) $this->option('revenue'),
        ];
        $significance = $invest->decide($econ, $observed);

        $attribution = [];
        if ($this->option('last-click-conv') !== null) {
            $attribution['last_click_conv'] = (float) $this->option('last-click-conv');
        }
        if ($this->option('dda-conv') !== null) {
            $attribution['dda_conv'] = (float) $this->option('dda-conv');
        }
        $lag = ['bake_days' => (int) $this->option('bake-days')];
        if ($this->option('days') !== null) {
            $lag['days_since_launch'] = (int) $this->option('days');
        }

        $r = $gate->verdict($significance, $attribution, $lag);

        if ($this->option('json')) {
            $this->line((string) json_encode(['verdict' => $r, 'significance' => $significance], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $icon = $r['proven'] ? '✅' : ($r['verdict'] === 'gasto' ? '🔻' : '🧪');
        $this->info($icon.'  VEREDITO: '.strtoupper((string) $r['verdict']).'  ['.$r['basis'].']');
        $this->line('  '.$r['reason']);
        $this->line(sprintf('  portas: significância=%s · atribuição=%s · lag=%s',
            $r['gates']['significance']['basis'], $r['gates']['attribution']['status'], $r['gates']['lag']['status']));

        return self::SUCCESS;
    }
}
