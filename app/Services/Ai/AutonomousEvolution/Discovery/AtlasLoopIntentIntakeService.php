<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * LOOP-OS · FASE 5 · SLICE 14.5 — the operator's NL WRITE port ("descreve um pedido → entra numa LISTA").
 *
 * The read side is live ({@see AtlasLoopBacklogIntentSource} reads storage/app/atlas/loop/backlog-intents.json
 * → {@see AtlasLoopTargetDiscoveryService}), but there was no NL write side. This translates a free-text want
 * into a VALIDATED {path, objective, priority} manifest row, append-safe, with a Decision Receipt — the
 * literal "describe a request → it lands in a list the loop grinds." Deterministic (no model): it extracts a
 * real, existing target path from the text when one is named, validates it against the repo, and records the
 * want either way (a pathless want is recorded as a general objective for downstream resolution, never lost).
 */
final class AtlasLoopIntentIntakeService
{
    public const SCHEMA_VERSION = 'atlas.loop.intent_intake.v1';

    /** Mirrors AtlasLoopBacklogIntentSource::MANIFEST_REL (relative to storage_path). */
    public const MANIFEST_REL = 'app/atlas/loop/backlog-intents.json';

    public function __construct(private readonly ?string $manifestPathOverride = null) {}

    /**
     * Record a free-text want as a validated manifest row the loop will discover next cycle.
     *
     * @return array{schema_version:string, ok:bool, reason:?string, item:?array{path:string,objective:string,priority:float,source:string}, resolved_path:bool, total_items:int, recorded_at:string}
     */
    public function want(string $naturalLanguage, string $repoRoot, ?float $priority = null): array
    {
        $objective = trim($naturalLanguage);
        $base = [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => false,
            'reason' => null,
            'item' => null,
            'resolved_path' => false,
            'total_items' => 0,
            'recorded_at' => now()->toIso8601String(),
        ];
        if ($objective === '') {
            return array_merge($base, ['reason' => 'empty_want (describe what you want)']);
        }

        $path = $this->extractExistingPath($objective, $repoRoot);
        $priority = max(0.0, min(1.0, $priority ?? 0.5));
        $item = ['path' => $path ?? '', 'objective' => $objective, 'priority' => $priority, 'source' => 'operator_nl_want'];

        $items = $this->readItems();
        $items[] = $item;
        $this->writeItems($items);

        return array_merge($base, [
            'ok' => true,
            'item' => $item,
            'resolved_path' => $path !== null,
            'total_items' => count($items),
            'reason' => $path === null ? 'recorded_without_resolved_path (general want — downstream resolves the target)' : null,
        ]);
    }

    /** Extract the FIRST repo-relative path mentioned in the text that actually EXISTS (validated, never invented). */
    private function extractExistingPath(string $text, string $repoRoot): ?string
    {
        $repoRoot = rtrim($repoRoot, '/');
        if (preg_match_all('#[A-Za-z0-9_./\\\\-]+\.php\b#', $text, $m) > 0) {
            foreach ($m[0] as $candidate) {
                $rel = ltrim(str_replace('\\', '/', $candidate), '/');
                if (is_file($repoRoot.'/'.$rel)) {
                    return $rel;
                }
            }
        }

        return null;
    }

    /** @return list<array<string,mixed>> */
    private function readItems(): array
    {
        $path = $this->manifestPath();
        if (! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded['items'] ?? null) ? array_values($decoded['items']) : [];
    }

    /** @param list<array<string,mixed>> $items */
    private function writeItems(array $items): void
    {
        $path = $this->manifestPath();
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
        file_put_contents($path, json_encode(['items' => $items], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function manifestPath(): string
    {
        return $this->manifestPathOverride ?? storage_path(self::MANIFEST_REL);
    }
}
