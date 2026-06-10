<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\RealExecution\AtlasMissionService;
use Illuminate\Console\Command;

/**
 * Mission e2e — "Atlas delivers from natural language". One command runs the full
 * CLOSED LOOP via {@see AtlasMissionService}: request → AURG brain context (provider
 * -bound, flag-gated, fail-open) → certified code (provider, with the wired
 * code-graph auto-context) → real branch (NEVER main; reversible) → outcome recorded
 * back INTO the brain so the NEXT mission sees this one. Atlas stops at the branch;
 * the OPERATOR reviews + merges.
 *
 * Routing through AtlasMissionService (not the orchestrator directly) is what makes
 * the brain bridges actually run on the CLI path: it mints the stable mission id,
 * threads it down, and surfaces brain_context_used + evidence_recorded honestly.
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
        {--no-brain : bypass the AURG brain context for this run (the outcome write-back still records)}
        {--json : machine-readable output}';

    protected $description = 'Mission e2e: request → brain context → certified code → branch → outcome recorded (Atlas delivers, you merge).';

    public function handle(AtlasMissionService $mission): int
    {
        $request = (string) $this->argument('request');

        $options = array_filter([
            'provider' => $this->stringOption('provider'),
            'target_file' => $this->stringOption('target-file'),
            'id' => $this->stringOption('id'),
            'measure_cmd' => $this->stringOption('measure'),
        ], static fn ($v): bool => $v !== null && $v !== '');

        // --no-brain bypasses the brain-context bridge for THIS run only (the
        // outcome write-back still records, so the run still compounds the brain).
        if ((bool) $this->option('no-brain')) {
            $options['no_brain'] = true;
        }

        $result = $mission->run($request, $options);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return ($result['delivered'] ?? false) ? self::SUCCESS : self::FAILURE;
        }

        if (! ($result['delivered'] ?? false)) {
            $this->error('Not delivered — stage='.($result['stage'] ?? '?').' reason='.($result['reason'] ?? '?'));

            return self::FAILURE;
        }

        $this->info('✓ Atlas delivered a ready-to-merge branch (main untouched).');
        $this->line('  mission:  '.($result['mission_id'] ?? '?'));
        $this->line('  branch:   '.($result['branch'] ?? '?'));
        $this->line('  brain:    context_used='.$this->yesNo($result['brain_context_used'] ?? false)
            .'  evidence_recorded='.$this->yesNo($result['evidence_recorded'] ?? false)
            .'  temporal_recorded='.$this->yesNo($result['temporal_recorded'] ?? false));
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

    private function yesNo(mixed $v): string
    {
        return $v ? 'yes' : 'no';
    }
}
