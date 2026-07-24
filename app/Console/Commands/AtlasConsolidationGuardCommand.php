<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Cognition\AtlasConsolidationRerankGuard;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * T4-S7 (Obra #17) — consolidation re-ranker non-regression guard surface.
 * `--freeze` stamps the current precision@k as the baseline; without it, the
 * command returns the promote/block verdict for a nightly consolidation.
 */
class AtlasConsolidationGuardCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:acos:consolidation-guard
        {--freeze : stamp the current precision@k as the non-regression baseline}
        {--json : machine-readable output}';

    protected $description = 'T4-S7 — precision@k non-regression guard for nightly re-ranker consolidation.';

    public function handle(AtlasConsolidationRerankGuard $guard): int
    {
        $result = $this->option('freeze') ? $guard->freeze() : $guard->verdict();

        if ($this->option('json')) {
            $this->line($this->encode($result));

            return self::SUCCESS;
        }

        if ($this->option('freeze')) {
            $this->info('[consolidation-guard] '.(($result['ok'] ?? false)
                ? 'baseline congelado precision@k='.($result['precision_at_k'] ?? '?')
                : 'não congelou: '.($result['reason'] ?? '?')));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '[consolidation-guard] verdict=%s (current=%s baseline=%s) → promote=%s',
            $result['verdict'],
            var_export($result['current_precision_at_k'], true),
            var_export($result['baseline_precision_at_k'], true),
            $result['promote_allowed'] ? 'sim' : 'não',
        ));

        return self::SUCCESS;
    }
}
