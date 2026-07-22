<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\SelfModel\Corpus;

use Illuminate\Support\Carbon;

/**
 * CORPUS PROVENANCE LEDGER — an append-only, in-memory audit of every corpus build, so a poisoned corpus can
 * never enter the self-model silently: each build records WHICH deliveries (by id) over WHICH window produced
 * WHICH corpus_hash. Append-only + deterministic order; a build missing its corpus_hash is REJECTED and
 * appends nothing (a corpus with no provenance hash is unauditable, so it is refused).
 *
 * In-memory by construction (no disk / no DB) — the audit lives for the duration of a build session.
 */
final class AtlasLoopCorpusProvenanceLedger
{
    public const SCHEMA = 'atlas.loop.corpus_provenance.v1';

    /** @var list<array<string,mixed>> */
    private array $entries = [];

    /**
     * @param  array<string,mixed>  $build  {corpus_hash, example_count?, delivery_ids?, window_from?, window_to?}
     * @return array{status:string, corpus_hash?:string, reason?:string}
     */
    public function record(array $build): array
    {
        $corpusHash = trim((string) ($build['corpus_hash'] ?? ''));
        if ($corpusHash === '') {
            return ['status' => 'rejected', 'reason' => 'corpus_hash_missing']; // unauditable ⇒ append nothing
        }

        $this->entries[] = [
            'schema' => self::SCHEMA,
            'corpus_hash' => $corpusHash,
            'example_count' => (int) ($build['example_count'] ?? 0),
            'delivery_ids' => array_values(array_filter(
                array_map(static fn ($id): string => (string) $id, (array) ($build['delivery_ids'] ?? [])),
                static fn (string $id): bool => $id !== '',
            )),
            'window_from' => (string) ($build['window_from'] ?? ''),
            'window_to' => (string) ($build['window_to'] ?? ''),
            'recorded_at' => Carbon::now('UTC')->toIso8601String(),
        ];

        return ['status' => 'ok', 'corpus_hash' => $corpusHash];
    }

    /**
     * The recorded builds in insertion order.
     *
     * @return list<array<string,mixed>>
     */
    public function entries(): array
    {
        return $this->entries;
    }
}
