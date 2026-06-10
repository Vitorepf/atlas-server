<?php

declare(strict_types=1);

namespace App\Services\Ai\Cartography;

use App\Models\AtlasEngineeringKnowledgeItem;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use App\Services\Engineering\EngineeringKnowledgeBaseService;
use App\Services\Semantic\CanonicalDocsFrontmatterParser;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use SplFileInfo;

/**
 * Cartography Truth Guard — Patamar 4 invariant.
 *
 * Regra canon (operator-declared): Cartografia = espelho fiel da documentação
 * canônica em `docs/engineering-knowledge-base/*.md`. Se a Cartografia mostra
 * dado que NÃO existe no .md, ou esconde dado que existe, ou o conteúdo está
 * stale (KB index com content_hash diferente do .md atual) → CRIME CRÍTICO.
 *
 * Este service faz o probe append-only:
 *   1. Lê todos os .md canônicos sob docs/engineering-knowledge-base/
 *   2. Computa {slug → content_hash} canônico
 *   3. Lê AtlasEngineeringKnowledgeItem (read-model que alimenta Cartografia)
 *   4. Computa drift: orphan_in_kb | missing_in_kb | content_drift
 *   5. Para cada drift, emite violation no Constitutional Kernel
 *   6. Receipt JSONL append-only com hash
 *
 * Authority doc: docs/engineering-knowledge-base/atlas-cartography-truth-guard.md
 *
 * Schema:
 *   - atlas.cartography.truth_guard_sweep.v1
 *   - atlas.cartography.drift_entry.v1
 *
 * Invariants:
 *   - PROBE-ONLY: nunca escreve no KB, nunca corrige sozinho
 *   - drift → violation no Kernel (não block automático)
 *   - defensive degradation: KB table missing → sweep retorna 'kb_table_missing'
 *   - claim_policy provider-safe enforced
 */
final class CartographyTruthGuardService
{
    public const SWEEP_SCHEMA = 'atlas.cartography.truth_guard_sweep.v1';

    public const DRIFT_SCHEMA = 'atlas.cartography.drift_entry.v1';

    public const DRIFT_ORPHAN_IN_KB = 'orphan_in_kb';

    public const DRIFT_MISSING_IN_KB = 'missing_in_kb';

    public const DRIFT_CONTENT_DRIFT = 'content_drift';

    public const STATUS_TRUTHFUL = 'truthful';

    public const STATUS_DRIFT = 'drift_detected';

    public const STATUS_KB_TABLE_MISSING = 'kb_table_missing';

    public const STATUS_DOCS_ROOT_MISSING = 'docs_root_missing';

    private ?string $logPathOverride = null;

    private ?string $docsRootOverride = null;

    public function __construct(
        private readonly AtlasConstitutionalKernelService $kernel,
        private readonly CanonicalDocsFrontmatterParser $frontmatter,
    ) {}

    public function setLogPathForTesting(?string $path): void
    {
        $this->logPathOverride = $path;
    }

    public function setDocsRootForTesting(?string $path): void
    {
        $this->docsRootOverride = $path;
    }

