<?php

namespace App\Console\Commands;

use App\Services\Ai\MarketingDomain\Content\FunnelContinuityAuditor;
use App\Services\Ai\MarketingDomain\Content\HtmlCopyExtractor;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * atlas:ai:marketing:continuity — structural-truth congruence across the funnel chain. Pass the funnel
 * stages in order (ad → bridge → page → checkout); flags the broken promise (a hero number that
 * vanishes downstream) and the price bait-and-switch ("free" upstream, a price downstream). These are
 * facts, not quality judgments — the highest-leverage trust/scent check (Eixo 6).
 */
class AtlasAiMarketingContinuityCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:marketing:continuity
        {--ad= : top-of-funnel copy/headline (file path or inline text)}
        {--bridge= : bridge/advertorial copy (file or text)}
        {--page= : landing/VSL page copy (file or text)}
        {--checkout= : offer/checkout copy (file or text)}
        {--json : machine output}';

    protected $description = 'Audita a CONTINUIDADE de promessa do funil (ad→página→checkout) — quebra de scent + bait-and-switch.';

    public function handle(FunnelContinuityAuditor $auditor): int
    {
        $stages = [];
        foreach (['ad', 'bridge', 'page', 'checkout'] as $stage) {
            $raw = (string) ($this->option($stage) ?? '');
            if ($raw === '') {
                continue;
            }
            $text = is_file($raw) ? (string) file_get_contents($raw) : $raw;
            $stages[$stage] = HtmlCopyExtractor::plainText($text);
        }

        if (count($stages) < 2) {
            $this->error('Passe pelo menos 2 estágios do funil (ex.: --ad e --page).');

            return self::FAILURE;
        }

        $result = $auditor->audit($stages);

        if ($this->option('json')) {
            $this->line($this->encode($result));

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('  <fg=white;options=bold>CONTINUIDADE DE FUNIL — '.implode(' → ', array_keys($stages)).'</>');
        $this->line('  Continuidade de promessa: <fg=yellow;options=bold>'.$result['continuity'].'%</>');
        if (! empty($result['committed'])) {
            $this->line('  <fg=gray>Promessas do topo: '.implode(' · ', $result['committed']).'</>');
        }
        $this->line('');
        if (empty($result['breaks'])) {
            $this->line('  <fg=green>✓ Sem quebra de scent detectada.</>');
        } else {
            $this->line('  <fg=red;options=bold>Quebras de continuidade:</>');
            foreach ($result['breaks'] as $b) {
                $this->line('    <fg=red>✗</> <fg=white;options=bold>'.$b['name'].'</>');
                $this->line('      <fg=gray>'.$b['detail'].'</>');
            }
        }
        $this->line('');

        return self::SUCCESS;
    }
}
