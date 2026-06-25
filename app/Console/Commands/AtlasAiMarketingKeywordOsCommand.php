<?php

namespace App\Console\Commands;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Campaign\KeywordOsRunner;
use Illuminate\Console\Command;
use Throwable;

/**
 * Capstone CLI do Keyword Intelligence OS — oferta → dossiê completo num passo. Monta os 3 feeds aterrados
 * em dinheiro real (flywheel per-nicho / descoberta de search-terms reais / negativos do waste real) e roda
 * o pipeline determinístico. Read-only no Blackink; se o banco estiver indisponível, cai no modo prior puro
 * (sem feeds reais) — nunca finge dado.
 */
class AtlasAiMarketingKeywordOsCommand extends Command
{
    protected $signature = 'atlas:ai:marketing:keyword-os
        {--mechanism= : mecanismo coined da VSL (ex.: "Triple Hormone Drops Protocol")}
        {--trick= : truque/protocolo coined (ex.: "at-home retatrutide protocol")}
        {--product= : nome do produto/oferta}
        {--niche= : nicho}
        {--payout=120 : payout por venda}
        {--cvr=0.012 : CVR clique->venda (prior)}
        {--budget=0 : budget diário/total — gera o portfólio de máximo lucro (alocação ótima)}
        {--no-live : não puxar feeds reais do Blackink (só o prior determinístico)}
        {--json : saída JSON}';

    protected $description = 'Atlas Keyword OS: oferta → dossiê completo (descoberta+precisão+exclusão da venda real), determinístico.';

    public function handle(KeywordOsRunner $runner): int
    {
        $asset = new AiMarketingVslAsset([
            'mechanism_name' => (string) ($this->option('mechanism') ?? ''),
            'trick' => (string) ($this->option('trick') ?? ''),
            'niche' => (string) ($this->option('niche') ?? ''),
            'offer' => ['product_name' => (string) ($this->option('product') ?? '')],
        ]);
        $econ = ['payout' => (float) $this->option('payout'), 'cvr' => (float) $this->option('cvr')];

        $budget = (float) $this->option('budget');
        $liveError = null;
        if ($this->option('no-live')) {
            $run = $runner->assemble($asset, $econ, ['budget' => $budget]);
        } else {
            try {
                $run = $runner->run($asset, $econ, 5, $budget);
            } catch (Throwable $e) {
                $liveError = $e->getMessage();
                $run = $runner->assemble($asset, $econ, ['budget' => $budget]); // fail-soft: prior puro, sem fingir dado real
            }
        }

        $recommended = (array) ($run['launch_selection']['recommended'] ?? []);
        $payload = [
            'run_hash' => $run['run_hash'] ?? null,
            'universe' => $run['universe']['count'] ?? 0,
            'discovered_real' => $run['discovered_count'] ?? 0,
            'negatives' => $run['negatives']['count'] ?? 0,
            'recommended' => array_map(fn ($r) => [
                'keyword' => $r['keyword'] ?? null,
                'score' => $r['score'] ?? null,
                'intent' => $r['intent']['tier'] ?? null,
                'outcome_weight' => $r['outcome_weight'] ?? 1.0,
            ], array_slice($recommended, 0, 15)),
            'live_feeds' => $liveError === null && ! $this->option('no-live'),
            'live_error' => $liveError,
            'total_expected_profit' => $run['revenue_ranking']['total_expected_profit'] ?? 0,
            'top_by_revenue' => array_map(fn ($r) => [
                'keyword' => $r['keyword'] ?? null,
                'expected_profit' => $r['expected_profit'] ?? 0,
                'volume' => $r['volume'] ?? 0,
                'cvr' => $r['cvr_prior'] ?? 0,
                'basis' => $r['basis'] ?? null,
            ], array_slice((array) ($run['revenue_ranking']['ranked'] ?? []), 0, 15)),
            'vital_few' => $run['revenue_ranking']['vital_few']['concentration'] ?? null,
            'sophistication' => $run['sophistication']['strategy'] ?? null,
            'sophistication_why' => $run['sophistication']['why'] ?? null,
            'budget_portfolio' => $budget > 0 ? [
                'budget' => $run['budget_portfolio']['budget'] ?? 0,
                'profit' => $run['budget_portfolio']['total_profit'] ?? 0,
                'roas' => $run['budget_portfolio']['blended_roas'] ?? 0,
                'excluded_losers' => $run['budget_portfolio']['excluded_losers'] ?? 0,
                'spend' => array_map(fn ($k) => [
                    'keyword' => $k['keyword'] ?? null,
                    'spend' => round((float) ($k['spend'] ?? 0)),
                    'profit' => round((float) ($k['captured_profit'] ?? 0)),
                    'roas' => $k['roas'] ?? 0,
                ], array_slice((array) ($run['budget_portfolio']['portfolio'] ?? []), 0, 10)),
            ] : null,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('Keyword OS — '.($payload['live_feeds'] ? 'feeds REAIS do Blackink' : 'prior determinístico (sem feeds reais)'));
        if ($liveError !== null) {
            $this->warn('Blackink indisponível, caí no prior: '.mb_substr($liveError, 0, 120));
        }
        $this->line("universo={$payload['universe']}  descoberta_real={$payload['discovered_real']}  negativas={$payload['negatives']}  run={$payload['run_hash']}");
        foreach ($payload['recommended'] as $r) {
            $this->line(sprintf('  %-40s score=%-3s %s  venda-real×%s', $r['keyword'], $r['score'], $r['intent'], $r['outcome_weight']));
        }

        $this->newLine();
        $this->info('💰 TOP KEYWORDS POR LUCRO PROJETADO (o norte — receita = volume × CVR × payout − custo):');
        $this->line('  lucro total projetado: R$'.number_format((float) $payload['total_expected_profit'], 0));
        foreach ($payload['top_by_revenue'] as $r) {
            $this->line(sprintf('  %-40s lucro=R$%-9s vol=%-6s cvr=%s%% [%s]', $r['keyword'], number_format((float) $r['expected_profit'], 0), $r['volume'], round((float) $r['cvr'] * 100, 1), $r['basis']));
        }

        if ($payload['vital_few'] !== null) {
            $this->newLine();
            $this->info('🎯 VITAL FEW (Marshall 80/20 — onde mora o dinheiro, foco obsessivo, corte a cauda):');
            $this->line('  '.$payload['vital_few']);
        }

        if ($payload['sophistication'] !== null) {
            $this->newLine();
            $this->info('🧠 SOFISTICAÇÃO DO MERCADO (Schwartz — estratégia de keyword pro nicho):');
            $this->line('  estratégia: '.$payload['sophistication']);
            $this->line('  '.mb_substr((string) $payload['sophistication_why'], 0, 160));
        }

        if ($payload['budget_portfolio'] !== null) {
            $bp = $payload['budget_portfolio'];
            $this->newLine();
            $this->info('📊 PORTFÓLIO SOB BUDGET (como gastar R$'.number_format((float) $bp['budget'], 0).' pra MÁXIMO lucro):');
            $this->line(sprintf('  lucro projetado R$%s · ROAS %s · %s losers excluídos', number_format((float) $bp['profit'], 0), $bp['roas'], $bp['excluded_losers']));
            foreach ($bp['spend'] as $k) {
                $this->line(sprintf('  %-40s gastar R$%-6s → lucro R$%-9s ROAS %s', $k['keyword'], $k['spend'], number_format((float) $k['profit'], 0), $k['roas']));
            }
        }

        return self::SUCCESS;
    }
}
