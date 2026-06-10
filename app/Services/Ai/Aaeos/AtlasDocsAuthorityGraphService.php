<?php

namespace App\Services\Ai\Aaeos;

use App\Models\AtlasDocsAuthorityGraph;
use App\Services\Semantic\CanonicalDocsFrontmatterParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use SplFileInfo;

/**
 * R1 — builds and queries the owner-doc authority graph so any AI resolves
 * "where does X live / where should X go" in one deterministic step, instead of
 * scanning 897 docs or trusting a hardcoded map. v1 derives rows from doc
 * frontmatter (`governs`, `capabilities`, `id`) with a fixed precedence; query
 * time adds a keyword fallback so a needle is never simply "unmapped".
 *
 * @see docs/engineering-knowledge-base/atlas-aaeos-documentation-as-law-proposal.md
 */
class AtlasDocsAuthorityGraphService
{
    private const CONFIDENCE = [
        'governs_frontmatter' => 100,
        'doc_id' => 95,
        'capability_frontmatter' => 80,
        'keyword_fallback' => 40,
    ];

    public function __construct(
        private readonly CanonicalDocsFrontmatterParser $frontmatter,
    ) {}

    /**
     * Rebuild the whole authority graph from canonical doc frontmatter. The graph
     * is fully regenerated (replace, not merge) so deletions/renames cannot leave
     * stale owners. Intended to run inside index-code.
     *
     * @return array{schema_version:string, rows:int, docs:int}
     */
    public function build(): array
    {
        $docs = $this->scanDocs();
        $rows = [];
        foreach ($docs as $doc) {
            foreach ($this->rowsForDoc($doc['frontmatter'], $doc['path']) as $row) {
                $rows[] = $row + ['created_at' => now(), 'updated_at' => now()];
            }
        }

        DB::transaction(function () use ($rows): void {
            AtlasDocsAuthorityGraph::query()->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                AtlasDocsAuthorityGraph::query()->insert($chunk);
            }
        });

