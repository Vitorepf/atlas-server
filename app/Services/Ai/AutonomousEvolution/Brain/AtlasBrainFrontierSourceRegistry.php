<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;

/**
 * FRONTIER-HARVEST substrate — the SOURCE side of the brain's frontier path. The portfolio names
 * frontier-harvest as "mine the defined sites (trendshift/github/arxiv) for a frontier technique to
 * port" but until now the path had no DATA contract: the brain couldn't read a single curated frontier
 * candidate because no organ defined the shape OR the location. This registry is that contract: a
 * pure, read-only, per-scope NDJSON source. The operator (or a future ingestion organ) APPENDS
 * candidates to `<frontier_root>/<scope>.ndjson`; the brain READS the top-K most recent ones and the
 * frontier path has real material to work with.
 *
 * SCHEMA per row (every field is just a fact — no learning scalar persisted):
 *   {title:string, url:string, summary:string, source:string, captured_at:string?,
 *    trust_tier?:string, source_trust_tier?:string, anti_hype_note?:string, lead_only?:bool}
 *
 * Bounded read: by default top-K (most recent K entries) so the brain payload stays small even if
 * the file grows. Deterministic order: newest-first within K (the line order in the file is the
 * capture order; we reverse to put the newest first). Append-only by convention — the registry
 * never mutates the file.
 *
 * No-fetch: this organ does NOT call the network. Fetching is a SEPARATE concern (the brain stays
 * provider-agnostic + pure-read at this seam). A future fetcher writes to the same path and this
 * registry reads it — clean separation lets the fetcher live in tests / cron / a CLI without forcing
 * the brain to grow an HTTP dependency.
 *
 * Pétreo: the réu never edits the source registry — it's the input layer to the frontier-harvest
 * path. Editable input ⇒ the brain could pre-seed itself with whatever frontier it wanted to "win",
 * collapsing the path's discriminating power. Same principle as the comprehension model + reflection
 * stream + structural-signal digest.
 */
final class AtlasBrainFrontierSourceRegistry
{
    public const SCHEMA = 'atlas.brain.frontier_source.v1';

    public const DEFAULT_K = 5;

    private readonly string $root;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim($root ?? (string) config(
            'atlas.brain.frontier_root',
            storage_path('app/atlas/brain/frontier')
        ), '/');
    }

    /**
     * Append ONE candidate row to the scope's NDJSON file. Fail-closed: empty title OR empty source
     * ⇒ no-op (an unattributable candidate is noise; same contract as the reflection stream). Filled
     * captured_at uses the value the caller supplies (we never call wall-clock here to keep this
     * deterministic / replayable). Returns the written row, or null when refused.
     *
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>|null
     */
    public function append(string $scope, array $candidate): ?array
    {
        $scopeSlug = $this->slugify($scope);
        $title = trim((string) ($candidate['title'] ?? ''));
        $source = trim((string) ($candidate['source'] ?? ''));
        if ($scopeSlug === 'default' && $scope !== '' && $this->slugify($scope) !== $scopeSlug) {
            return null; // unreachable but defensive — scope must round-trip the slugifier.
        }
        if ($title === '' || $source === '') {
            return null;
        }

        $row = [
            'schema' => self::SCHEMA,
            'title' => $title,
            'url' => trim((string) ($candidate['url'] ?? '')),
            'summary' => trim((string) ($candidate['summary'] ?? '')),
            'source' => $source,
            'captured_at' => trim((string) ($candidate['captured_at'] ?? '')),
        ];
        foreach (['trust_tier', 'source_trust_tier', 'anti_hype_note'] as $field) {
            $value = trim((string) ($candidate[$field] ?? ''));
            if ($value !== '') {
                $row[$field] = $value;
            }
        }
        if (array_key_exists('lead_only', $candidate)) {
            $row['lead_only'] = (bool) $candidate['lead_only'];
        }

        try {
            (new JsonlReceiptStore($this->pathFor($scopeSlug)))->append($row);
        } catch (\Throwable) {
            return null; // was: uncreatable root dir ⇒ null, never throw
        }

        return $row;
    }

    /**
     * Return the top-K most recent rows for a scope. Empty when the file doesn't exist (frontier-harvest
     * gracefully has nothing yet — the brain stays quiet rather than emitting an empty key).
     *
     * @return list<array<string,mixed>>
     */
    /**
     * Count the number of candidate rows recorded for a scope (cheap; reads + parses the NDJSON). Returns
     * 0 when the file doesn't exist (frontier-harvest gracefully has nothing yet for new scopes).
     */
    public function count(string $scope): int
    {
        return count($this->validRows($scope));
    }

    public function topK(string $scope, int $k = self::DEFAULT_K): array
    {
        if ($k <= 0) {
            return [];
        }

        // Newest-first within K: the file order IS capture order; reverse + slice keeps it stable.
        return array_slice(array_reverse($this->validRows($scope)), 0, $k);
    }

    /** @return list<array<string,mixed>> decoded rows with a non-empty title, oldest-first. */
    private function validRows(string $scope): array
    {
        return array_values(array_filter(
            (new JsonlReceiptStore($this->pathFor($this->slugify($scope))))->replay(),
            static fn (array $row): bool => trim((string) ($row['title'] ?? '')) !== '',
        ));
    }

    private function pathFor(string $scopeSlug): string
    {
        return $this->root.'/'.$scopeSlug.'.ndjson';
    }

    private function slugify(string $slug): string
    {
        $clean = strtolower(trim($slug));
        $clean = (string) preg_replace('/[^a-z0-9]+/', '-', $clean);

        return trim($clean, '-') ?: 'default';
    }
}
