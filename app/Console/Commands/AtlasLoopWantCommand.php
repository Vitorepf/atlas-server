<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopIntentIntakeService;
use Illuminate\Console\Command;

/**
 * LOOP-OS · Fase 5 · Slice 14.5 — o operador descreve um pedido em linguagem natural e ele entra na LISTA
 * que o loop mói (manifesto de backlog-intents). "descreve um pedido → entra numa lista."
 */
class AtlasLoopWantCommand extends Command
{
    protected $signature = 'atlas:loop:want
        {want : O pedido em linguagem natural (descreva o que você quer)}
        {--priority= : Prioridade 0..1 (default 0.5)}
        {--repo= : Repo root (default: a raiz da app)}
        {--json : Saída JSON canônica}';

    protected $description = 'Registra um pedido do operador em linguagem natural como item de backlog que o loop descobre no próximo ciclo.';

    public function handle(AtlasLoopIntentIntakeService $intake): int
    {
        $repo = trim((string) $this->option('repo')) ?: base_path();
        $priority = $this->option('priority') !== null ? (float) $this->option('priority') : null;
        $result = $intake->want((string) $this->argument('want'), $repo, $priority);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $result['ok'] ? self::SUCCESS : self::FAILURE;
        }

        if (! $result['ok']) {
            $this->error('Pedido não registrado: '.(string) $result['reason']);

            return self::FAILURE;
        }
        $this->components->twoColumnDetail('Registrado', '#'.$result['total_items'].($result['resolved_path'] ? ' → '.$result['item']['path'] : ' (sem path resolvido)'));

        return self::SUCCESS;
    }
}
