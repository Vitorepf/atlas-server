<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopChangedSymbolCoverageCensus;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopChangedSymbolCoverageCensus::symbolExercised()} at the operator surface:
 * emits whether a changed symbol is exercised by a test corpus (named + called + an assertion present) as a
 * deterministic fact. Read-only and pure — it only pattern-matches the corpus text; no mutation, no coverage
 * driver. `--corpus` is read as a file when it points at one, otherwise used as the literal corpus.
 */
final class AtlasLoopChangedSymbolCoverageCommand extends Command
{
    protected $signature = 'atlas:loop:changed-symbol-coverage {--corpus=} {--symbol=} {--json}';

    protected $description = 'Read-only: is a changed symbol exercised (named+called+asserted) by the test corpus?';

    public function handle(AtlasLoopChangedSymbolCoverageCensus $census): int
    {
        $corpusOption = (string) $this->option('corpus');
        $symbol = trim((string) $this->option('symbol'));
        if (trim($corpusOption) === '' || $symbol === '') {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => 'corpus_and_symbol_required'], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $corpus = is_file($corpusOption) ? (string) file_get_contents($corpusOption) : $corpusOption;

        $this->line((string) json_encode([
            'schema' => 'atlas.loop.changed_symbol_coverage.v1',
            'symbol' => $symbol,
            'exercised' => $census->symbolExercised($corpus, $symbol),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
