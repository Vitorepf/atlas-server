<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\Cartography;

use App\Services\Ai\Support\AppendOnlyJsonlStore;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Atlas Programming Cartography Publisher.
 *
 * Fecha o gap canon "cartografia operacional emit-only" identificado no audit
 * 2026-05-26. ProgrammingCartographyGate emite "cartography_publishing_required"
 * mas não existia publisher real. Este service LÊ work items + specs + tasks +
 * evidence + drift reports e PROJETA um grafo canônico append-only.
 *
 * Authority doc: docs/engineering-knowledge-base/atlas-programming-cartography-publisher.md
 *
 * Schema: atlas.programming.cartography_graph.v1
 *
 * Invariantes:
 *   - Read-only sobre Programming Governance models (não muta nada)
 *   - Snapshots append-only JSONL
 *   - claim_policy provider-safe
 *   - Defensive degradation: se modelo/tabela não disponível (ex: SQLite test
 *     sem migration), publisher retorna grafo vazio honesto, não crasha.
 */
final class AtlasProgrammingCartographyPublisherService
{
    public const SCHEMA_VERSION = 'atlas.programming.cartography_graph.v1';

    public const NODE_KIND_WORK_ITEM = 'work_item';

    public const NODE_KIND_SPEC = 'spec';

    public const NODE_KIND_TASK = 'task';

    public const NODE_KIND_FILE = 'file';

    public const NODE_KIND_EVIDENCE = 'evidence';

    public const NODE_KIND_DRIFT = 'drift_report';

    public const EDGE_HAS_SPEC = 'has_spec';

    public const EDGE_CONTAINS_TASK = 'contains_task';

    public const EDGE_TOUCHES = 'touches';

    public const EDGE_EVIDENCED_BY = 'evidenced_by';

    public const EDGE_HAS_DRIFT = 'has_drift';

    private ?string $snapshotsLogOverride = null;

    public function setSnapshotsLogPathForTesting(?string $path): void
    {
        $this->snapshotsLogOverride = $path;
    }

