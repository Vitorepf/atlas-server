<?php

namespace App\Console\Commands;

use App\Services\Ai\MarketingDomain\Content\MarketSpyHarvester;
use Illuminate\Console\Command;

/**
 * atlas:ai:marketing:spy — feeds the MarketSpyHarvester. Receives a directory of HTML files (or a
 * glob) and prints the safra analysis: trend patterns (those that repeat in K+ pages), candidates
 * to harvest in the next deepening cycle, library density ranking and the averages. The operator's
 * lens on "what's dominating my niche this month".
 */
class AtlasAiMarketingSpyCommand extends Command
{
    protected $signature = 'atlas:ai:marketing:spy
        {path : directory of HTML files OR glob pattern (e.g. /tmp/winners/*.html)}
        {--threshold=0 : K — pattern must appear in K+ pages to count as trend (0 = auto, ceil(N/2))}
        {--json : machine output}';

    protected $description = 'Analisa a SAFRA (N páginas vencedoras) → trend patterns + candidatos + density por library.';

    public function handle(MarketSpyHarvester $harvester): int
    {
        $path = (string) $this->argument('path');
        $files = is_dir($path) ? (glob(rtrim($path, '/').'/*.{html,htm,txt}', GLOB_BRACE) ?: []) : (glob($path) ?: []);

        if ($files === []) {
            $this->error("No files found at {$path}");

            return self::FAILURE;
        }

        $pages = array_map(static fn (string $f): string => (string) file_get_contents($f), $files);
        $r = $harvester->harvest($pages, (int) $this->option('threshold'));

        if ($this->option('json')) {
            $this->line((string) json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('  <fg=white;options=bold>MARKET SPY — análise da safra</>');
        $this->line('  N páginas: <fg=yellow>'.$r['n_pages'].'</>  |  avg score: <fg=yellow>'.$r['avg_overall_score']
            .'</>  |  avg hollowness: <fg=cyan>'.$r['avg_hollowness'].'</>');
        $this->line('');

        $this->line('  <fg=gray>Density agregada por library (média de hits/1k palavras):</>');
        foreach ($r['library_density'] as $lib => $v) {
            $bar = str_repeat('█', max(0, min(20, (int) round($v))));
            $this->line(sprintf('    %-26s %s %6.2f', $lib, $bar, $v));
        }
        $this->line('');

        $this->line('  <fg=green;options=bold>Trend patterns (em '.($this->option('threshold') ?: 'ceil(N/2)').'+ páginas):</>');
        if ($r['trend_patterns'] === []) {
            $this->line('    <fg=gray>(nenhum padrão repetiu — safra muito diversa)</>');
        } else {
            foreach (array_slice($r['trend_patterns'], 0, 15) as $p) {
                $this->line(sprintf('    <fg=green>·</> %-45s <fg=gray>seen=%d/%d</>', $p['pattern'], $p['seen_in'], $r['n_pages']));
            }
        }
        $this->line('');

        if (! empty($r['trend_candidates'])) {
            $this->line('  <fg=magenta;options=bold>Candidatos recorrentes a aprender:</>');
            foreach ($r['trend_candidates'] as $c) {
                $this->line('    <fg=magenta>·</> seen='.$c['seen_in'].' <fg=white>'.$c['snippet'].'</>');
            }
            $this->line('');
        }

        return self::SUCCESS;
    }
}
