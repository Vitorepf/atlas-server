<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\LearningTransfer\AtlasSelfConstructionLearningLedgerQuery;
use Illuminate\Console\Command;

/**
 * Arms the dormant orphan {@see AtlasSelfConstructionLearningLedgerQuery::all()} at the operator surface: reads
 * the self-construction learning ledger (give_back lessons, proofs) and emits the recorded entries as facts.
 *
 * Pure + read-only query: it re-reads the durable JSONL every call and reports; it mutates nothing.
 */
final class AtlasLoopLearningLedgerCommand extends Command
{
    protected $signature = 'atlas:loop:learning-ledger {--json}';

    protected $description = 'Read-only dump of the self-construction learning ledger entries.';

    public function handle(): int
    {
        $entries = app(AtlasSelfConstructionLearningLedgerQuery::class)->all();

        $facts = [
            'schema' => 'atlas.loop.learning_ledger.v1',
            'entry_count' => count($entries),
            'entries' => $entries,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('entry_count: '.$facts['entry_count']);
            foreach ($entries as $e) {
                $this->line('  '.($e['lesson']['lesson_id'] ?? $e['lesson_id'] ?? '?'));
            }
        }

        return self::SUCCESS;
    }
}
