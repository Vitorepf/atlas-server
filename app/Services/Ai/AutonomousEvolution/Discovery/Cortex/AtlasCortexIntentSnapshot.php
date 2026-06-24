<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * CORTEX INTENT SNAPSHOT — extends the scope-comprehension inventory by attaching a {@see TriangulatedIntentFact}
 * to every item, persisted as ONE JSON document (storage/atlas/cortex/intent-snapshot.json) with sorted keys
 * and NO per-item timestamp — only a single top-level generated_at — so byte-identical inputs yield
 * byte-identical per-item payloads. Exposes a per-fqcn lookup and a fact-only summary.
 */
final class AtlasCortexIntentSnapshot
{
    public const SCHEMA = 'atlas.cortex.intent_snapshot.v1';

    /** @var array<string, TriangulatedIntentFact> */
    private array $factsByFqcn = [];

    public function __construct(private readonly ?string $snapshotPath = null)
    {
    }

    /**
     * @param  list<array{fqcn?:string}>  $items
     * @param  callable(array<string,mixed>):TriangulatedIntentFact  $resolveIntent
     * @return array<string,mixed>
     */
    public function build(array $items, callable $resolveIntent, ?string $generatedAt = null): array
    {
        $this->factsByFqcn = [];
        $perItem = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $fqcn = (string) ($item['fqcn'] ?? '');
            if ($fqcn === '') {
                continue;
            }
            $fact = $resolveIntent($item);
            $this->factsByFqcn[$fqcn] = $fact;
            $perItem[$fqcn] = $fact->toArray(); // per-item payload carries NO timestamp
        }
        ksort($perItem); // sorted keys ⇒ deterministic

        $document = [
            'schema' => self::SCHEMA,
            'generated_at' => $generatedAt ?? Carbon::now('UTC')->toIso8601String(), // the ONLY timestamp
            'items' => $perItem,
            'summary' => $this->summary(),
        ];

        $this->persist($document);

        return $document;
    }

    public function getIntentFor(string $fqcn): ?TriangulatedIntentFact
    {
        return $this->factsByFqcn[$fqcn] ?? null;
    }

    /**
     * @return array{items_total:int, items_with_purpose:int, items_with_conflicts:int}
     */
    public function summary(): array
    {
        $withPurpose = 0;
        $withConflicts = 0;
        foreach ($this->factsByFqcn as $fact) {
            if ($fact->purposeStatement !== null) {
                $withPurpose++;
            }
            if ($fact->conflicts !== []) {
                $withConflicts++;
            }
        }

        return [
            'items_total' => count($this->factsByFqcn),
            'items_with_purpose' => $withPurpose,
            'items_with_conflicts' => $withConflicts,
        ];
    }

    /**
     * @param  array<string,mixed>  $document
     */
    private function persist(array $document): void
    {
        $path = $this->snapshotPath ?? storage_path('atlas/cortex/intent-snapshot.json');
        try {
            $dir = dirname($path);
            if (! is_dir($dir) && ! @mkdir($dir, 0o775, true) && ! is_dir($dir)) {
                return;
            }
            @file_put_contents($path, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
        } catch (Throwable) {
            // snapshot persistence is best-effort; the in-memory lookup still serves.
        }
    }
}
