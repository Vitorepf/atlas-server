<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * Shared append-only writer for the Loop backlog manifest.
 *
 * The manifest is intentionally just JSON on disk: operators can inspect or seed it, while
 * Loop jobs can append deduped intents without owning a new table.
 */
final class AtlasLoopBacklogManifestService
{
    public const SCHEMA_VERSION = 'atlas.loop.backlog_manifest.v1';

    public const DEFAULT_RELATIVE_PATH = 'app/atlas/loop/backlog-intents.json';

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    public function append(string $manifestPath, array $item, int $manifestLimit = 200, bool $write = true): array
    {
        $item = $this->normalizeItem($item);
        $sourceKey = (string) $item['source_key'];
        $manifest = $this->read($manifestPath);
        $items = is_array($manifest['items'] ?? null)
            ? array_values(array_filter($manifest['items'], 'is_array'))
            : [];

        foreach ($items as $existing) {
            if ($this->isDuplicate($existing, $item)) {
                return ['type' => 'backlog_intent', 'status' => 'duplicate', 'source_key' => $sourceKey, 'item' => $item];
            }
        }

        if (! $write) {
            return ['type' => 'backlog_intent', 'status' => 'dry_run', 'source_key' => $sourceKey, 'item' => $item];
        }

        $items[] = $item;
        usort($items, static fn (array $a, array $b): int => ((float) ($b['priority'] ?? 0)) <=> ((float) ($a['priority'] ?? 0)));

        $manifest['schema_version'] = self::SCHEMA_VERSION;
        $manifest['updated_at'] = Carbon::now()->toIso8601String();
        $manifest['items'] = array_slice($items, 0, max(10, $manifestLimit));

        File::ensureDirectoryExists(dirname($manifestPath));
        File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        return ['type' => 'backlog_intent', 'status' => 'enqueued', 'source_key' => $sourceKey, 'item' => $item];
    }

    /**
     * @return array<string,mixed>
     */
    public function read(string $manifestPath): array
    {
        if (! is_file($manifestPath)) {
            return ['schema_version' => self::SCHEMA_VERSION, 'items' => []];
        }

        $decoded = json_decode((string) @file_get_contents($manifestPath), true);
        if (! is_array($decoded)) {
            return ['schema_version' => self::SCHEMA_VERSION, 'items' => []];
        }
        if (is_array($decoded['items'] ?? null)) {
            return $decoded;
        }

        return ['schema_version' => self::SCHEMA_VERSION, 'items' => array_values(array_filter($decoded, 'is_array'))];
    }

    public function defaultPath(): string
    {
        return storage_path(self::DEFAULT_RELATIVE_PATH);
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    private function normalizeItem(array $item): array
    {
        $path = ltrim(trim((string) ($item['path'] ?? '')), '/');
        $objective = trim((string) ($item['objective'] ?? ''));
        $source = trim((string) ($item['source'] ?? 'auto_feed'));
        $source = $source !== '' ? $source : 'auto_feed';
        $reason = trim((string) ($item['reason'] ?? ''));
        $sourceKey = trim((string) ($item['source_key'] ?? ''));
        if ($sourceKey === '') {
            $sourceKey = hash('sha256', $source.'|'.$path.'|'.$objective.'|'.$reason);
        }

        return [
            ...$item,
            'path' => $path,
            'objective' => $objective,
            'priority' => round(max(0.0, min(1.0, (float) ($item['priority'] ?? 0.7))), 4),
            'source' => $source,
            'source_key' => $sourceKey,
        ];
    }

    /**
     * @param  array<string,mixed>  $existing
     * @param  array<string,mixed>  $item
     */
    private function isDuplicate(array $existing, array $item): bool
    {
        if (($existing['source_key'] ?? null) === $item['source_key']) {
            return true;
        }

        if (($existing['source'] ?? null) === $item['source']
            && ($existing['path'] ?? null) === $item['path']
            && trim((string) ($item['reason'] ?? '')) !== ''
            && ($existing['reason'] ?? null) === ($item['reason'] ?? null)) {
            return true;
        }

        return ($existing['source'] ?? null) === $item['source']
            && ($existing['path'] ?? null) === $item['path']
            && ($existing['objective'] ?? null) === $item['objective'];
    }
}
