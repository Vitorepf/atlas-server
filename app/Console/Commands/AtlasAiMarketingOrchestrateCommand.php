<?php

namespace App\Console\Commands;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Content\ConversionOrchestrator;
use Illuminate\Console\Command;

/**
 * atlas:ai:marketing:orchestrate — the "press one button" CLI. Picks a VSL asset and runs the full
 * end-to-end pipeline (seed → amplifier → renderer), writes the final HTML to disk, and prints the
 * before/after report. This is the operator's entry point: asset id → conversion-optimized page.
 */
class AtlasAiMarketingOrchestrateCommand extends Command
{
    protected $signature = 'atlas:ai:marketing:orchestrate
        {--asset= : VSL asset id (defaults to latest)}
        {--out= : HTML output path (defaults to storage/app/marketing/orchestrated/<asset>.html)}
        {--until=strong : target grade (flat|weak|decent|strong|killer)}
        {--iterations=3 : max amplifier iterations}
        {--brand= : brand name shown in the masthead}';

    protected $description = 'Pipeline end-to-end: asset → bridge amplificada → HTML pronta pra subir.';

    public function handle(ConversionOrchestrator $orch): int
    {
        $asset = $this->option('asset')
            ? AiMarketingVslAsset::find($this->option('asset'))
            : AiMarketingVslAsset::orderByDesc('updated_at')->first();
        if (! $asset) {
            $this->error('No VSL asset found.');

            return self::FAILURE;
        }

        $out = $orch->orchestrate($asset, [
            'until' => (string) $this->option('until'),
            'max_iterations' => (int) $this->option('iterations'),
            'brand' => (string) ($this->option('brand') ?: 'The Daily Wellness Report'),
        ]);

        $outPath = (string) ($this->option('out') ?: storage_path('app/marketing/orchestrated/'.$asset->id.'.html'));
        @mkdir(dirname($outPath), 0775, true);
        file_put_contents($outPath, $out['html']);

        $this->line('');
        $this->line('  <fg=white;options=bold>ORCHESTRATOR — pipeline end-to-end</>');
        $this->line('  Asset: '.($asset->title ?: '(sem título)').'  (#'.$asset->id.')');
        $this->line('');
        $this->line('  Overall: <fg=red>'.$out['before']['overall_score'].'</> ('.$out['before']['grade'].')  →  '
            .'<fg=green;options=bold>'.$out['after']['overall_score'].'</> ('.$out['after']['grade'].')'
            .'  <fg=yellow>+'.($out['after']['overall_score'] - $out['before']['overall_score']).'</>');
        $this->line('  Padrões injetados ('.count($out['injected']).'): <fg=cyan>'.implode(', ', $out['injected']).'</>');
        $this->line('');
        $this->line('  HTML salvo: <fg=white>'.$outPath.'</>  ('.number_format(strlen($out['html'])).' bytes)');
        $this->line('');

        return self::SUCCESS;
    }
}
