<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopLearningAppendService;
use Throwable;

/**
 * EXTERNAL BRAIN · keystone substrate — the scope-keyed SEMANTIC reflection stream (Reflexion memory).
 *
 * The brain's existing learning ledger ({@see AtlasLoopLearningAppendService})
 * is a write-only FACT log of content hashes + terminal_reason: it proves a cycle happened, but the NEXT
 * comprehension cannot LEARN from it. This stream is the missing substrate: it stores a semantic post-mortem
 * keyed by scope ("authoring an acceptance against a pétreo file is doomed — pivot to the muscle side") and
 * recalls the top-K most relevant ones to inject into the next origination, so the brain is conditioned on its
 * own prior failure semantics instead of repeating the same wrong leverage diagnosis.
 *
 * NO-SCALAR (the anti-Goodhart contract inherited from AtlasLoopLearningAppendService): a row stores ONLY raw
 * facts — scope, cycle ref, the reflection TEXT, the cycle's signal facts and result kind. It NEVER stores a
 * learning score, quality scalar or leverage rank. {@see recall()} computes a DETERMINISTIC ordering at READ
 * time from those facts (relevance + result-kind + recency); the ordering is never persisted, so there is no
 * stored number for the loop to Goodhart-game.
 *
 * Append-only NDJSON on a DEDICATED path (never the serving disk / live ledgers). Flag
 * atlas.brain.reflection_enabled default OFF ⇒ {@see record()} is a byte-identical no-op (returns null, writes
 * nothing) — exactly mirroring the AppendService's flag stance.
 */
final class AtlasBrainReflectionStream
{
    public const SCHEMA = 'atlas.brain.reflection.v1';

    /** Result kinds (facts) — mirror the loop's honest terminal classes plus a free 'note'. */
    public const KIND_SUCCESS = 'success';

    public const KIND_CLEAN_NO_OP = 'clean_no_op';

    public const KIND_BLOCKED = 'blocked';

    public const KIND_EXHAUSTED = 'exhausted';

    public const KIND_STAGNATED = 'stagnated';

    public const KIND_NOTE = 'note';

    public const KINDS = [
        self::KIND_SUCCESS,
        self::KIND_CLEAN_NO_OP,
        self::KIND_BLOCKED,
        self::KIND_EXHAUSTED,
        self::KIND_STAGNATED,
        self::KIND_NOTE,
    ];

    /**
     * Recall importance by result kind — a FIXED deterministic map (like the selector's fixed scoring), NEVER a
     * learned score and NEVER persisted. We learn most from failures (ReasoningBank: ~40% of the value is in
     * failed runs), so a blocked/exhausted/stagnated cycle surfaces before a clean success that taught little.
     */
    private const KIND_IMPORTANCE = [
        self::KIND_BLOCKED => 3,
        self::KIND_EXHAUSTED => 3,
        self::KIND_STAGNATED => 3,
        self::KIND_NOTE => 2,
        self::KIND_CLEAN_NO_OP => 1,
        self::KIND_SUCCESS => 1,
    ];

