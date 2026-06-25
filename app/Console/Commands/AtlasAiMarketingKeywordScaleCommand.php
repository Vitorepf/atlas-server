<?php

namespace App\Console\Commands;

use App\Services\Ai\MarketingDomain\Campaign\KeywordScalingDiagnostic;
use Illuminate\Console\Command;

/**
 * CLI da decisão de ESCALA ("escalar MILHÕES") — o operador digita os números reais do painel do Google Ads
 * e o OS decide AUMENTAR/segurar/recuar budget por SINAL (regra 2026, não % fixo). Tira o KeywordScalingDiagnostic
 * do limbo de órfão "armado-aguardando-live": agora é usável com input manual, sem precisar de integração live.
 */
class AtlasAiMarketingKeywordScaleCommand extends Command
{
    protected $signature = 'atlas:ai:marketing:keyword-scale
        {--roas= : ROAS atual da campanha (ex.: 4.5)}
        {--target-roas= : ROAS-alvo (ex.: 3.0)}
        {--days=0 : dias na janela de avaliação (regra 2026: ≥14 pra sinal confiável)}
        {--is-lost-budget=0 : fração de impression share perdida POR BUDGET (0..1 — headroom)}
        {--cvr-trend=flat : tendência da CVR: rising|flat|falling}
        {--cpc-inflation=0 : inflação de CPC além do ganho de CVR (0..1)}
        {--json : saída JSON}';

    protected $description = 'Decide escalar/segurar/recuar budget por SINAL (KeywordScalingDiagnostic) com os números reais do painel.';

    public function handle(KeywordScalingDiagnostic $diag): int
    {
        $r = $diag->diagnose([
            'roas' => (float) $this->option('roas'),
            'target_roas' => (float) $this->option('target-roas'),
            'days_in_window' => (int) $this->option('days'),
            'impr_share_lost_to_budget' => (float) $this->option('is-lost-budget'),
            'cvr_trend' => (string) $this->option('cvr-trend'),
            'cpc_inflation' => (float) $this->option('cpc-inflation'),
        ]);

        if ($this->option('json')) {
            $this->line((string) json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $icon = match ($r['action']) {
            'scale_aggressive' => '🚀',
            'scale_moderate' => '📈',
            'pull_back' => '🔻',
            default => '✋',
        };
        $this->info($icon.'  AÇÃO: '.strtoupper($r['action']).'  (budget ×'.$r['budget_multiplier'].')');
        if ($r['troas_action'] !== 'none') {
            $this->line('  tROAS: '.$r['troas_action']);
        }
        $this->line('  '.$r['reason']);

        return self::SUCCESS;
    }
}
