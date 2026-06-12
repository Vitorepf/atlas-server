<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopAutoMergeService;
use Illuminate\Console\Command;

/**
 * Drena propostas certificadas do Loop para MAIN (merge-livre v2, decisão do operador):
 * re-prova → git apply real → php -l → commit → canário fix-forward-first → receipt.
 * Gated por atlas.ai.loop.auto_merge_to_main (o operador ligou em 12/06; reversível).
 */
class AtlasLoopAutoMergeCommand extends Command
{
    protected $signature = 'atlas:loop:automerge
        {--repo= : Repo root (default: a raiz da app)}
        {--limit=10 : Máximo de propostas a drenar neste passe}
        {--json : Saída JSON canônica}';

    protected $description = 'Merge-livre v2: drena propostas certificadas+re-provadas do Loop para MAIN (commit real, canário, receipt; fix-forward-first).';

    public function handle(AtlasLoopAutoMergeService $merger): int
    {
        $repo = trim((string) $this->option('repo')) ?: base_path();
        $result = $merger->drain($repo, (int) $this->option('limit'));

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Auto-merge', (string) $result['status']);
        $this->components->twoColumnDetail('Merged to main', (string) $result['merged_count']);
        foreach ($result['results'] as $r) {
            $line = $r['merged']
                ? '✅ '.$r['target_path'].' → '.substr((string) $r['commit'], 0, 10)
                : '⏸  '.$r['target_path'].' ('.(string) $r['reason'].')';
            $this->line('  '.$line);
            if (is_array($r['canary'] ?? null) && ($r['canary']['ran'] ?? false)) {
                $this->line('     canário: '.(($r['canary']['passed'] ?? false) ? 'GREEN' : 'RED → fila fix-forward').' ('.(string) $r['canary']['target'].')');
            }
        }

        return self::SUCCESS;
    }
}
