<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\AtlasCortexDecisionHistoryReader;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\AtlasCortexIntentExtractor;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\AtlasCortexIntentSnapshot;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\AtlasCortexIntentStaleness;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\AtlasCortexIntentTriangulator;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\TriangulatedIntentFact;
use Illuminate\Console\Command;

final class AtlasLoopCortexIntentCommand extends Command
{
    protected $signature = 'atlas:loop:cortex:intent {action : inspect|refresh|diff|history|extract|triangulate} {--fqcn=} {--file=} {--json}';

    protected $description = 'Inspect, refresh, diff, extract, or history the intent-aware Cortex snapshot.';

    public function handle(): int
    {
        $action = trim((string) $this->argument('action'));

        return match ($action) {
            'inspect' => $this->inspect(),
            'refresh' => $this->refresh(),
            'diff' => $this->diff(),
            'history' => $this->history(),
            'extract' => $this->extract(),
            'triangulate' => $this->triangulate(),
            default => $this->usageError($action),
        };
    }

    /**
     * Wires AtlasCortexDecisionHistoryReader into the operator-facing intent CLI so
     * `atlas:loop:cortex:intent history --file=path/to/Foo.php` produces a deterministic
     * DecisionHistoryFact for the file (commit_count + decisions list) — the same shape the
     * Cortex compounding consumer reads downstream.
     */
    private function history(): int
    {
        $filePath = trim((string) $this->option('file'));
        if ($filePath === '') {
            $this->line('usage_error: history requires --file=<path>');

            return self::FAILURE;
        }
        $reader = app(AtlasCortexDecisionHistoryReader::class);
        $fact = $reader->read($filePath);
        $this->emit([
            'fqcn' => $fact->fqcn,
            'file_path' => $fact->filePath,
            'commit_count' => $fact->commitCount,
            'decisions' => $fact->decisions,
        ]);

        return self::SUCCESS;
    }

    /**
     * Wires AtlasCortexIntentExtractor into the operator-facing intent CLI so
     * `atlas:loop:cortex:intent extract --file=path/to/Foo.php` emits the REAL
     * IntentExtractionFact (docblock_purpose/design, doc_path/excerpt, confidence)
     * for the file — the same shape the snapshot builder reads downstream.
     */
    private function extract(): int
    {
        $filePath = trim((string) $this->option('file'));
        if ($filePath === '') {
            $this->line('usage_error: extract requires --file=<path>');

            return self::FAILURE;
        }

        $fact = app(AtlasCortexIntentExtractor::class)->extract($filePath);

        $this->emit([
            'fqcn' => $fact->fqcn,
            'docblock_purpose' => $fact->docblockPurpose,
            'docblock_design' => $fact->docblockDesign,
            'doc_path' => $fact->docPath,
            'doc_excerpt' => $fact->docExcerpt,
            'confidence' => $fact->confidence,
            'source_file' => $filePath,
        ]);

        return self::SUCCESS;
    }

    /**
     * Wires the dormant AtlasCortexIntentTriangulator into the operator-facing intent CLI so
     * `atlas:loop:cortex:intent triangulate --file=path/to/Foo.php` FUSES leg 1 (the docblock
     * IntentExtractionFact) and leg 2 (the DecisionHistoryFact) into the REAL TriangulatedIntentFact —
     * the same fused shape the snapshot consumer reads — instead of the refresh path's placeholder default.
     * Siblings (leg 3) default to empty. Read-only: no snapshot/queue mutation.
     */
    private function triangulate(): int
    {
        $filePath = trim((string) $this->option('file'));
        if ($filePath === '') {
            $this->line('usage_error: triangulate requires --file=<path>');

            return self::FAILURE;
        }

        $extractorFact = app(AtlasCortexIntentExtractor::class)->extract($filePath);
        $historyFact = app(AtlasCortexDecisionHistoryReader::class)->read($filePath);
        $fused = (new AtlasCortexIntentTriangulator)->triangulate($extractorFact->fqcn, $extractorFact, $historyFact);

        $this->emit([
            'fqcn' => $fused->fqcn,
            'purpose_statement' => $fused->purposeStatement,
            'evidence' => $fused->evidence,
            'confidence_score' => $fused->confidenceScore,
            'conflicts' => $fused->conflicts,
        ]);

        return self::SUCCESS;
    }

    private function inspect(): int
    {
        $document = $this->readSnapshot();
        $fqcn = trim((string) $this->option('fqcn'));
        if ($fqcn !== '') {
            $item = (array) data_get($document, 'items.'.$fqcn, []);
            $this->emit($item !== [] ? $item : [
                'fqcn' => $fqcn,
                'purpose_statement' => null,
                'confidence_score' => 0,
                'conflicts' => [],
            ]);

            return self::SUCCESS;
        }

        $this->emit((array) ($document['summary'] ?? ['items_total' => 0, 'items_with_purpose' => 0, 'items_with_conflicts' => 0]));

        return self::SUCCESS;
    }

