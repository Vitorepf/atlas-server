<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\RealExecution\MissionDeliveryOrchestrator;
use Illuminate\Console\Command;

/**
 * Mission e2e — "Atlas delivers from natural language". One command runs the full
 * governed chain: request → certified code (provider, with the wired code-graph
 * auto-context) → diff → real branch (NEVER main; reversible). Atlas stops at the
 * branch; the OPERATOR reviews + merges.
 *
 * NOTE: the default path makes a REAL provider call (operator spend). Use it when
 * you intend to spend; the resulting branch is yours to inspect and merge or drop.
 */
class AtlasMissionDeliverCommand extends Command
{
    protected $signature = 'atlas:mission:deliver
        {request : the natural-language code request}
        {--provider=codex_cli : provider key for the generation step}
        {--target-file= : preferred target path for a single-file delivery}
        {--id= : materialization id (→ branch atlas/materialize/<id>)}
        {--measure= : optional success-metric command run on the branch}
        {--json : machine-readable output}';

    protected $description = 'Mission e2e: request → certified code → materialized branch (Atlas delivers, you merge).';

    public function handle(MissionDeliveryOrchestrator $orchestrator): int
    {
        $request = (string) $this->argument('request');

        $options = array_filter([
            'provider' => $this->stringOption('provider'),
            'target_file' => $this->stringOption('target-file'),
            'id' => $this->stringOption('id'),
            'measure_cmd' => $this->stringOption('measure'),
        ], static fn ($v): bool => $v !== null && $v !== '');

        $result = $orchestrator->deliver($request, $options);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return ($result['delivered'] ?? false) ? self::SUCCESS : self::FAILURE;
        }

        if (! ($result['delivered'] ?? false)) {
            $this->error('Not delivered — stage='.($result['stage'] ?? '?').' reason='.($result['reason'] ?? '?'));

            return self::FAILURE;
        }

        $this->info('✓ Atlas delivered a ready-to-merge branch (main untouched).');
        $this->line('  branch:   '.($result['branch'] ?? '?'));
        $this->line('  files:    '.implode(', ', (array) ($result['delivery']['files'] ?? [])));
        $this->line('  provider: '.($result['delivery']['provider'] ?? '?'));
        $this->line('');
        $this->line('  Review + merge (your sovereignty):');
        foreach ((array) ($result['review_commands'] ?? []) as $cmd) {
            $this->line('    '.$cmd);
        }

        return self::SUCCESS;
    }

    private function stringOption(string $key): ?string
    {
        $v = $this->option($key);

        return is_string($v) ? $v : null;
    }
}
