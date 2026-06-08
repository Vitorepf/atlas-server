<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Verify\AtlasDocStructureAnalyzer;
use Illuminate\Console\Command;

/**
 * FROZEN per-file doc verifier — the acceptance for the documentation evolution loop.
 *
 * docs-health is global + read-model-backed, so it cannot judge a single edited file
 * inside a throwaway scenario workspace. This checks ONE markdown file, from the file
 * alone, against the dominant canonical-module structural rules docs-health enforces:
 * doc_schema present, graph_layer in the allowed set, and the 12 required sections
 * present. It prints `ATLAS_DOC_VIOLATIONS=<n>` and exits 0 iff the file is clean — so
 * the Evolution Loop can grind a doc RED→GREEN, propose-only, and reverting the edit
 * brings the violations back (the diff-earned anti-fake holds). It is a SUBSET of
 * docs-health's rules (the structural ones an LLM can fix); content quality is backstopped
 * by propose-only human review.
 */
final class AtlasDocsLintFileCommand extends Command
{
    protected $signature = 'atlas:docs:lint-file
        {--path= : Markdown file to lint (relative to CWD unless absolute)}
        {--json : Emit a JSON report too}';

    protected $description = 'Lint ONE canonical-module markdown doc against the structural rules docs-health enforces (frozen, per-file). Exit 0 iff clean.';

    public function handle(AtlasDocStructureAnalyzer $analyzer): int
    {
        $path = (string) $this->option('path');
        if ($path !== '' && ! str_starts_with($path, '/')) {
            $path = rtrim((string) getcwd(), '/').'/'.$path;
        }
        if ($path === '' || ! is_file($path)) {
            $this->line('ATLAS_DOC_VIOLATIONS=999');
            $this->error('file not found: '.$path);

            return self::FAILURE;
        }

        // Single source of truth: the shared analyzer (also used by the unified orchestrator).
        $report = $analyzer->analyzeFile($path);
        $violations = $report['violations'];
        $n = count($violations);

        $this->line('ATLAS_DOC_VIOLATIONS='.$n);
        if ((bool) $this->option('json')) {
            $this->line('ATLAS_DOC_REPORT='.json_encode(['path' => $path, 'is_module' => $report['is_module'], 'violations' => $violations], JSON_UNESCAPED_SLASHES));
        } elseif ($n > 0) {
            $this->line('VIOLATIONS='.implode(', ', $violations));
        }

        return $n === 0 ? self::SUCCESS : self::FAILURE;
    }
}
