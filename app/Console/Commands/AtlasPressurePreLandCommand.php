<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\EngineeringKernel\PressureLayerGuards;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * atlas:pressure:preland — ATLAS REDONDO SLICE 4. The PRE-LAND seam for the two weak-input
 * guards (boundary_wiring_guard + context_cartographer). It runs on the IN-PROGRESS diff
 * BEFORE the land: it reads the files about to be landed, extracts the RICH signal (the
 * symbols they wire into, via their `use ...;` imports), and grounds them — so the
 * cartographer produces a PROVEN verdict instead of the fail-open it returns at land time
 * (where only prose FQCNs in the commit message are available). Wire this as a pre-edit /
 * pre-commit hook to lift the guards' cadence off honestly-low.
 *
 * ADVISORY: it never blocks (exit is always 0 on a clean run); --record feeds the same
 * proof-gated outcome ledger the Decision Core weighs. Deterministic, reuse-first, no
 * provider calls. Default-safe: without --record it is a dry pre-land check.
 *
 * @see docs/engineering-knowledge-base/atlas-orchestrator-canon.md
 */
final class AtlasPressurePreLandCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:pressure:preland
        {--file=* : in-progress files about to be landed (their `use` imports are grounded)}
        {--declared=* : declared target rel path(s) (defaults to --file)}
        {--objective= : stated objective / commit summary}
        {--runtime=unknown : runtime whose candidate is judged (outcome ledger provider)}
        {--task-category=programming : outcome ledger task_category}
        {--repo= : repo root override}
        {--record : persist verdicts into the outcome ledger the Decision Core weighs}
        {--json : emit JSON}';

    protected $description = 'Pre-land Cognitive Pressure Layer seam: ground the in-progress diff\'s referenced symbols (rich signal) so boundary + cartographer produce PROVEN verdicts before the land; --record feeds the outcome ledger.';

    public function handle(PressureLayerGuards $guards): int
    {
        $files = $this->listOption('file');
        if ($files === []) {
            return $this->failWith('atlas:pressure:preland requires at least one --file=<rel path>');
        }

        $root = rtrim((string) ($this->option('repo') ?: (function_exists('base_path') ? base_path() : (getcwd() ?: '.'))), '/');

        $symbols = [];
        foreach ($files as $rel) {
            $abs = $root.'/'.ltrim($rel, '/');
            if (! is_file($abs)) {
                continue;
            }
            $symbols = array_merge($symbols, PressureLayerGuards::referencedSymbols((string) file_get_contents($abs)));
        }
        $symbols = array_values(array_unique($symbols));

        $declared = $this->listOption('declared');
        if ($declared === []) {
            $declared = $files;
        }

        $summary = $guards->observeInProgressDiff(
            $declared,
            $files,
            $symbols,
            (string) $this->option('objective'),
            (string) ($this->option('runtime') ?: 'unknown'),
            (string) ($this->option('task-category') ?: 'programming'),
            (bool) $this->option('record'),
        );
        $summary['referenced_symbols'] = $symbols;

        if ((bool) $this->option('json')) {
            $this->line($this->encode($summary));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('guards ran', (string) $summary['ran']);
        $this->components->twoColumnDetail('recorded to ledger', (string) $summary['recorded']);
        $this->components->twoColumnDetail('referenced symbols', (string) count($symbols));
        foreach ($summary['verdicts'] as $v) {
            $this->components->twoColumnDetail(
                (string) $v['guard'],
                ($v['pass'] ? 'pass' : 'FAIL').($v['proven_real'] ? ' · proven' : '').' · '.(string) $v['detail'],
            );
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function listOption(string $name): array
    {
        return array_values(array_filter(
            array_map(static fn ($v): string => trim((string) $v), (array) $this->option($name)),
            static fn (string $v): bool => $v !== '',
        ));
    }

    private function failWith(string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(['error' => $message], JSON_PRETTY_PRINT));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