    private function refresh(): int
    {
        $existing = $this->readSnapshot();
        $items = $this->refreshItems($existing);
        $existingItems = (array) ($existing['items'] ?? []);
        $snapshot = new AtlasCortexIntentSnapshot($this->snapshotPath());
        $document = $snapshot->build($items, function (array $item) use ($existingItems): TriangulatedIntentFact {
            $fqcn = (string) ($item['fqcn'] ?? '');
            $current = (array) ($existingItems[$fqcn] ?? []);

            return new TriangulatedIntentFact(
                fqcn: $fqcn,
                purposeStatement: array_key_exists('purpose_statement', $current) ? $current['purpose_statement'] : 'Intent for '.class_basename($fqcn),
                evidence: is_array($current['evidence'] ?? null) ? $current['evidence'] : ['extractor' => [], 'history' => [], 'siblings' => []],
                confidenceScore: max(0, min(100, (int) ($current['confidence_score'] ?? 50))),
                conflicts: array_values((array) ($current['conflicts'] ?? [])),
            );
        });

        $summary = (array) ($document['summary'] ?? []);
        $line = 'items_total='.(int) ($summary['items_total'] ?? 0);
        $this->option('json') ? $this->line($this->json($summary)) : $this->line($line);

        return self::SUCCESS;
    }

    private function diff(): int
    {
        $report = new AtlasCortexIntentStaleness(
            commitCountFor: fn (string $path): int => (int) ($this->diffCounts()[$path] ?? 0),
            headShaProbe: fn (): string => (string) config('atlas.cortex.intent_head_sha', 'HEAD'),
            changedInRange: fn (string $last, string $head, string $path): bool => (bool) ($this->diffChanged()[$path] ?? false),
        )->detect($this->diffItems($this->readSnapshot()));
        $payload = $report->toArray();
        $this->emit($payload);

        return $report->staleItems === [] ? self::SUCCESS : self::FAILURE;
    }

    private function usageError(string $action): int
    {
        $this->line("usage_error: invalid action [{$action}], expected inspect|refresh|diff|history|extract|triangulate");

        return 2;
    }

    /**
     * @return array<string,mixed>
     */
    private function readSnapshot(): array
    {
        $path = $this->snapshotPath();
        if (! is_file($path)) {
            return ['schema' => AtlasCortexIntentSnapshot::SCHEMA, 'items' => [], 'summary' => ['items_total' => 0, 'items_with_purpose' => 0, 'items_with_conflicts' => 0]];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : ['items' => [], 'summary' => []];
    }

    /**
     * @param  array<string,mixed>  $existing
     * @return list<array{fqcn:string}>
     */
    private function refreshItems(array $existing): array
    {
        $configured = (array) config('atlas.cortex.intent_items', []);
        if ($configured !== []) {
            return array_values(array_map(
                static fn (mixed $item): array => ['fqcn' => is_array($item) ? (string) ($item['fqcn'] ?? '') : (string) $item],
                $configured,
            ));
        }
        $keys = array_keys((array) ($existing['items'] ?? []));

        return $keys !== [] ? array_map(static fn (string $fqcn): array => ['fqcn' => $fqcn], $keys) : [['fqcn' => self::class]];
    }

    /**
     * @param  array<string,mixed>  $document
     * @return list<array<string,mixed>>
     */
    private function diffItems(array $document): array
    {
        $configured = (array) config('atlas.cortex.intent_staleness_items', []);
        if ($configured !== []) {
            return array_values(array_filter($configured, 'is_array'));
        }

        return array_map(
            static fn (string $fqcn): array => ['fqcn' => $fqcn, 'path' => '', 'last_extract_commit_count' => 0, 'last_extract_head_sha' => ''],
            array_keys((array) ($document['items'] ?? [])),
        );
    }

    /**
     * @return array<string,int>
     */
    private function diffCounts(): array
    {
        return array_map('intval', (array) config('atlas.cortex.intent_commit_counts', []));
    }

    /**
     * @return array<string,bool>
     */
    private function diffChanged(): array
    {
        return array_map('boolval', (array) config('atlas.cortex.intent_changed_paths', []));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ($this->option('json')) {
            $this->line($this->json($payload));

            return;
        }

        $this->line($this->json($payload));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function json(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function snapshotPath(): string
    {
        return (string) config('atlas.cortex.intent_snapshot_path', storage_path('atlas/cortex/intent-snapshot.json'));
    }
}
