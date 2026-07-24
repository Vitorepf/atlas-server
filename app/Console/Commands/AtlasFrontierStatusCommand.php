<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Cognition\AtlasFrontierWaveLadder;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * E — #20 frontier ladder status. Etiqueta os 5 sistemas frontier (SIS2-7 +
 * economia) e mostra a ativação por eventos externos reais: `active` só quando
 * a onda anterior acumulou o limiar; `aguardando_eventos` caso contrário.
 */
class AtlasFrontierStatusCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:acos:frontier-status
        {--record= : record a real external event: wave:kind[:ref] (kinds: pack_diff_merged|contradiction_prevented_error|memory_cited_by_foreign_session)}
        {--json : machine-readable output}';

    protected $description = 'ACOS #20 frontier wave ladder — etiqueta os 5 sistemas + ativação por eventos externos reais (E).';

    public function handle(AtlasFrontierWaveLadder $ladder): int
    {
        if ($rec = (string) ($this->option('record') ?? '')) {
            $parts = explode(':', $rec, 3);
            $ladder->recordExternalEvent($parts[0] ?? '', $parts[1] ?? '', $parts[2] ?? '');
            $this->info('registrado (se wave/kind válidos): '.$rec);
        }

        $status = $ladder->status();

        if ($this->option('json')) {
            $this->line($this->encode($status));

            return self::SUCCESS;
        }

        $this->line('[atlas:acos:frontier-status] limiar='.$status['event_threshold'].' eventos externos por onda');
        foreach ($status['waves'] as $w) {
            $this->line(sprintf(
                '  %-8s %-18s [%s] eventos=%d %s',
                $w['wave'],
                implode(',', $w['systems']),
                $w['activation'],
                $w['external_events'],
                $w['prior_events'] === null ? '' : '(onda anterior: '.$w['prior_events'].'/'.$w['threshold'].')',
            ));
        }

        return self::SUCCESS;
    }
}
