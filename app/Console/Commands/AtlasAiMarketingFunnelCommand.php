<?php

namespace App\Console\Commands;

use App\Services\Ai\MarketingDomain\Content\FunnelCongruenceAuditor;
use App\Services\Ai\MarketingDomain\Content\HtmlCopyExtractor;
use Illuminate\Console\Command;

/**
 * atlas:ai:marketing:funnel — whole-funnel structural X-ray. Pass the stages in order; reports per-hop
 * message-match congruence, promise continuity (dropped claim / price bait-and-switch) and per-stage
 * watch-through leaks, then names the weakest hop. All structural truth — facts, not quality scores.
 */
class AtlasAiMarketingFunnelCommand extends Command
{
    protected $signature = 'atlas:ai:marketing:funnel
        {--ad= : top-of-funnel copy (file path or inline text)}
        {--bridge= : bridge/advertorial copy}
        {--page= : landing/VSL page copy}
        {--checkout= : offer/checkout copy}
        {--json : machine output}';

    protected $description = 'Raio-X estrutural do funil inteiro (congruência por hop + continuidade + vazamentos).';

    public function handle(FunnelCongruenceAuditor $auditor): int
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

        $r = $auditor->audit($stages);

        if ($this->option('json')) {
            $this->line((string) json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('  <fg=white;options=bold>RAIO-X DE FUNIL — '.implode(' → ', array_keys($stages)).'</>');
        $verdictColor = $r['verdict'] === 'sound' ? '<fg=green;options=bold>sound</>' : '<fg=red;options=bold>'.$r['verdict'].'</>';
        $this->line('  Veredito estrutural: '.$verdictColor);
        $this->line('');
        $this->line('  <fg=gray>Congruência por hop:</>');
        foreach ($r['hops'] as $h) {
            $c = $h['congruence'];
            $tag = $c < 20 ? '<fg=red>' : ($c < 50 ? '<fg=yellow>' : '<fg=green>');
            $this->line(sprintf('    %s%-22s %3d%%</>', $tag, $h['from'].'→'.$h['to'], $c));
        }
        if ($r['weakest_hop'] !== null) {
            $this->line('    <fg=gray>elo mais fraco: '.$r['weakest_hop']['from'].'→'.$r['weakest_hop']['to'].' ('.$r['weakest_hop']['congruence'].'%)</>');
        }
        $this->line('');
        if ($r['defects'] === []) {
            $this->line('  <fg=green>✓ Funil estruturalmente íntegro.</>');
        } else {
            $this->line('  <fg=red;options=bold>Defeitos estruturais ('.count($r['defects']).'):</>');
            foreach ($r['defects'] as $d) {
                $this->line('    <fg=red>✗</> <fg=gray>'.$d.'</>');
            }
        }
        $this->line('');
        if (! empty($r['requires_proof'])) {
            $this->line('  <fg=yellow;options=bold>Claims que EXIGEM prova real antes de subir (linha moral):</>');
            foreach ($r['requires_proof'] as $c) {
                $this->line('    <fg=yellow>•</> <fg=gray>['.$c['stage'].'] '.$c['name'].': "'.$c['evidence'].'"</>');
            }
            $this->line('');
        }

        return self::SUCCESS;
    }
}
