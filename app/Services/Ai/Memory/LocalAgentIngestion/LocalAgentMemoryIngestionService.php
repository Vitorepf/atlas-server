<?php

namespace App\Services\Ai\Memory\LocalAgentIngestion;

use App\Models\AiLocalAgentIngestionCandidate;
use App\Models\AiLocalAgentIngestionRun;
use App\Models\AiLocalAgentIngestionSource;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Orchestrator for `atlas.ai.local_agent_ingestion.*.v1`.
 *
 * Single entry point: {@see run()}. The orchestrator walks each configured
 * root, redacts secrets, classifies sources, deduplicates by content hash,
 * emits quarantined memory candidates and produces a stable receipt.
 *
 * Why one class: the pipeline is short and the steps share state (file
 * hashes, finding counters). Splitting into a fluent stage class would add
 * abstraction without buying anything testable. Each sub-concern is already
 * its own service ({@see LocalAgentSourceDiscoveryService},
 * {@see LocalAgentSecretScanner}, {@see LocalAgentSourceClassifier}); the
 * orchestrator just composes them.
 *
 * Safety invariants enforced here:
 *  - never reads a file outside an enabled root;
 *  - never persists raw file content; only hashes + redacted snippets +
 *    classification signals leave the in-memory pipeline;
 *  - candidates from `sensitive_secret` sources are auto-rejected — they
 *    cannot be promoted by any downstream caller because their status is
 *    `rejected` from birth;
 *  - dry-run mode never writes to the DB; the receipt is returned identical
 *    in shape but no Eloquent records exist after the call returns.
 */
class LocalAgentMemoryIngestionService
{
    public function __construct(
        private readonly LocalAgentSourceDiscoveryService $discovery,
        private readonly LocalAgentSecretScanner $secretScanner,
        private readonly LocalAgentSourceClassifier $classifier,
    ) {}

    /**
     * @param  array{dry_run?:bool,roots?:array<int,string>,config_override?:array<string,mixed>,actor_alias?:string}  $options
     * @return array{run:array<string,mixed>,sources:list<array<string,mixed>>,candidates:list<array<string,mixed>>,receipt:array<string,mixed>}
     */
    public function run(array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? (bool) config('atlas_local_agent_ingestion.dry_run_default', true));
        $config = $this->resolveConfig($options['config_override'] ?? []);
        $rootsAll = $this->resolveRoots($config['roots'] ?? [], $options['roots'] ?? null);

        $runUuid = (string) Str::uuid();
        $runStartedAt = Carbon::now();
        $actorAlias = (string) ($options['actor_alias'] ?? 'operator');

        $sources = [];
        $candidates = [];
        $contentHashSeen = [];
        $skippedCounts = [];

        if ($rootsAll === []) {
            return $this->finaliseRun(
                runUuid: $runUuid,
                runStartedAt: $runStartedAt,
                dryRun: $dryRun,
                config: $config,
                roots: [],
                rootsConsidered: 0,
                sources: [],
                candidates: [],
                actorAlias: $actorAlias,
                noRootsConfigured: true,
            );
        }

