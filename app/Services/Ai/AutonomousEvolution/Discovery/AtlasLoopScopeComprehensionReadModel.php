<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use Throwable;

/**
 * PART 2 · B1 (P1-B) — the persistent comprehension READ-MODEL: ONE JSON blob per `snapshotId`, NOT 16
 * normalized columns. It serializes the model's deterministic {@see AtlasLoopScopeComprehensionModel::toArray}
 * and rehydrates it byte-identically via {@see AtlasLoopScopeComprehensionModel::fromArray} — so a Part-2
 * consumer can read the model across refills / as a time series WITHOUT re-running the build.
 *
 * HONEST LIMITATION (per the architecture): this does NOT make the next build cheap — edges are always a full
 * re-grep at refresh, so freshness is owned by {@see AtlasLoopScopeComprehensionQuery::staleness}, not by a
 * stale cache hit. The read-model is a durable record of proven facts (write-through on build), never a
 * substitute for re-grounding when the scope drifts. Zero migration: one local JSON file per snapshot.
 */
final class AtlasLoopScopeComprehensionReadModel
{
    public const SCHEMA = 'atlas.loop.scope_comprehension_read_model.v1';

    private ?string $rootOverride = null;

    public function setRootForTesting(?string $path): void
    {
        $this->rootOverride = $path;
    }

    public function root(): string
    {
        return rtrim($this->rootOverride ?? storage_path('app/atlas/loop/comprehension'), '/');
    }

    /**
     * Write-through persist the model as a single JSON blob keyed by snapshotId. Fail-open (a failed write
     * never breaks a build/refill — the model is still returned in-memory).
     *
     * @return array{persisted:bool, snapshot_id:string, path:string}
     */
    public function put(AtlasLoopScopeComprehensionModel $model, string $scopeRoot, ?int $builtAtUnix = null): array
    {
        $snapshotId = $model->snapshotId;
        $path = $this->pathFor($snapshotId);
        $record = [
            'schema' => self::SCHEMA,
            'scope_root' => trim(str_replace('\\', '/', $scopeRoot), '/'),
            'snapshot_id' => $snapshotId,
            'built_at_unix' => $builtAtUnix ?? time(),
            'model_json' => $model->toArray(),
        ];
        $ok = false;
        try {
            if (! is_dir($this->root())) {
                @mkdir($this->root(), 0775, true);
            }
            $ok = @file_put_contents($path, json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) !== false;
        } catch (Throwable) {
            $ok = false;
        }

        return ['persisted' => $ok, 'snapshot_id' => $snapshotId, 'path' => $path];
    }

    /** Rehydrate the model persisted under a snapshotId, or null if absent/unreadable. */
    public function get(string $snapshotId): ?AtlasLoopScopeComprehensionModel
    {
        $record = $this->readRecord($this->pathFor($snapshotId));

        return $record === null ? null : AtlasLoopScopeComprehensionModel::fromArray((array) ($record['model_json'] ?? []));
    }

    /** The most recently built persisted model for a scope root (by built_at), or null. */
    public function latestFor(string $scopeRoot): ?AtlasLoopScopeComprehensionModel
    {
        $scope = trim(str_replace('\\', '/', $scopeRoot), '/');
        $best = null;
        $bestAt = -1;
        foreach (glob($this->root().'/*.json') ?: [] as $file) {
            $record = $this->readRecord($file);
            if ($record === null || (string) ($record['scope_root'] ?? '') !== $scope) {
                continue;
            }
            $at = (int) ($record['built_at_unix'] ?? 0);
            if ($at >= $bestAt) {
                $bestAt = $at;
                $best = AtlasLoopScopeComprehensionModel::fromArray((array) ($record['model_json'] ?? []));
            }
        }

        return $best;
    }

    private function pathFor(string $snapshotId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $snapshotId) ?? $snapshotId;

        return $this->root().'/'.substr($safe, 0, 128).'.json';
    }

    /** @return array<string, mixed>|null */
    private function readRecord(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }
        try {
            $decoded = json_decode((string) @file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
