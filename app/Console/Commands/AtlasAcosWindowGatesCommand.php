<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Cognition\AtlasAcosWindowGatesService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * D (Obra #18/#19) — honest live-window gate panel. Surfaces measured value +
 * each gate's own certified verdict; `aguardando_janela` where proof needs a
 * data window. Never fabricates a number (D pétrea: medir na cadência).
 */
class AtlasAcosWindowGatesCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:acos:window-gates {--json : machine-readable output}';

    protected $description = 'ACOS live-window gate panel (D3/D4/D5 + long-horizon receipts) — measured or aguardando-janela, honesto.';

    public function handle(AtlasAcosWindowGatesService $gates): int
    {
        $status = $gates->status();

        if ($this->option('json')) {
            $this->line($this->encode($status));

            return self::SUCCESS;
        }

        $this->line('[atlas:acos:window-gates] '.$status['generated_at']);
        $this->line('— dimensões vivas —');
        foreach ($status['live_dimensions'] as $d) {
            $this->line(sprintf('  %-24s %-14s value=%s target=%s', $d['gate'] ?? '?', $d['status'] ?? '?', var_export($d['value'] ?? null, true), (string) ($d['target'] ?? '-')));
        }
        $this->line('— receipts de janela —');
        foreach ($status['window_receipts'] as $r) {
            $this->line(sprintf('  %-40s %-16s (%s)', $r['gate'] ?? '?', $r['status'] ?? '?', (string) ($r['receipt_status'] ?? '')));
        }

        return self::SUCCESS;
    }
}
