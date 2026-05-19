<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Vox\Dogfood\VoxDogfoodSummaryService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Vox V6 · dogfood summary command (PT-BR, humano).
 *
 * Pergunta única: "como tá meu uso real do Atlas Vox?"
 *
 * Devolve o envelope canônico `atlas.vox.dogfood_summary.v1` produzido pelo
 * {@see VoxDogfoodSummaryService}. READ-ONLY: não grava no ledger, não
 * promove V7, não chama API paga, não toca Voice Realtime.
 *
 * Modos:
 *   - default        → texto humano PT-BR em uma frase por linha. Exit 0.
 *   - --json         → envelope JSON canônico em stdout. Exit 0.
 *
 * Uso:
 *   php artisan atlas:vox:dogfood-summary
 *   php artisan atlas:vox:dogfood-summary --json
 */
final class AtlasVoxDogfoodSummaryCommand extends Command
{
    protected $signature = 'atlas:vox:dogfood-summary
        {--json : Print machine-readable JSON envelope}';

    protected $description = 'Resumo PT-BR do uso real do Atlas Vox (dogfood + métricas). READ-ONLY.';

    public function handle(VoxDogfoodSummaryService $service): int
    {
        try {
            $envelope = $service->build();
        } catch (Throwable $e) {
            $this->error('atlas:vox:dogfood-summary exceção: '.$e->getMessage());

            return 2;
        }

        if ($this->option('json')) {
            $this->line(json_encode(
                $envelope,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
            ) ?: '{}');

            return 0;
        }

        // V6-OBSERVABILITY-FINAL · resposta direta no topo. Em vez de
        // "resumo do uso real" (técnico), abrimos com a pergunta que importa:
        // "dá pra usar o Atlas Vox no dia a dia?"
        $totalSessions = (int) ($envelope['numbers']['total_sessions'] ?? 0);
        $ready = (bool) ($envelope['ready_for_daily_use'] ?? false);
        $stillCollecting = $totalSessions === 0;
        $directAnswer = match (true) {
            $ready => 'Sim. Pode usar no dia a dia.',
            $stillCollecting => 'Ainda coletando uso — abra o overlay e fale algumas vezes para o Atlas começar a medir.',
            default => 'Ainda não — revise os problemas listados antes de uso pesado.',
        };

        $this->line('Atlas Vox · dá pra usar no dia a dia?');
        $this->line('');
        $this->line('  '.$directAnswer);
        $this->line('');
        $this->line('Uso real até agora:');
        foreach ($envelope['sentences_pt_br'] as $sentence) {
            $this->line('  · '.$sentence);
        }
        if (! empty($envelope['v7_blockers_pt_br'])) {
            $this->line('');
            $this->line('Memória entre dias (V7) — bloqueada por design até uso real suficiente:');
            foreach ($envelope['v7_blockers_pt_br'] as $b) {
                $this->line('  · '.$b);
            }
        }
        $this->line('');
        $this->line(sprintf(
            'Detalhes técnicos completos: rode com --json. (gerado em %s)',
            (string) $envelope['generated_at'],
        ));

        return 0;
    }
}