    public function logPath(): string
    {
        if ($this->logPathOverride !== null) {
            return $this->logPathOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/cartography')
            : sys_get_temp_dir().'/atlas/cartography';

        return $base.DIRECTORY_SEPARATOR.'truth_guard_sweeps.jsonl';
    }

    public function docsRoot(): string
    {
        if ($this->docsRootOverride !== null) {
            return $this->docsRootOverride;
        }

        return function_exists('base_path')
            ? base_path('docs/engineering-knowledge-base')
            : __DIR__.'/../../../../docs/engineering-knowledge-base';
    }

    /**
     * Run one truth sweep. Append-only receipt + Kernel violation per drift.
     *
     * @return array<string,mixed>
     */
    public function sweep(string $actor = 'cartography_truth_guard'): array
    {
        $startedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

        $docsRoot = $this->docsRoot();
        if (! is_dir($docsRoot)) {
            return $this->persist($this->envelope(
                startedAt: $startedAt,
                status: self::STATUS_DOCS_ROOT_MISSING,
                canonicalCount: 0,
                kbCount: 0,
                drifts: [],
                actor: $actor,
                note: "docs root '{$docsRoot}' not a directory",
            ));
        }

        $canonical = $this->scanCanonicalDocs($docsRoot);

        $kbReachable = false;
        try {
            $kbReachable = Schema::hasTable('atlas_engineering_knowledge_items');
        } catch (\Throwable $e) {
            $kbReachable = false;
        }
        if (! $kbReachable) {
            return $this->persist($this->envelope(
                startedAt: $startedAt,
                status: self::STATUS_KB_TABLE_MISSING,
                canonicalCount: count($canonical),
                kbCount: 0,
                drifts: [],
                actor: $actor,
                note: 'KB unreachable or table missing — Cartografia cannot be probed until sync runs',
            ));
        }

        try {
            $kbItems = AtlasEngineeringKnowledgeItem::query()
                ->where('source_type', 'canonical_doc')
                ->where('status', '!=', 'archived')
                ->get(['slug', 'content_hash', 'canonical_path']);
        } catch (\Throwable $e) {
            return $this->persist($this->envelope(
                startedAt: $startedAt,
                status: self::STATUS_KB_TABLE_MISSING,
                canonicalCount: count($canonical),
                kbCount: 0,
                drifts: [],
                actor: $actor,
                note: 'KB query failed: '.substr($e->getMessage(), 0, 160),
            ));
        }

        $kb = [];
        foreach ($kbItems as $item) {
            $kb[(string) $item->slug] = [
                'content_hash' => (string) $item->content_hash,
                'canonical_path' => (string) $item->canonical_path,
            ];
        }

        $drifts = [];

        // 1. content_drift + missing_in_kb
        foreach ($canonical as $slug => $info) {
            if (! isset($kb[$slug])) {
                $drifts[] = $this->driftEntry(
                    kind: self::DRIFT_MISSING_IN_KB,
                    slug: $slug,
                    canonicalPath: $info['relative_path'],
                    canonicalHash: $info['content_hash'],
                    kbHash: null,
                );
                continue;
            }
            if ($kb[$slug]['content_hash'] !== $info['content_hash']) {
                $drifts[] = $this->driftEntry(
                    kind: self::DRIFT_CONTENT_DRIFT,
                    slug: $slug,
                    canonicalPath: $info['relative_path'],
                    canonicalHash: $info['content_hash'],
                    kbHash: $kb[$slug]['content_hash'],
                );
            }
        }

        // 2. orphan_in_kb
        foreach ($kb as $slug => $info) {
            if (! isset($canonical[$slug])) {
                $drifts[] = $this->driftEntry(
                    kind: self::DRIFT_ORPHAN_IN_KB,
                    slug: $slug,
                    canonicalPath: $info['canonical_path'],
                    canonicalHash: null,
                    kbHash: $info['content_hash'],
                );
            }
        }

        // 3. Each drift emits a Kernel violation receipt.
        foreach ($drifts as $drift) {
            try {
                $this->kernel->recordViolation([
                    'actor' => $actor,
                    'change_kind' => 'cartography_truth_guard',
                    'decision' => AtlasConstitutionalKernelService::DECISION_BLOCK,
                    'reason' => sprintf(
                        'cartography drift detected: kind=%s slug=%s',
                        $drift['drift_kind'],
                        $drift['slug']
                    ),
                    'violations' => [[
                        'invariant_id' => 'no_silent_invariant_mutation',
                        'reason' => 'cartography_doc_drift:'.$drift['drift_kind'],
                    ]],
                ]);
            } catch (\Throwable $e) {
                // Defensive — kernel ledger write failure must not abort the sweep.
            }
        }

        $status = $drifts === [] ? self::STATUS_TRUTHFUL : self::STATUS_DRIFT;

        return $this->persist($this->envelope(
            startedAt: $startedAt,
            status: $status,
            canonicalCount: count($canonical),
            kbCount: count($kb),
            drifts: $drifts,
            actor: $actor,
            note: $drifts === []
                ? 'cartography mirrors canonical docs'
                : sprintf('%d drift entries — operator must reconcile via atlas engineering knowledge sync --prune', count($drifts))
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listSweeps(): array
    {
        return AppendOnlyJsonlStore::read($this->logPath());
    }

    public function lastSweep(): ?array
    {
        $list = $this->listSweeps();

        return $list === [] ? null : $list[count($list) - 1];
    }

    /**
     * Convenience accessor for tests/CLIs.
     *
     * @return array<string, array{relative_path:string, content_hash:string}>
     */
    public function canonicalIndex(): array
    {
        return $this->scanCanonicalDocs($this->docsRoot());
    }

    // ---------- internals ----------

    /**
     * @return array<string, array{relative_path:string, content_hash:string}>
     */
    private function scanCanonicalDocs(string $root): array
    {
        $files = collect(File::allFiles($root))
            ->filter(fn (SplFileInfo $f): bool => strtolower($f->getExtension()) === 'md')
            ->values()
            ->all();

        $out = [];
        foreach ($files as $file) {
            $path = $file->getPathname();
            $markdown = File::get($path);
            $parsed = $this->frontmatter->parse($markdown);
            $frontmatter = is_array($parsed['frontmatter'] ?? null) ? $parsed['frontmatter'] : [];
            $relative = $this->relativePath($path);
            $slug = $this->slug((string) ($frontmatter['id'] ?? $frontmatter['slug'] ?? $relative));
            $out[$slug] = [
                'relative_path' => $relative,
                'content_hash' => hash('sha256', $markdown),
            ];
        }

        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private function driftEntry(
        string $kind,
        string $slug,
        string $canonicalPath,
        ?string $canonicalHash,
        ?string $kbHash,
    ): array {
        return [
            'schema_version' => self::DRIFT_SCHEMA,
            'drift_kind' => $kind,
            'slug' => $slug,
            'canonical_path' => $canonicalPath,
            'canonical_content_hash' => $canonicalHash,
            'kb_content_hash' => $kbHash,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $drifts
     * @return array<string,mixed>
     */
    private function envelope(
        string $startedAt,
        string $status,
        int $canonicalCount,
        int $kbCount,
        array $drifts,
        string $actor,
        string $note,
    ): array {
        $envelope = [
            'schema_version' => self::SWEEP_SCHEMA,
            'started_at' => $startedAt,
            'actor' => $actor,
            'status' => $status,
            'canonical_doc_count' => $canonicalCount,
            'kb_item_count' => $kbCount,
            'drift_count' => count($drifts),
            'drifts' => $drifts,
            'note' => $note,
        ];
        $envelope['sweep_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => self::SWEEP_SCHEMA,
            'started_at' => $startedAt,
            'status' => $status,
            'canonical_doc_count' => $canonicalCount,
            'kb_item_count' => $kbCount,
            'drifts' => array_map(static fn ($d) => $d['drift_kind'].':'.$d['slug'], $drifts),
        ], JSON_THROW_ON_ERROR));

        return $envelope;
    }

    /**
     * @param  array<string,mixed>  $envelope
     * @return array<string,mixed>
     */
    private function persist(array $envelope): array
    {
        AppendOnlyJsonlStore::append($this->logPath(), $envelope);

        return $envelope;
    }

    private function slug(string $raw): string
    {
        $clean = trim($raw);
        $clean = preg_replace('/\.md$/', '', $clean) ?? $clean;
        $clean = preg_replace('#[/\\\\]#', '-', $clean) ?? $clean;
        $clean = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $clean) ?? $clean;
        $clean = trim($clean, '-');

        return strtolower($clean) ?: 'doc';
    }

    private function relativePath(string $path): string
    {
        $base = function_exists('base_path') ? base_path().DIRECTORY_SEPARATOR : '';
        if ($base !== '' && str_starts_with($path, $base)) {
            return substr($path, strlen($base));
        }

        return $path;
    }

}
