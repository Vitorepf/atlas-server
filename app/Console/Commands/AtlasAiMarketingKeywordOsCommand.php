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

        $liveError = null;
        if ($this->option('no-live')) {
            $run = $runner->assemble($asset, $econ, []);
        } else {
            try {
                $run = $runner->run($asset, $econ);
            } catch (Throwable $e) {
                $liveError = $e->getMessage();
                $run = $runner->assemble($asset, $econ, []); // fail-soft: prior puro, sem fingir dado real
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

        return self::SUCCESS;
    }
}