    private readonly string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? (string) config(
            'atlas.brain.reflection_root',
            storage_path('app/atlas/brain/reflection-stream.ndjson')
        );
    }

    /**
     * Append ONE reflection. Fail-closed: empty scope or empty reflection text ⇒ null (an unattributable or
     * empty reflection is noise worse than none). Flag OFF ⇒ null, byte-identical no-op. Returns the written
     * row, or null.
     *
     * @param  array<string,mixed>  $reflection  {scope, reflection, cycle_id?, result_kind?, signals?}
     * @return array<string,mixed>|null
     */
    public function record(array $reflection, ?int $at = null): ?array
    {
        if (! (bool) config('atlas.brain.reflection_enabled', false)) {
            return null; // flag OFF ⇒ byte-identical no-op
        }

        $scope = trim((string) ($reflection['scope'] ?? ''));
        $text = trim((string) ($reflection['reflection'] ?? ''));
        if ($scope === '' || $text === '') {
            return null; // fail-closed: an unattributable / empty reflection is worse than none
        }

        $kind = (string) ($reflection['result_kind'] ?? self::KIND_NOTE);
        if (! in_array($kind, self::KINDS, true)) {
            $kind = self::KIND_NOTE;
        }

        $row = [
            'schema' => self::SCHEMA,
            'scope' => $scope,
            'cycle_id' => trim((string) ($reflection['cycle_id'] ?? '')),
            'result_kind' => $kind,
            'reflection' => $text,
            'signals' => $this->normalizeSignals($reflection['signals'] ?? []),
            'recorded_at' => $at ?? time(),
        ];

        try {
            $dir = dirname($this->path);
            if (! is_dir($dir) && ! @mkdir($dir, 0o775, true) && ! is_dir($dir)) {
                return null;
            }
            @file_put_contents(
                $this->path,
                json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
        } catch (Throwable) {
            return null;
        }

        return $row;
    }

    /** @return list<array<string,mixed>> every reflection, in write order. */
    public function entries(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        $out = [];
        foreach (preg_split('/\R/', (string) @file_get_contents($this->path)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

    /** @return list<array<string,mixed>> reflections for one scope, in write order. */
    public function forScope(string $scope): array
    {
        $scope = trim($scope);

        return array_values(array_filter(
            $this->entries(),
            static fn (array $r): bool => (string) ($r['scope'] ?? '') === $scope
        ));
    }

    /**
     * Recall the top-K reflections for a scope, to inject into the next comprehension. Ordering is a
     * DETERMINISTIC function of facts (never a stored score): relevance (overlap with the query signals) desc,
     * then result-kind importance desc (failures first), then recency desc (later write == more recent).
     *
     * @param  array<string,mixed>  $context  {signals?: list<string>|array<string,mixed>} — the live cycle's facts
     * @return list<array<string,mixed>>
     */
    public function recall(string $scope, array $context = [], int $k = 5): array
    {
        if ($k <= 0) {
            return [];
        }

        $querySignals = $this->normalizeSignals($context['signals'] ?? $context);
        $rows = $this->forScope($scope);

        $ranked = [];
        foreach ($rows as $i => $row) {
            $ranked[] = [
                'relevance' => $this->relevance($querySignals, $this->normalizeSignals($row['signals'] ?? [])),
                'importance' => self::KIND_IMPORTANCE[(string) ($row['result_kind'] ?? self::KIND_NOTE)] ?? 1,
                'recency' => $i, // later index == more recent (forScope preserves write order)
                'row' => $row,
            ];
        }

        usort($ranked, static fn (array $a, array $b): int => [$b['relevance'], $b['importance'], $b['recency']]
            <=> [$a['relevance'], $a['importance'], $a['recency']]);

        return array_slice(array_map(static fn (array $r): array => $r['row'], $ranked), 0, $k);
    }

    /**
     * Flag-gated recall shaped for a brain payload: [] when reflection_enabled is OFF (so callers stay
     * byte-identical), else the top-K reflections as compact {kind, reflection} rows for injection into the
     * next comprehension. This is the single seam a producer (AtlasBrainNextCommand) calls — the flag gate
     * lives here so the producer never grows a config branch.
     *
     * @param  array<string,mixed>  $context
     * @return list<array{kind: string, reflection: string}>
     */
    public function recallTexts(string $scope, array $context = [], int $k = 5): array
    {
        if (! (bool) config('atlas.brain.reflection_enabled', false)) {
            return [];
        }

        return array_map(
            static fn (array $r): array => [
                'kind' => (string) ($r['result_kind'] ?? ''),
                'reflection' => (string) ($r['reflection'] ?? ''),
            ],
            $this->recall($scope, $context, $k),
        );
    }

    /**
     * Normalize signals to a flat list of comparable string tokens. Accepts a list (["target:Foo.php"]) or a
     * map ({target_path: "Foo.php", gate: "seed_quality"} → ["target_path:Foo.php", "gate:seed_quality"]).
     *
     * @return list<string>
     */
    private function normalizeSignals(mixed $signals): array
    {
        if (! is_array($signals)) {
            return [];
        }

        $out = [];
        foreach ($signals as $key => $value) {
            if (is_int($key)) {
                $token = trim((string) $value);
            } else {
                $scalar = is_scalar($value) ? (string) $value : (string) json_encode($value);
                $token = trim((string) $key).':'.trim($scalar);
            }
            if ($token !== '' && $token !== ':') {
                $out[] = $token;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  list<string>  $query
     * @param  list<string>  $row
     */
    private function relevance(array $query, array $row): int
    {
        if ($query === [] || $row === []) {
            return 0;
        }

        return count(array_intersect($query, $row));
    }
}
