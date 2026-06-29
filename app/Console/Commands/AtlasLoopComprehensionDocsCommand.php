<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopComprehensionDocReader;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopComprehensionDocReader} at the operator surface for the first time: a
 * read-only mine of the loop-*.md comprehension docs in --docs-dir (default base_path('docs')), emitting their
 * sections (heading-chains + line numbers) and doc_stated_gaps (gap-marker bullets + gap_marker_hit) as JSON,
 * so the loop's own docs become a structured origination material feed. No queue/DB/provider/process.
 */
final class AtlasLoopComprehensionDocsCommand extends Command
{
    protected $signature = 'atlas:loop:comprehension-docs {--docs-dir=} {--json}';

    protected $description = 'Read-only doc-stated-gap miner over the loop comprehension docs (facts only).';

    public function handle(AtlasLoopComprehensionDocReader $reader): int
    {
        $docsDir = trim((string) $this->option('docs-dir'));
        if ($docsDir === '') {
            $docsDir = base_path('docs');
        }

        $facts = $reader->read($docsDir);
        $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
