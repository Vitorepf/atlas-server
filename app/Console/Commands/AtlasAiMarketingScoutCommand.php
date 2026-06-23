<?php

namespace App\Console\Commands;

use App\Services\Ai\MarketingDomain\Content\WinningPatternScout;
use Illuminate\Console\Command;

/**
 * atlas:ai:marketing:scout — scouts a winning page from the wild. Takes an HTML/text file (or inline
 * text) and prints what the OS sees: multi-dimensional audit + hollowness verdict + signal density
 * per library + candidate patterns the deepening loop should harvest. The operator's lens on
 * competitor / winning swipe files.
 */
class AtlasAiMarketingScoutCommand extends Command
{
    protected $signature = 'atlas:ai:marketing:scout
        {target : path to an HTML/text file OR raw inline text}
        {--json : machine output}';

    protected $description = 'Escaneia uma página vencedora real → fingerprint, signal density e candidatos a aprender.';

    public function handle(WinningPatternScout $scout): int
    {
        $raw = (string) $this->argument('target');
        $html = is_file($raw) ? (string) file_get_contents($raw) : $raw;

        $r = $scout->scout($html);

        if ($this->option('json')) {
            $this->line((string) json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('  <fg=white;options=bold>SCOUT — fingerprint da página vencedora</>');
        $this->line('  Overall: <fg=yellow;options=bold>'.$r['audit']['overall_score'].'/100</> ('.$r['audit']['grade'].')'
            .'  |  Hollowness: <fg=cyan>'.$r['hollowness']['hollowness'].'</> ('.$r['hollowness']['grade'].')'
            .'  |  '.count($r['fingerprint']).' padrões presentes');
        $this->line('');

        $this->line('  <fg=gray>Signal density (hits / 1000 palavras):</>');
        $density = $r['signal_density'];
        arsort($density);
        foreach ($density as $lib => $v) {
            $bar = str_repeat('█', max(0, (int) round($v))).str_repeat('░', max(0, 20 - (int) round($v)));
            $this->line(sprintf('    %-26s %s %5.2f', $lib, $bar, $v));
        }
        $this->line('');

        if (! empty($r['hollowness']['flags'])) {
            $this->line('  <fg=red>Bandeiras de hollow:</>');
            foreach ($r['hollowness']['flags'] as $f) {
                $this->line('    <fg=red>•</> '.$f['name'].' — <fg=gray>'.$f['detail'].'</>');
            }
            $this->line('');
        }

        $this->line('  <fg=magenta;options=bold>Candidatos a aprender:</>');
        if (empty($r['candidates'])) {
            $this->line('    <fg=gray>(nenhum candidato novo — esta página já está bem mapeada pelas libs)</>');
        } else {
            foreach ($r['candidates'] as $c) {
                $this->line('    <fg=magenta>·</> <fg=white>'.mb_strimwidth($c['snippet'], 0, 70, '…').'</>');
                $this->line('      <fg=gray>'.$c['why'].'</>');
            }
        }
        $this->line('');

        return self::SUCCESS;
    }
}