    public function snapshotsLogPath(): string
    {
        if ($this->snapshotsLogOverride !== null) {
            return $this->snapshotsLogOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/programming')
            : sys_get_temp_dir().'/atlas/programming';

        return $base.DIRECTORY_SEPARATOR.'cartography_snapshots.jsonl';
    }

    /**
     * Publish a cartography snapshot. Defensive against missing tables —
     * returns an honest empty graph rather than crashing.
     *
     * @param  array<string,mixed>  $context  optional filters: workspace, work_item_codes, limit
     * @return array<string,mixed>
     */
    public function publish(array $context = []): array
    {
        $workspace = (string) ($context['workspace'] ?? '');
        $limit = max(1, min(500, (int) ($context['limit'] ?? 100)));

        $nodes = [];
        $edges = [];
        $byKind = array_fill_keys([
            self::NODE_KIND_WORK_ITEM,
            self::NODE_KIND_SPEC,
            self::NODE_KIND_TASK,
            self::NODE_KIND_FILE,
            self::NODE_KIND_EVIDENCE,
            self::NODE_KIND_DRIFT,
        ], 0);

        // Read work items (defensive). Each work item becomes a work_item node
        // with edges to specs, tasks (from json columns), evidence (files).
        try {
            $workItemClass = '\\App\\Models\\AtlasProgrammingWorkItem';
            if (class_exists($workItemClass)) {
                $query = $workItemClass::query()->orderBy('id', 'desc')->limit($limit);
                if ($workspace !== '') {
                    $query->where('workspace', $workspace);
                }
                foreach ($query->get() as $wi) {
                    $wiId = 'wi_'.(string) ($wi->code ?? $wi->id ?? 'unknown');
                    $nodes[] = [
                        'id' => $wiId,
                        'kind' => self::NODE_KIND_WORK_ITEM,
                        'label' => (string) ($wi->title ?? $wi->code ?? 'work_item'),
                        'status' => (string) ($wi->status ?? 'unknown'),
                    ];
                    $byKind[self::NODE_KIND_WORK_ITEM]++;

                    // Spec child (if compiled_spec exists)
                    $spec = $wi->compiled_spec_json ?? $wi->compiled_spec ?? null;
                    if ($spec) {
                        $spId = 'sp_'.$wiId;
                        $nodes[] = [
                            'id' => $spId,
                            'kind' => self::NODE_KIND_SPEC,
                            'label' => 'compact_sdd',
                            'status' => 'compiled',
                        ];
                        $byKind[self::NODE_KIND_SPEC]++;
                        $edges[] = ['from' => $wiId, 'to' => $spId, 'kind' => self::EDGE_HAS_SPEC];
                    }

                    // Tasks (from tasks_json column if available)
                    $tasks = is_array($wi->tasks_json ?? null) ? $wi->tasks_json : [];
                    foreach ($tasks as $i => $task) {
                        if (! is_array($task)) {
                            continue;
                        }
                        $tkId = 'tk_'.$wiId.'_'.$i;
                        $nodes[] = [
                            'id' => $tkId,
                            'kind' => self::NODE_KIND_TASK,
                            'label' => (string) ($task['title'] ?? $task['id'] ?? "task_{$i}"),
                            'status' => (string) ($task['status'] ?? 'unknown'),
                        ];
                        $byKind[self::NODE_KIND_TASK]++;
                        $edges[] = ['from' => $spId ?? $wiId, 'to' => $tkId, 'kind' => self::EDGE_CONTAINS_TASK];

                        // Files touched
                        foreach ((array) ($task['files'] ?? []) as $f) {
                            $fiId = 'fi_'.substr(hash('sha256', (string) $f), 0, 10);
                            if (! $this->nodeExists($nodes, $fiId)) {
                                $nodes[] = [
                                    'id' => $fiId,
                                    'kind' => self::NODE_KIND_FILE,
                                    'label' => (string) $f,
                                ];
                                $byKind[self::NODE_KIND_FILE]++;
                            }
                            $edges[] = ['from' => $tkId, 'to' => $fiId, 'kind' => self::EDGE_TOUCHES];
                        }
                    }

                    // Evidence refs
                    $evRefs = is_array($wi->evidence_refs_json ?? null) ? $wi->evidence_refs_json : [];
                    foreach ($evRefs as $i => $ref) {
                        if (! is_array($ref)) {
                            continue;
                        }
                        $evId = 'ev_'.$wiId.'_'.$i;
                        $nodes[] = [
                            'id' => $evId,
                            'kind' => self::NODE_KIND_EVIDENCE,
                            'label' => (string) ($ref['kind'] ?? "evidence_{$i}"),
                            'status' => (string) ($ref['status'] ?? 'unknown'),
                        ];
                        $byKind[self::NODE_KIND_EVIDENCE]++;
                        // Edge evidence → first touched file if available
                        foreach ((array) ($ref['files'] ?? []) as $f) {
                            $fiId = 'fi_'.substr(hash('sha256', (string) $f), 0, 10);
                            if ($this->nodeExists($nodes, $fiId)) {
                                $edges[] = ['from' => $fiId, 'to' => $evId, 'kind' => self::EDGE_EVIDENCED_BY];
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Defensive degradation — DB tables may not exist in test envs.
            // The empty graph is honest; the snapshot still publishes with zero counts.
        }

        // Read SDD drift reports if available
        try {
            $driftClass = '\\App\\Models\\AtlasSddDriftReport';
            if (class_exists($driftClass)) {
                foreach ($driftClass::query()->orderBy('id', 'desc')->limit($limit)->get() as $dr) {
                    $drId = 'dr_'.(string) ($dr->id ?? 'unknown');
                    $nodes[] = [
                        'id' => $drId,
                        'kind' => self::NODE_KIND_DRIFT,
                        'label' => (string) ($dr->summary ?? "drift_{$dr->id}"),
                        'severity' => (string) ($dr->severity ?? 'unknown'),
                    ];
                    $byKind[self::NODE_KIND_DRIFT]++;

                    $relatedSpecId = $dr->spec_id ?? null;
                    if ($relatedSpecId !== null) {
                        $spId = 'sp_'.(string) $relatedSpecId;
                        if ($this->nodeExists($nodes, $spId)) {
                            $edges[] = ['from' => $spId, 'to' => $drId, 'kind' => self::EDGE_HAS_DRIFT];
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Defensive degradation
        }

        $generatedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
        $snapshotId = 'carto_'.substr(hash('sha256', $generatedAt.'|'.count($nodes).'|'.count($edges)), 0, 12);

        $snapshot = [
            'schema_version' => self::SCHEMA_VERSION,
            'snapshot_id' => $snapshotId,
            'generated_at' => $generatedAt,
            'scope' => [
                'workspace' => $workspace !== '' ? $workspace : null,
                'limit' => $limit,
            ],
            'nodes' => $nodes,
            'edges' => $edges,
            'stats' => [
                'node_count' => count($nodes),
                'edge_count' => count($edges),
                'by_kind' => $byKind,
            ],
            'claim_policy' => [
                'benchmark_claim_allowed' => false,
                'rivals_claim_allowed' => false,
                'superiority_claim_allowed' => false,
                'aggregate_winner_claim_allowed' => false,
            ],
        ];
        $snapshot['snapshot_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => self::SCHEMA_VERSION,
            'node_count' => count($nodes),
            'edge_count' => count($edges),
            'by_kind' => $byKind,
            'workspace' => $workspace,
        ], JSON_THROW_ON_ERROR));

        AppendOnlyJsonlStore::append($this->snapshotsLogPath(), $snapshot);

        return $snapshot;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listSnapshots(): array
    {
        return AppendOnlyJsonlStore::read($this->snapshotsLogPath());
    }

    public function latestSnapshot(): ?array
    {
        $list = $this->listSnapshots();

        return $list === [] ? null : $list[count($list) - 1];
    }

    // ---------- internals ----------

    /**
     * @param  list<array<string,mixed>>  $nodes
     */
    private function nodeExists(array $nodes, string $id): bool
    {
        foreach ($nodes as $n) {
            if (($n['id'] ?? null) === $id) {
                return true;
            }
        }

        return false;
    }
}