        return [
            'schema_version' => 'atlas.docs.authority_graph.v1',
            'rows' => count($rows),
            'docs' => count($docs),
        ];
    }

    /**
     * Pure: derive authority rows for a single doc. Exposed for unit testing.
     * Precedence by basis: governs_frontmatter (100) > doc_id (95) >
     * capability_frontmatter (80).
     *
     * @param  array<string,mixed>  $frontmatter
     * @return array<int,array<string,mixed>>
     */
    public function rowsForDoc(array $frontmatter, string $path): array
    {
        $ownerId = trim((string) ($frontmatter['id'] ?? $frontmatter['graph_id'] ?? ''));
        $state = trim((string) ($frontmatter['implementation_state'] ?? ''));
        $rows = [];

        $add = function (string $kind, mixed $needle, string $basis) use (&$rows, $path, $ownerId, $state): void {
            $needle = trim((string) $needle);
            if ($needle === '') {
                return;
            }
            // Clip to the read-model column sizes — doc frontmatter values are
            // arbitrary, and pgsql (unlike sqlite) enforces varchar lengths.
            $needle = mb_substr($needle, 0, 300);
            $rows[] = [
                'needle_kind' => mb_substr($kind, 0, 40),
                'needle' => $needle,
                'needle_normalized' => mb_substr(mb_strtolower($needle), 0, 300),
                'owner_doc_path' => mb_substr($path, 0, 500),
                'owner_doc_id' => $ownerId !== '' ? mb_substr($ownerId, 0, 200) : null,
                'owner_basis' => $basis,
                'confidence' => self::CONFIDENCE[$basis] ?? 0,
                'owner_implementation_state' => $state !== '' ? mb_substr($state, 0, 60) : null,
            ];
        };

        if ($ownerId !== '') {
            $add('doc_id', $ownerId, 'doc_id');
        }
        foreach ((array) ($frontmatter['governs'] ?? []) as $governs) {
            $add('governs', $governs, 'governs_frontmatter');
        }
        foreach ((array) ($frontmatter['capabilities'] ?? []) as $capability) {
            $add('capability', $capability, 'capability_frontmatter');
        }

        return $rows;
    }

    /**
     * Resolve the canonical owner doc for a needle. Exact (normalized) match
     * first, ordered by confidence; otherwise a keyword fallback over needle,
     * path and id so a best-effort owner is always offered when any doc relates.
     *
     * @return array<string,mixed>
     */
    public function locate(string $needle, int $limit = 5): array
    {
        $normalized = mb_strtolower(trim($needle));

        $exact = AtlasDocsAuthorityGraph::query()
            ->where('needle_normalized', $normalized)
            ->orderByDesc('confidence')
            ->limit($limit)
            ->get();

        if ($exact->isNotEmpty()) {
            return $this->result($needle, $exact, fallback: false);
        }

        // Forgiving fallback: try the needle as-is and with spaces<->dashes
        // normalized, since doc ids/governs use dashes/underscores while a human
        // or AI may type spaces (e.g. "autonomy ladder" -> atlas-autonomy-ladder-*).
        $variants = $this->needleVariants($normalized);

        $fallback = AtlasDocsAuthorityGraph::query()
            ->where(function ($w) use ($variants): void {
                foreach ($variants as $variant) {
                    $w->orWhere('needle_normalized', 'like', '%'.$variant.'%')
                        ->orWhere('owner_doc_path', 'like', '%'.$variant.'%')
                        ->orWhere('owner_doc_id', 'like', '%'.$variant.'%');
                }
            })
            ->orderByDesc('confidence')
            ->limit($limit)
            ->get();

        return $this->result($needle, $fallback, fallback: true);
    }

    /**
     * @return array<int,string>
     */
    private function needleVariants(string $normalized): array
    {
        return $this->uniqueNonEmptyStrings([
            $normalized,
            str_replace(' ', '-', $normalized),
            str_replace(' ', '_', $normalized),
            str_replace(['-', '_'], ' ', $normalized),
        ]);
    }

    /**
     * @param  array<int,string>  $values
     * @return array<int,string>
     */
    private function uniqueNonEmptyStrings(array $values): array
    {
        return array_values(array_unique(array_filter($values)));
    }

    /**
     * @param  \Illuminate\Support\Collection<int,AtlasDocsAuthorityGraph>  $matches
     * @return array<string,mixed>
     */
    private function result(string $needle, $matches, bool $fallback): array
    {
        if ($matches->isEmpty()) {
            return [
                'schema_version' => 'atlas.docs.locate.v1',
                'needle' => $needle,
                'resolved' => false,
                'owner_doc_path' => null,
                'owner_basis' => 'keyword_fallback',
                'confidence' => 0,
                'candidates' => [],
            ];
        }

        $best = $matches->first();
        $basis = $fallback ? 'keyword_fallback' : (string) $best->owner_basis;
        $confidence = $fallback ? self::CONFIDENCE['keyword_fallback'] : (int) $best->confidence;

        return [
            'schema_version' => 'atlas.docs.locate.v1',
            'needle' => $needle,
            'resolved' => true,
            'owner_doc_path' => (string) $best->owner_doc_path,
            'owner_doc_id' => $best->owner_doc_id,
            'owner_basis' => $basis,
            'confidence' => $confidence,
            'owner_implementation_state' => $best->owner_implementation_state,
            'candidates' => $matches->map(fn (AtlasDocsAuthorityGraph $row): array => [
                'owner_doc_path' => (string) $row->owner_doc_path,
                'needle' => (string) $row->needle,
                'basis' => $fallback ? 'keyword_fallback' : (string) $row->owner_basis,
                'confidence' => $fallback ? self::CONFIDENCE['keyword_fallback'] : (int) $row->confidence,
            ])->all(),
        ];
    }

    /**
     * @return array<int,array{path:string, frontmatter:array<string,mixed>}>
     */
    private function scanDocs(): array
    {
        $root = base_path('docs/engineering-knowledge-base');
        if (! File::isDirectory($root)) {
            return [];
        }

        $docs = [];
        foreach (File::allFiles($root) as $file) {
            /** @var SplFileInfo $file */
            if (strtolower($file->getExtension()) !== 'md') {
                continue;
            }
            $parsed = $this->frontmatter->parse(File::get($file->getPathname()));
            $frontmatter = is_array($parsed['frontmatter'] ?? null) ? $parsed['frontmatter'] : [];
            if (str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'archive'.DIRECTORY_SEPARATOR)) {
                continue;
            }
            $docs[] = [
                'path' => str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()),
                'frontmatter' => $frontmatter,
            ];
        }

        return $docs;
    }
}
