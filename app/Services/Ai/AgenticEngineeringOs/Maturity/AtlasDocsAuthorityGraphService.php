<?php

namespace App\Services\Ai\AgenticEngineeringOs\Maturity;

use App\Models\AtlasDocsAuthorityGraph;
use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Engineering\EngineeringStringListNormalizer;
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
    public const SCHEMA_VERSION = 'atlas.docs.authority_graph.v1';

    public const LOCATE_SCHEMA = 'atlas.docs.locate.v1';

    /**
     * @var array<string, int>
     */
    public const CONFIDENCE = [
        self::FIELD_GOVERNS_FRONTMATTER => self::INT_100,
        self::FIELD_DOC_ID => self::INT_95,
        self::FIELD_CAPABILITY_FRONTMATTER => self::INT_80,
        self::FIELD_KEYWORD_FALLBACK => self::INT_40,
    ];

    public const DEFAULT_LOCATE_LIMIT = 5;

    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_NEEDLE = 'needle';
    public const FIELD_OWNER_DOC_PATH = 'owner_doc_path';
    public const FIELD_CONFIDENCE = 'confidence';
    public const FIELD_KEYWORD_FALLBACK = 'keyword_fallback';
    public const FIELD_FRONTMATTER = 'frontmatter';
    public const FIELD_OWNER_BASIS = 'owner_basis';
    public const FIELD_PATH = 'path';
    public const FIELD_RESOLVED = 'resolved';
    public const FIELD_CANDIDATES = 'candidates';
    public const FIELD_OWNER_DOC_ID = 'owner_doc_id';
    public const FIELD_OWNER_IMPLEMENTATION_STATE = 'owner_implementation_state';
    public const FIELD_GOVERNS_FRONTMATTER = 'governs_frontmatter';
    public const FIELD_DOC_ID = 'doc_id';
    public const FIELD_CAPABILITY_FRONTMATTER = 'capability_frontmatter';
    public const FIELD_ROWS = 'rows';
    public const FIELD_BASIS = 'basis';
    public const FIELD_CAPABILITIES = 'capabilities';
    public const FIELD_CREATED_AT = 'created_at';
    public const FIELD_DOCS = 'docs';
    public const FIELD_GOVERNS = 'governs';
    public const FIELD_GRAPH_ID = 'graph_id';
    public const FIELD_ID = 'id';
    public const FIELD_IMPLEMENTATION_STATE = 'implementation_state';
    public const FIELD_NEEDLE_KIND = 'needle_kind';
    public const FIELD_NEEDLE_NORMALIZED = 'needle_normalized';
    public const FIELD_UPDATED_AT = 'updated_at';
    public const FIELD_ATLAS_DOCS_AUTHORITY_GRAPH = 'atlas_docs_authority_graph';
    public const FIELD_CAPABILITY = 'capability';
    public const FIELD_LIKE = 'like';
    public const FIELD_ARCHIVE = 'archive';
    public const FIELD_MD = 'md';
    public const FIELD_DOCS_ENGINEERING_KNOWLEDGE_BASE = 'docs/engineering-knowledge-base';
    public const INT_80 = 80;
    public const INT_95 = 95;
    public const INT_100 = 100;
    public const INT_40 = 40;

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
            foreach ($this->rowsForDoc($doc[self::FIELD_FRONTMATTER], $doc[self::FIELD_PATH]) as $row) {
                $rows[] = $row + [self::FIELD_CREATED_AT => now(), self::FIELD_UPDATED_AT => now()];
            }
        }

        DB::transaction(function () use ($rows): void {
            AtlasDocsAuthorityGraph::query()->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                AtlasDocsAuthorityGraph::query()->insert($chunk);
            }
        });

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_ROWS => count($rows),
            self::FIELD_DOCS => count($docs),
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
        $ownerId = AiValueNormalizer::trimmedStringOrNull($frontmatter[self::FIELD_ID] ?? $frontmatter[self::FIELD_GRAPH_ID] ?? null) ?? '';
        $state = AiValueNormalizer::trimmedStringOrNull($frontmatter[self::FIELD_IMPLEMENTATION_STATE] ?? null) ?? '';
        $rows = [];

        $add = function (string $kind, mixed $needle, string $basis) use (&$rows, $path, $ownerId, $state): void {
            $needle = AiValueNormalizer::trimmedStringOrNull($needle) ?? '';
            if ($needle === '') {
                return;
            }
            // Clip to the read-model column sizes — doc frontmatter values are
            // arbitrary, and pgsql (unlike sqlite) enforces varchar lengths.
            $needle = mb_substr($needle, 0, 300);
            $rows[] = [
                self::FIELD_NEEDLE_KIND => mb_substr($kind, 0, 40),
                self::FIELD_NEEDLE => $needle,
                self::FIELD_NEEDLE_NORMALIZED => mb_substr(AiValueNormalizer::lowerTrimmedString($needle), 0, 300),
                self::FIELD_OWNER_DOC_PATH => mb_substr($path, 0, 500),
                self::FIELD_OWNER_DOC_ID => $ownerId !== '' ? mb_substr($ownerId, 0, 200) : null,
                self::FIELD_OWNER_BASIS => $basis,
                self::FIELD_CONFIDENCE => self::CONFIDENCE[$basis] ?? 0,
                self::FIELD_OWNER_IMPLEMENTATION_STATE => $state !== '' ? mb_substr($state, 0, 60) : null,
            ];
        };

        if ($ownerId !== '') {
            $add(self::FIELD_DOC_ID, $ownerId, self::FIELD_DOC_ID);
        }
        foreach (AiValueNormalizer::arrayOrEmpty($frontmatter[self::FIELD_GOVERNS] ?? null) as $governs) {
            $add(self::FIELD_GOVERNS, $governs, self::FIELD_GOVERNS_FRONTMATTER);
        }
        foreach (AiValueNormalizer::arrayOrEmpty($frontmatter[self::FIELD_CAPABILITIES] ?? null) as $capability) {
            $add(self::FIELD_CAPABILITY, $capability, self::FIELD_CAPABILITY_FRONTMATTER);
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
    public function locate(string $needle, int $limit = self::DEFAULT_LOCATE_LIMIT): array
    {
        $normalized = AiValueNormalizer::lowerTrimmedString($needle);

        // Read-model fail-open: when the table was never materialized (fresh
        // env, test sqlite without migrations), resolve to "not found" instead
        // of a QueryException. Guard here, once, for every caller.
        if (! DatabaseTableAvailability::has(self::FIELD_ATLAS_DOCS_AUTHORITY_GRAPH)) {
            return $this->result($needle, collect(), fallback: true);
        }

        $exact = AtlasDocsAuthorityGraph::query()
            ->where(self::FIELD_NEEDLE_NORMALIZED, $normalized)
            ->orderByDesc(self::FIELD_CONFIDENCE)
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
                    $w->orWhere(self::FIELD_NEEDLE_NORMALIZED, self::FIELD_LIKE, '%'.$variant.'%')
                        ->orWhere(self::FIELD_OWNER_DOC_PATH, self::FIELD_LIKE, '%'.$variant.'%')
                        ->orWhere(self::FIELD_OWNER_DOC_ID, self::FIELD_LIKE, '%'.$variant.'%');
                }
            })
            ->orderByDesc(self::FIELD_CONFIDENCE)
            ->limit($limit)
            ->get();

        return $this->result($needle, $fallback, fallback: true);
    }

    /**
     * @return array<int,string>
     */
    private function needleVariants(string $normalized): array
    {
        return EngineeringStringListNormalizer::uniqueTruthyStringValues([
            $normalized,
            str_replace(' ', '-', $normalized),
            str_replace(' ', '_', $normalized),
            str_replace(['-', '_'], ' ', $normalized),
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int,AtlasDocsAuthorityGraph>  $matches
     * @return array<string,mixed>
     */
    private function result(string $needle, $matches, bool $fallback): array
    {
        if ($matches->isEmpty()) {
            return [
                self::FIELD_SCHEMA_VERSION => self::LOCATE_SCHEMA,
                self::FIELD_NEEDLE => $needle,
                self::FIELD_RESOLVED => false,
                self::FIELD_OWNER_DOC_PATH => null,
                self::FIELD_OWNER_BASIS => self::FIELD_KEYWORD_FALLBACK,
                self::FIELD_CONFIDENCE => 0,
                self::FIELD_CANDIDATES => [],
            ];
        }

        $best = $matches->first();
        $basis = $fallback ? self::FIELD_KEYWORD_FALLBACK : (AiValueNormalizer::trimmedScalarStringOrNull($best->owner_basis ?? null) ?? '');
        $confidence = $fallback ? self::CONFIDENCE[self::FIELD_KEYWORD_FALLBACK] : (int) $best->confidence;

        return [
            self::FIELD_SCHEMA_VERSION => self::LOCATE_SCHEMA,
            self::FIELD_NEEDLE => $needle,
            self::FIELD_RESOLVED => true,
            self::FIELD_OWNER_DOC_PATH => AiValueNormalizer::trimmedScalarStringOrNull($best->owner_doc_path ?? null) ?? '',
            self::FIELD_OWNER_DOC_ID => $best->owner_doc_id,
            self::FIELD_OWNER_BASIS => $basis,
            self::FIELD_CONFIDENCE => $confidence,
            self::FIELD_OWNER_IMPLEMENTATION_STATE => $best->owner_implementation_state,
            self::FIELD_CANDIDATES => $matches->map(fn (AtlasDocsAuthorityGraph $row): array => [
                self::FIELD_OWNER_DOC_PATH => AiValueNormalizer::trimmedScalarStringOrNull($row->owner_doc_path ?? null) ?? '',
                self::FIELD_NEEDLE => AiValueNormalizer::trimmedScalarStringOrNull($row->needle ?? null) ?? '',
                self::FIELD_BASIS => $fallback ? self::FIELD_KEYWORD_FALLBACK : (AiValueNormalizer::trimmedScalarStringOrNull($row->owner_basis ?? null) ?? ''),
                self::FIELD_CONFIDENCE => $fallback ? self::CONFIDENCE[self::FIELD_KEYWORD_FALLBACK] : (int) $row->confidence,
            ])->all(),
        ];
    }

    /**
     * @return array<int,array{path:string, frontmatter:array<string,mixed>}>
     */
    private function scanDocs(): array
    {
        $root = base_path(self::FIELD_DOCS_ENGINEERING_KNOWLEDGE_BASE);
        if (! File::isDirectory($root)) {
            return [];
        }

        $docs = [];
        foreach (File::allFiles($root) as $file) {
            /** @var SplFileInfo $file */
            if (AiValueNormalizer::lowerTrimmedString($file->getExtension()) !== self::FIELD_MD) {
                continue;
            }
            $parsed = $this->frontmatter->parse(File::get($file->getPathname()));
            $frontmatter = AiValueNormalizer::arrayOrEmpty($parsed[self::FIELD_FRONTMATTER] ?? null);
            if (str_contains($file->getPathname(), DIRECTORY_SEPARATOR.self::FIELD_ARCHIVE.DIRECTORY_SEPARATOR)) {
                continue;
            }
            $docs[] = [
                self::FIELD_PATH => str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()),
                self::FIELD_FRONTMATTER => $frontmatter,
            ];
        }

        return $docs;
    }
}
