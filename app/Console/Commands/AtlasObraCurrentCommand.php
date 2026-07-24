<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Obra\AtlasObraStateService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * WO-17-T1 — the EXPLICIT active-obra pointer. The active obra is NEVER inferred
 * (a wrong guess poisons every resumption): the operator/Atlas names it here, and
 * only then does the resumption pack scope to it.
 *
 *   atlas:obra:current --set=obra-17     # make obra-17 the active obra
 *   atlas:obra:current                   # show the active obra + its last session
 *   atlas:obra:current --clear           # no active obra (resumption section silent)
 */
final class AtlasObraCurrentCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:obra:current
        {--set= : set the active obra id (explicit; never inferred)}
        {--clear : clear the active obra}
        {--json : machine-readable output}';

    protected $description = 'Show or set the active obra (storage/atlas/obras/current) — the explicit scope of the resumption pack.';

    public function handle(AtlasObraStateService $state): int
    {
        if ((bool) $this->option('clear')) {
            $state->clearCurrent();
            $this->emit(['active_obra' => null, 'action' => 'cleared']);

            return self::SUCCESS;
        }

        $set = trim((string) $this->option('set'));
        if ($set !== '') {
            $id = $state->setCurrent($set);
            $this->emit(['active_obra' => $id, 'action' => 'set']);

            return self::SUCCESS;
        }

        $id = $state->currentId();
        $current = $id !== null ? $state->read($id) : null;
        $sessions = (array) ($current['sessions'] ?? []);
        $last = $sessions === [] ? null : end($sessions);

        $this->emit([
            'active_obra' => $id,
            'action' => 'show',
            'phase' => $current['phase'] ?? null,
            'sessions_recorded' => count($sessions),
            'last_session' => $last === false ? null : $last,
            'head' => $current['head'] ?? null,
        ]);

        return self::SUCCESS;
    }

    /** @param array<string,mixed> $payload */
    private function emit(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return;
        }

        if (($payload['active_obra'] ?? null) === null) {
            $this->info('Nenhuma obra ativa'.(($payload['action'] ?? '') === 'cleared' ? ' (limpa).' : '.'));

            return;
        }

        $this->info('Obra ativa: '.$payload['active_obra'].(($payload['action'] ?? '') === 'set' ? ' (definida).' : ''));
        if (($payload['action'] ?? '') === 'show') {
            $this->line('  fase: '.($payload['phase'] ?? '—').'  sessões: '.($payload['sessions_recorded'] ?? 0).'  head: '.($payload['head'] ?? '—'));
        }
    }
}
