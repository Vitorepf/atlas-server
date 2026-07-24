<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AtlasOpenBrainGuardService;
use Illuminate\Console\Command;
use App\Support\YesNo;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * AOBG N2.F2 — `atlas:aobg:guard`: the SENTINEL's PreToolUse surface.
 *
 * Given a PROPOSED edit (a target path + optional diff/new content), evaluates the
 * change against the brain BEFORE it lands and returns {decision, reasons, evidence}
 * so a Claude Code PreToolUse hook can warn (advisory, default) or — only with the
 * opt-in flag AND a highest-confidence violation — block. Built on
 * {@see AtlasOpenBrainGuardService}.
 *
 *     atlas:aobg:guard app/Services/Ai/Foo.php
 *     atlas:aobg:guard app/Services/Ai/Foo.php --diff="$(git diff)" --json
 *     atlas:aobg:guard secrets/keys.env --json   # sensitive-class warning
 *
 * SAFETY-FIRST: the DEFAULT decision is `warn` (advisory — emit reasons, never
 * block). `block` is returned ONLY when atlas.aobg.guard.block_enabled is true AND
 * the violation is highest-confidence (sensitive/secret/cyber path touch OR an exact
 * registered-decision contradiction). ANY error/timeout FAILS OPEN to `allow`.
 *
 * Read-only, local DB only, ZERO provider spend. The command ALWAYS exits 0 (the
 * decision rides in the payload, not the exit code) so a hook can never be broken by
 * a non-zero exit — the PreToolUse JSON the hook emits is what enforces, not this.
 */
class AtlasAobgGuardCommand extends Command
{
    use EmitsCanonicalJson;

    public const SCHEMA = 'atlas.aobg.guard_command.v1';

    protected $signature = 'atlas:aobg:guard
        {path : The file path the proposed edit targets (absolute or workspace-relative)}
        {--diff= : The proposed diff / new content (drives duplication + contradiction checks)}
        {--workspace= : Workspace path or id to scope the evaluation (defaults to the primary atlas-server)}
        {--engine= : The engine proposing the edit (claude_code|codex|cursor|atlas) — excluded from the N2.F4 blackboard cross-engine claim check so an engine never warns about its own claim}
        {--budget= : Char budget for the assembled warning (defaults to config atlas.aobg.guard.budget_chars)}
        {--block : Force-enable hard block for this run (else uses config atlas.aobg.guard.block_enabled)}
        {--json : Output the verdict as JSON instead of a rendered summary}';

    protected $description = 'AOBG N2.F2: the sentinel — evaluate a PROPOSED edit against the brain BEFORE it lands. Returns allow|warn|block (warn by default; block opt-in + highest-confidence only). Provider-bound, read-only, cost-free, fail-OPEN (exit 0).';

    public function handle(AtlasOpenBrainGuardService $service): int
    {
        $path = (string) ($this->argument('path') ?? '');

        $opts = [];
        $diff = $this->option('diff');
        if (is_string($diff) && trim($diff) !== '') {
            $opts['diff'] = $diff;
        }
        $workspace = $this->option('workspace');
        if (is_string($workspace) && trim($workspace) !== '') {
            $opts['workspace'] = trim($workspace);
        }
        $engine = $this->option('engine');
        if (is_string($engine) && trim($engine) !== '') {
            $opts['engine'] = trim($engine);
        }
        $budget = $this->option('budget');
        if (is_string($budget) && is_numeric(trim($budget))) {
            $opts['budget'] = (int) max(0, (int) floor((float) trim($budget)));
        }
        if ((bool) $this->option('block')) {
            $opts['block_enabled'] = true;
        }

        $verdict = $service->evaluate($path, $opts);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($verdict));

            return self::SUCCESS;
        }

        $counts = (array) ($verdict['counts'] ?? []);
        $this->info(sprintf(
            'atlas:aobg:guard  file=%s  workspace=%s  decision=%s  block-enabled=%s  reasons=%d  decisions=%d  duplicates=%d  %dms',
            (string) ($verdict['path'] ?? ''),
            (string) ($verdict['workspace'] ?? ''),
            (string) ($verdict['decision'] ?? 'allow'),
            YesNo::format($verdict['block_enabled'] ?? false),
            (int) ($counts['reasons'] ?? 0),
            (int) ($counts['decisions'] ?? 0),
            (int) ($counts['duplicates'] ?? 0),
            (int) ($verdict['elapsed_ms'] ?? 0),
        ));

        $warning = trim((string) ($verdict['warning'] ?? ''));
        if ($warning !== '') {
            $this->line('');
            $this->line($warning);
        }

        return self::SUCCESS;
    }
}
