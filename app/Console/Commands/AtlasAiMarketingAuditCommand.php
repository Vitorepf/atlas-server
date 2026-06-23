<?php

namespace App\Console\Commands;

use App\Services\Ai\MarketingDomain\Content\ConversionAuditor;
use Illuminate\Console\Command;

/**
 * atlas:ai:marketing:audit — runs the unified Conversion-OS x-ray on any page (HTML file or raw text)
 * and prints the multi-dimensional score plus the top missing high-leverage patterns. Use this to
 * audit a competitor page, a draft, or your own funnel before going live.
 */
class AtlasAiMarketingAuditCommand extends Command
{
    protected $signature = 'atlas:ai:marketing:audit
        {target : path to an HTML/text file OR raw inline text}
        {--json : machine output}';

    protected $description = 'Audita uma página em 10 dimensões de conversão (Conversion Pattern OS).';

    public function handle(ConversionAuditor $auditor): int
    {
        $raw = (string) $this->argument('target');
        if (is_file($raw)) {
            $html = (string) file_get_contents($raw);
        } else {
            $html = $raw;
        }
        $copy = \App\Services\Ai\MarketingDomain\Content\HtmlCopyExtractor::plainText($html);

        $audit = $auditor->audit($copy, $html);

        if ($this->option('json')) {
            $this->line((string) json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('  <fg=white;options=bold>CONVERSION PATTERN OS — auditoria</>');
        $this->line('  Overall: <fg=yellow;options=bold>'.$audit['overall_score'].'/100</> ('.$this->color($audit['grade']).')');
        $this->line('');
        $this->line('  <fg=gray>Por dimensão:</>');
        foreach ($audit['by_library'] as $name => $r) {
            $bar = str_repeat('█', max(1, (int) round($r['score'] / 5))).str_repeat('░', 20 - (int) round($r['score'] / 5));
            $this->line(sprintf('    %-26s %s %3d (%s)', $name, $bar, $r['score'], $r['grade']));
        }
        $this->line('');
        if (! empty($audit['personas'])) {
            $this->line('  <fg=magenta>Como cada persona reage (audience: '.$audit['audience_score'].'%):</>');
            foreach ($audit['personas'] as $name => $p) {
                $watchBar = str_repeat('▰', (int) round($p['will_watch'] * 10));
                $closeBar = str_repeat('▱', (int) round($p['will_close'] * 10));
                $this->line(sprintf('    %-26s watch %s%s close %s%s',
                    $name, $watchBar, str_repeat('·', 10 - mb_strlen($watchBar, 'UTF-8') / 3), $closeBar, str_repeat('·', 10 - mb_strlen($closeBar, 'UTF-8') / 3)));
                $this->line('      <fg=gray>obj: '.$p['first_objection'].'</>');
            }
            $this->line('');
        }
        if (! empty($audit['smells'])) {
            $this->line('  <fg=red;options=bold>Smells de copy VSL ('.$audit['smells_count'].'):</>');
            foreach ($audit['smells'] as $s) {
                $sevColor = match ($s['severity']) {
                    'critical' => '<fg=red;options=bold>['.$s['severity'].']</>',
                    'high' => '<fg=red>['.$s['severity'].']</>',
                    'medium' => '<fg=yellow>['.$s['severity'].']</>',
                    default => '<fg=gray>['.$s['severity'].']</>',
                };
                $this->line('    '.$sevColor.' <fg=white;options=bold>'.$s['key'].'</> <fg=gray>("'.$s['evidence'].'")</>');
                $this->line('      <fg=gray>→ '.$s['fix'].'</>');
            }
            $this->line('');
        }
        $this->line('  <fg=red;options=bold>O que falta (top 10, ponderado):</>');
        foreach ($audit['top_missing'] as $m) {
            $this->line('    <fg=red>•</> <fg=white;options=bold>'.$m['name'].'</> <fg=gray>['.$m['library'].']</>');
            $this->line('      <fg=gray>'.$m['lever'].'</>');
        }
        $this->line('');

        return self::SUCCESS;
    }

    private function color(string $grade): string
    {
        return match ($grade) {
            'killer' => '<fg=green;options=bold>killer</>',
            'strong' => '<fg=green>strong</>',
            'decent' => '<fg=yellow>decent</>',
            'weak' => '<fg=red>weak</>',
            default => '<fg=red;options=bold>flat</>',
        };
    }
}
