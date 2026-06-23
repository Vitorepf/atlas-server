<?php

namespace App\Console\Commands;

use App\Services\Ai\MarketingDomain\Content\AntiGoodhartGuard;
use App\Services\Ai\MarketingDomain\Content\ConversionAuditor;
use App\Services\Ai\MarketingDomain\Content\LearnedWeightLedger;
use Illuminate\Console\Command;

/**
 * atlas:ai:marketing:record-outcome — feeds the LearnedWeightLedger with a real campaign outcome.
 * Takes the page that was live (HTML file) + the measured CVR (and optional clicks/conversions/RPV
 * + niche + page_kind) and records the audit fingerprint frozen at that moment. Once enough
 * outcomes accumulate per niche, weights() returns calibrated weights — the flywheel turns.
 */
class AtlasAiMarketingRecordOutcomeCommand extends Command
{
    protected $signature = 'atlas:ai:marketing:record-outcome
        {page : path to the HTML/text of the page that was live}
        {--niche= : niche key (weight_loss, finance, relationship, …) — required}
        {--cvr= : measured conversion rate 0.0–1.0 — required}
        {--page-kind=bridge : bridge|vsl_page|rsa|email}
        {--clicks= : total clicks}
        {--conversions= : total conversions}
        {--rpv= : revenue per visitor (currency-agnostic)}
        {--asset= : VSL asset id this page belongs to}';

    protected $description = 'Registra o outcome de uma campanha real no ledger (alimenta o flywheel de pesos aprendidos).';

    public function handle(ConversionAuditor $auditor, AntiGoodhartGuard $guard, LearnedWeightLedger $ledger): int
    {
        $page = (string) $this->argument('page');
        $niche = (string) ($this->option('niche') ?: '');
        $cvr = $this->option('cvr');

        if ($niche === '' || $cvr === null) {
            $this->error('--niche and --cvr are required.');

            return self::FAILURE;
        }
        if (! is_file($page)) {
            $this->error("Page file not found: {$page}");

            return self::FAILURE;
        }

        $html = (string) file_get_contents($page);
        $copy = \App\Services\Ai\MarketingDomain\Content\HtmlCopyExtractor::plainText($html);

        $audit = $auditor->audit($copy, $html);
        $hollow = $guard->inspect($copy);

        $row = $ledger->record($audit, $hollow['hollowness'], [
            'niche' => $niche,
            'page_kind' => (string) $this->option('page-kind'),
            'conversion_rate' => (float) $cvr,
            'clicks' => $this->option('clicks') ? (int) $this->option('clicks') : null,
            'conversions' => $this->option('conversions') ? (int) $this->option('conversions') : null,
            'revenue_per_visitor' => $this->option('rpv') ? (float) $this->option('rpv') : null,
            'vsl_asset_id' => $this->option('asset'),
        ]);

        $this->line('');
        $this->line('  <fg=white;options=bold>OUTCOME registrado no ledger</>');
        $this->line('  Row #'.$row->id);
        $this->line('  Niche: '.$niche.'  | Kind: '.$row->page_kind.'  | CVR: '.number_format((float) $cvr * 100, 2).'%');
        $this->line('  Patterns frozen: '.count((array) $row->present_patterns).'  | Hollowness: '.$hollow['hollowness']);
        $this->line('');

        return self::SUCCESS;
    }
}