        foreach ($rootsAll as $root) {
            $alias = (string) ($root['alias'] ?? '');
            if (! (bool) ($root['enabled'] ?? true)) {
                continue;
            }
            if ($alias === '') {
                continue;
            }
            $rootPath = (string) ($root['path'] ?? '');
            if ($rootPath === '' || ! is_dir($rootPath)) {
                continue;
            }

            $discovered = $this->discovery->walk($root, $config);

            foreach ($discovered as $descriptor) {
                $skipReason = $descriptor['skip_reason'] ?? null;
                if ($skipReason !== null) {
                    $skippedCounts[$skipReason] = ($skippedCounts[$skipReason] ?? 0) + 1;
                    $sources[] = $this->buildSkippedSource($runUuid, $descriptor);

                    continue;
                }

                $absolute = (string) $descriptor['absolute_path'];
                $rawContent = @file_get_contents($absolute);
                if ($rawContent === false) {
                    $skippedCounts[LocalAgentMemoryIngestionCanon::SKIP_UNREADABLE] = ($skippedCounts[LocalAgentMemoryIngestionCanon::SKIP_UNREADABLE] ?? 0) + 1;
                    $sources[] = $this->buildSkippedSource($runUuid, [
                        ...$descriptor,
                        'skip_reason' => LocalAgentMemoryIngestionCanon::SKIP_UNREADABLE,
                    ]);

                    continue;
                }

                if ($this->isBinary($rawContent)) {
                    $skippedCounts[LocalAgentMemoryIngestionCanon::SKIP_BINARY_DETECTED] = ($skippedCounts[LocalAgentMemoryIngestionCanon::SKIP_BINARY_DETECTED] ?? 0) + 1;
                    $sources[] = $this->buildSkippedSource($runUuid, [
                        ...$descriptor,
                        'skip_reason' => LocalAgentMemoryIngestionCanon::SKIP_BINARY_DETECTED,
                    ]);

                    continue;
                }

                $scan = $this->secretScanner->scanAndRedact($rawContent);
                $redacted = (string) $scan['redacted'];
                $findings = $scan['findings'];
                $findingCounts = $scan['counts'];

                $contentHash = hash('sha256', $redacted);
                $dedupKey = $alias.'::'.($descriptor['relative_path'] ?? '').'::'.$contentHash;
                if (isset($contentHashSeen[$dedupKey])) {
                    $skippedCounts[LocalAgentMemoryIngestionCanon::SKIP_DUPLICATE] = ($skippedCounts[LocalAgentMemoryIngestionCanon::SKIP_DUPLICATE] ?? 0) + 1;
                    $sources[] = $this->buildDuplicateSource($runUuid, $descriptor, $contentHash);

                    continue;
                }
                $contentHashSeen[$dedupKey] = true;

                $classification = $this->classifier->classify(
                    (string) ($descriptor['relative_path'] ?? ''),
                    $redacted,
                );

                $isSecretClass = $classification['source_class'] === LocalAgentMemoryIngestionCanon::CLASS_SENSITIVE_SECRET;
                $sourceUuid = (string) Str::uuid();
                $sourceRow = [
                    'uuid' => $sourceUuid,
                    'run_uuid' => $runUuid,
                    'schema_version' => LocalAgentMemoryIngestionCanon::SOURCE_SCHEMA_VERSION,
                    'alias' => $alias,
                    'relative_path' => (string) ($descriptor['relative_path'] ?? ''),
                    'extension' => $descriptor['extension'] ?? null,
                    'size_bytes' => (int) ($descriptor['size_bytes'] ?? 0),
                    'mtime' => isset($descriptor['mtime']) ? Carbon::createFromTimestamp((int) $descriptor['mtime'])->toISOString() : null,
                    'content_hash' => $contentHash,
                    'redacted_snippet' => Str::limit($redacted, 1024, '…'),
                    'source_class' => $classification['source_class'],
                    'classification_signals' => $classification['signals'],
                    'quality_score' => $classification['quality_score'],
                    'freshness_score' => $this->freshnessScore($descriptor['mtime'] ?? null),
                    'secret_finding_count' => count($findings),
                    'secret_finding_counts' => $findingCounts,
                    'status' => $isSecretClass
                        ? LocalAgentMemoryIngestionCanon::SOURCE_STATUS_QUARANTINED
                        : LocalAgentMemoryIngestionCanon::SOURCE_STATUS_INGESTED,
                    'skip_reason' => null,
                ];
                $sourceRow['source_hash'] = MissionCanonicalHash::sha256([
                    'schema' => LocalAgentMemoryIngestionCanon::SOURCE_SCHEMA_VERSION,
                    'run_uuid' => $runUuid,
                    'alias' => $alias,
                    'relative_path' => $sourceRow['relative_path'],
                    'content_hash' => $contentHash,
                    'source_class' => $sourceRow['source_class'],
                    'secret_finding_count' => $sourceRow['secret_finding_count'],
                ]);
                $sources[] = $sourceRow;

                $candidateRow = $this->buildCandidate($runUuid, $sourceRow);
                $candidates[] = $candidateRow;
            }
        }

