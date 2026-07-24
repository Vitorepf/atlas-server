<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Programming\AtlasDev\Regression\CallerTestSelectionService;
use App\Services\Ai\Programming\ProgrammingTestImpactAnalyzer;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * P2 (Obra #19, Frente P) — `atlas:test:impacted <paths...>`: the changed paths →
 * the tests that cover them + a ready phpunit command, so a slice proves in
 * seconds instead of running the whole suite.
 *
 * WIRING (the gap this closes): {@see ProgrammingTestImpactAnalyzer} received an
 * INJECTED code-graph but nothing fed it real edges. This command resolves the
 * REAL `test_targets`/caller edges from the world model via
 * {@see CallerTestSelectionService::resolveCodeGraph} (the read-model consumer of
 * the CodeGraph) and hands them to the analyzer.
 *
 * PÉTREA (honest): the analyzer's SELECTION recall is golden-set proven ≥0.85 by
 * `atlas:programming:test-impact-benchmark` (currently 1.0). This command is
 * ADVISORY — it recommends impacted tests with a DECLARED broader-suite fallback
 * (`selection_reason` + `minimum_policy` when no edge is found, or
 * `edge_source=ci_tables_absent_conventional_fallback`). It never SILENTLY skips
 * code, so it can be trusted before real-edge recall is separately benchmarked.
 */
class AtlasTestImpactedCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:test:impacted
        {paths* : changed file paths}
        {--risk=medium : critical|high|medium|low}
        {--workspace= : workspace root (default: base_path)}
        {--json : machine-readable output}';

    protected $description = 'P2 · changed paths → impacted tests + phpunit command (real world-model edges, declared fallback) (Obra #19).';

    public function handle(CallerTestSelectionService $selector, ProgrammingTestImpactAnalyzer $analyzer): int
    {
        $paths = array_values(array_filter(array_map('trim', (array) $this->argument('paths')), static fn ($p) => $p !== ''));
        $risk = (string) $this->option('risk') ?: 'medium';
        $workspace = (string) ($this->option('workspace') ?: base_path());

        // REAL edges from the world model (fail-safe: absent tables => empty related_tests).
        $codeGraph = $selector->resolveCodeGraph($paths, $workspace);
        $receipt = $analyzer->analyze($paths, $codeGraph, $risk);

        $receipt['edge_source'] = $codeGraph['source'] ?? 'unknown';
        $receipt['related_from_edges'] = count((array) ($codeGraph['related_tests'] ?? []));
        // The declared fallback the pétrea requires: when no edge covers the change,
        // the honest command is the broader module/suite, never a silent skip.
        $receipt['fallback_declared'] = $receipt['requires_no_test_reason']
            ? 'no known coverage — run the module/broader suite (never skip): '.($risk === 'critical' || $risk === 'high' ? 'php artisan test' : 'php artisan test <module>')
            : null;
        $receipt['advisory'] = true;

        if ($this->option('json')) {
            $this->line($this->encode($receipt));

            return self::SUCCESS;
        }

        $this->line(sprintf('impacted: %d test(s) — edges=%s, source=%s', count((array) $receipt['selected_existing_tests']), $receipt['related_from_edges'], $receipt['edge_source']));
        foreach ((array) $receipt['recommended_commands'] as $cmd) {
            $this->line('  '.$cmd);
        }
        if ($receipt['fallback_declared'] !== null) {
            $this->warn('  fallback: '.$receipt['fallback_declared']);
        }

        return self::SUCCESS;
    }
}