        return $this->finaliseRun(
            runUuid: $runUuid,
            runStartedAt: $runStartedAt,
            dryRun: $dryRun,
            config: $config,
            roots: $rootsAll,
            rootsConsidered: count($rootsAll),
            sources: $sources,
            candidates: $candidates,
            actorAlias: $actorAlias,
            noRootsConfigured: false,
            skippedCounts: $skippedCounts,
        );
    }

    /**
     * @param  array<string,mixed>  $override
     * @return array<string,mixed>
     */
    private function resolveConfig(array $override): array
    {
        $base = (array) config('atlas_local_agent_ingestion', []);

        return array_merge($base, $override);
    }

    /**
     * @param  array<int,array<string,mixed>>  $configuredRoots
     * @param  array<int,string>|null  $aliasFilter
     * @return array<int,array<string,mixed>>
     */
    private function resolveRoots(array $configuredRoots, ?array $aliasFilter): array
    {
        $filter = $aliasFilter === null ? null : array_values(array_filter($aliasFilter, 'is_string'));
        if ($filter === []) {
            $filter = null;
        }

        $out = [];
        foreach ($configuredRoots as $root) {
            if (! is_array($root)) {
                continue;
            }
            $alias = (string) ($root['alias'] ?? '');
            if ($alias === '') {
                continue;
            }
            if ($filter !== null && ! in_array($alias, $filter, true)) {
                continue;
            }
            $out[] = [
                'alias' => $alias,
                'path' => (string) ($root['path'] ?? ''),
                'enabled' => (bool) ($root['enabled'] ?? true),
            ];
        }

        return $out;
    }

    private function isBinary(string $head): bool
    {
        $sample = substr($head, 0, 8192);

        return str_contains($sample, "\0");
    }

    /**
     * @return array<string,mixed>
     */
    private function buildSkippedSource(string $runUuid, array $descriptor): array
    {
        $alias = (string) ($descriptor['alias'] ?? '');
        $relativePath = (string) ($descriptor['relative_path'] ?? '');
        $skipReason = (string) ($descriptor['skip_reason'] ?? 'unknown');

        $row = [
            'uuid' => (string) Str::uuid(),
            'run_uuid' => $runUuid,
            'schema_version' => LocalAgentMemoryIngestionCanon::SOURCE_SCHEMA_VERSION,
            'alias' => $alias,
            'relative_path' => $relativePath,
            'extension' => $descriptor['extension'] ?? null,
            'size_bytes' => (int) ($descriptor['size_bytes'] ?? 0),
            'mtime' => null,
            'content_hash' => null,
            'redacted_snippet' => null,
            'source_class' => LocalAgentMemoryIngestionCanon::CLASS_UNCLASSIFIED,
            'classification_signals' => [],
            'quality_score' => 0,
            'freshness_score' => 0,
            'secret_finding_count' => 0,
            'secret_finding_counts' => [],
            'status' => LocalAgentMemoryIngestionCanon::SOURCE_STATUS_SKIPPED,
            'skip_reason' => $skipReason,
        ];
        $row['source_hash'] = MissionCanonicalHash::sha256([
            'schema' => LocalAgentMemoryIngestionCanon::SOURCE_SCHEMA_VERSION,
            'run_uuid' => $runUuid,
            'alias' => $alias,
            'relative_path' => $relativePath,
            'skip_reason' => $skipReason,
        ]);

        return $row;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildDuplicateSource(string $runUuid, array $descriptor, string $contentHash): array
    {
        $row = $this->buildSkippedSource($runUuid, [
            ...$descriptor,
            'skip_reason' => LocalAgentMemoryIngestionCanon::SKIP_DUPLICATE,
        ]);
        $row['status'] = LocalAgentMemoryIngestionCanon::SOURCE_STATUS_DUPLICATE;
        $row['content_hash'] = $contentHash;

        return $row;
    }

    /**
     * @param  array<string,mixed>  $source
     * @return array<string,mixed>
     */
    private function buildCandidate(string $runUuid, array $source): array
    {
        $isSecret = $source['source_class'] === LocalAgentMemoryIngestionCanon::CLASS_SENSITIVE_SECRET;
        $status = $isSecret
            ? LocalAgentMemoryIngestionCanon::CANDIDATE_STATUS_REJECTED
            : LocalAgentMemoryIngestionCanon::CANDIDATE_STATUS_QUARANTINED;

        $evidenceRefs = [
            ['kind' => 'source', 'source_hash' => $source['source_hash'], 'content_hash' => $source['content_hash']],
        ];
        if (($source['secret_finding_count'] ?? 0) > 0) {
            $evidenceRefs[] = ['kind' => 'secret_scan', 'finding_count' => $source['secret_finding_count']];
        }

        $payload = [
            'schema_version' => LocalAgentMemoryIngestionCanon::CANDIDATE_SCHEMA_VERSION,
            'source_class' => $source['source_class'],
            'classification_signals' => array_values((array) $source['classification_signals']),
            'quality_score' => (int) $source['quality_score'],
            'freshness_score' => (int) $source['freshness_score'],
            'promotion_blocked_reason' => $isSecret ? 'sensitive_secret_class' : null,
        ];
        $candidateHash = MissionCanonicalHash::sha256([
            'schema' => LocalAgentMemoryIngestionCanon::CANDIDATE_SCHEMA_VERSION,
            'run_uuid' => $runUuid,
            'source_hash' => $source['source_hash'],
            'status' => $status,
            'payload' => $payload,
        ]);

        return [
            'uuid' => (string) Str::uuid(),
            'run_uuid' => $runUuid,
            'source_uuid' => $source['uuid'],
            'schema_version' => LocalAgentMemoryIngestionCanon::CANDIDATE_SCHEMA_VERSION,
            'source_class' => $source['source_class'],
            'status' => $status,
            'memory_eligible' => false,
            'context_eligible' => false,
            'embedding_allowed' => false,
            'promotion_target' => 'memory_candidate',
            'evidence_refs' => $evidenceRefs,
            'payload' => $payload,
            'candidate_hash' => $candidateHash,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $sources
     * @param  list<array<string,mixed>>  $candidates
     * @param  array<int,array<string,mixed>>  $roots
     * @param  array<string,int>  $skippedCounts
     * @return array{run:array<string,mixed>,sources:list<array<string,mixed>>,candidates:list<array<string,mixed>>,receipt:array<string,mixed>}
     */
    private function finaliseRun(
        string $runUuid,
        Carbon $runStartedAt,
        bool $dryRun,
        array $config,
        array $roots,
        int $rootsConsidered,
        array $sources,
        array $candidates,
        string $actorAlias,
        bool $noRootsConfigured,
        array $skippedCounts = [],
    ): array {
        $endedAt = Carbon::now();
        $status = $dryRun
            ? LocalAgentMemoryIngestionCanon::RUN_STATUS_DRY_RUN
            : LocalAgentMemoryIngestionCanon::RUN_STATUS_COMPLETED;
        $aliases = array_values(array_unique(array_map(
            static fn (array $r): string => (string) ($r['alias'] ?? ''),
            $roots,
        )));
        $rootAliasFingerprint = MissionCanonicalHash::sha256(['aliases' => $aliases]);

        $secretFindingTotal = array_sum(array_map(
            static fn (array $s): int => (int) ($s['secret_finding_count'] ?? 0),
            $sources,
        ));
        $classCounts = [];
        foreach ($sources as $s) {
            $cls = (string) ($s['source_class'] ?? LocalAgentMemoryIngestionCanon::CLASS_UNCLASSIFIED);
            $classCounts[$cls] = ($classCounts[$cls] ?? 0) + 1;
        }

        $receipt = [
            'schema' => LocalAgentMemoryIngestionCanon::RECEIPT_SCHEMA_VERSION,
            'run_uuid' => $runUuid,
            'status' => $status,
            'dry_run' => $dryRun,
            'actor_alias' => $actorAlias,
            'no_roots_configured' => $noRootsConfigured,
            'started_at' => $runStartedAt->toISOString(),
            'ended_at' => $endedAt->toISOString(),
            'roots_considered' => $rootsConsidered,
            'root_aliases' => $aliases,
            'root_alias_fingerprint' => $rootAliasFingerprint,
            'discovered_count' => count($sources),
            'ingested_count' => count(array_filter(
                $sources,
                static fn (array $s): bool => ($s['status'] ?? null) === LocalAgentMemoryIngestionCanon::SOURCE_STATUS_INGESTED,
            )),
            'skipped_count' => count(array_filter(
                $sources,
                static fn (array $s): bool => ($s['status'] ?? null) === LocalAgentMemoryIngestionCanon::SOURCE_STATUS_SKIPPED
                    || ($s['status'] ?? null) === LocalAgentMemoryIngestionCanon::SOURCE_STATUS_DUPLICATE,
            )),
            'quarantined_count' => count(array_filter(
                $sources,
                static fn (array $s): bool => ($s['status'] ?? null) === LocalAgentMemoryIngestionCanon::SOURCE_STATUS_QUARANTINED,
            )),
            'duplicate_count' => count(array_filter(
                $sources,
                static fn (array $s): bool => ($s['status'] ?? null) === LocalAgentMemoryIngestionCanon::SOURCE_STATUS_DUPLICATE,
            )),
            'skipped_reasons' => $skippedCounts,
            'secret_finding_total' => $secretFindingTotal,
            'class_counts' => $classCounts,
            'candidate_count' => count($candidates),
            'candidate_quarantined' => count(array_filter(
                $candidates,
                static fn (array $c): bool => ($c['status'] ?? null) === LocalAgentMemoryIngestionCanon::CANDIDATE_STATUS_QUARANTINED,
            )),
            'candidate_rejected' => count(array_filter(
                $candidates,
                static fn (array $c): bool => ($c['status'] ?? null) === LocalAgentMemoryIngestionCanon::CANDIDATE_STATUS_REJECTED,
            )),
        ];
        $receipt['receipt_hash'] = MissionCanonicalHash::sha256(array_diff_key($receipt, ['receipt_hash' => true]));

        $runRow = [
            'uuid' => $runUuid,
            'schema_version' => LocalAgentMemoryIngestionCanon::RUN_SCHEMA_VERSION,
            'status' => $status,
            'dry_run' => $dryRun,
            'actor_alias' => $actorAlias,
            'root_aliases' => $aliases,
            'root_alias_fingerprint' => $rootAliasFingerprint,
            'started_at' => $runStartedAt,
            'ended_at' => $endedAt,
            'config_snapshot' => [
                'max_file_bytes' => (int) ($config['max_file_bytes'] ?? 0),
                'max_files_per_run' => (int) ($config['max_files_per_run'] ?? 0),
                'allowlist_extensions' => array_values((array) ($config['allowlist_extensions'] ?? [])),
                'denylist_patterns' => array_values((array) ($config['denylist_patterns'] ?? [])),
            ],
            'summary' => $receipt,
            'receipt_hash' => $receipt['receipt_hash'],
        ];

        if (! $dryRun) {
            $this->persist($runRow, $sources, $candidates);
        }

        return [
            'run' => $runRow,
            'sources' => $sources,
            'candidates' => $candidates,
            'receipt' => $receipt,
        ];
    }

    /**
     * @param  array<string,mixed>  $runRow
     * @param  list<array<string,mixed>>  $sources
     * @param  list<array<string,mixed>>  $candidates
     */
    private function persist(array $runRow, array $sources, array $candidates): void
    {
        $run = AiLocalAgentIngestionRun::query()->create($runRow);

        foreach ($sources as $s) {
            AiLocalAgentIngestionSource::query()->create(array_merge($s, [
                'run_id' => $run->id,
            ]));
        }

        $sourceUuidToId = AiLocalAgentIngestionSource::query()
            ->where('run_uuid', $run->uuid)
            ->pluck('id', 'uuid')
            ->all();

        foreach ($candidates as $c) {
            $sourceUuid = (string) ($c['source_uuid'] ?? '');
            AiLocalAgentIngestionCandidate::query()->create(array_merge($c, [
                'run_id' => $run->id,
                'source_id' => $sourceUuidToId[$sourceUuid] ?? null,
            ]));
        }
    }

    private function freshnessScore(mixed $mtime): int
    {
        if ($mtime === null) {
            return 0;
        }
        $ts = (int) $mtime;
        if ($ts <= 0) {
            return 0;
        }
        $ageDays = max(0, (time() - $ts) / 86400);
        if ($ageDays < 1) {
            return 100;
        }
        if ($ageDays < 7) {
            return 80;
        }
        if ($ageDays < 30) {
            return 60;
        }
        if ($ageDays < 180) {
            return 30;
        }

        return 10;
    }
}
