<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Diff;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use Throwable;

/**
 * JSONL exporter for diffs produced by {@see AtlasCortexSnapshotDiffEngine}.
 *
 * Structural transitions are emitted into the caller-supplied path; prose deltas land in a sibling
 * `<path>.prose.jsonl` so the structural export is never contaminated by writable_untrusted_prose.
 *
 * Deterministic order: categories sorted alphabetically, then subjects sorted alphabetically.
 *
 * Fail-open: filesystem errors yield `exported=0` with a `error` field; the caller decides whether
 * to retry. No exceptions surface out of {@see export()} / {@see proseExport()}.
 */
final class AtlasCortexSnapshotDiffExporter
{
    public const SCHEMA = 'atlas.cortex.snapshot_diff_export.v1';

    public const PROSE_SCHEMA = 'atlas.cortex.snapshot_diff_export.prose.v1';

    /**
     * Structural transition categories (in canonical alpha order).
     *
     * @var list<string>
     */
    private const STRUCTURAL_CATEGORIES = [
        'clone_clusters.appeared',
        'clone_clusters.dissolved',
        'clone_clusters.shape_changed',
        'doc_stated_gaps.closed',
        'doc_stated_gaps.opened',
        'edges.added',
        'edges.removed',
        'forbidden.added',
        'forbidden.removed',
        'inventory.added',
        'inventory.removed',
        'inventory.shape_changed',
        'orphans.appeared',
        'orphans.resolved',
    ];

    /**
     * @param  array<string,mixed>  $diff
     * @return array<string,mixed>
     */
    public function export(array $diff, string $path, string $scopeRoot = ''): array
    {
        $left = (string) ($diff['left']['snapshot_id'] ?? '');
        $right = (string) ($diff['right']['snapshot_id'] ?? '');
        $lines = $this->buildStructuralLines($diff, $left, $right, $scopeRoot);

        $body = '';
        foreach ($lines as $line) {
            $body .= json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        }

        try {
            $ok = @file_put_contents($path, $body);
            if ($ok === false) {
                return ['exported' => 0, 'path' => $path, 'sha256' => '', 'error' => 'write_failed'];
            }
        } catch (Throwable $e) {
            return ['exported' => 0, 'path' => $path, 'sha256' => '', 'error' => 'write_exception:'.$e->getMessage()];
        }

        return [
            'exported' => count($lines),
            'path' => $path,
            'sha256' => hash('sha256', $body),
        ];
    }

    /**
     * @param  array<string,mixed>  $diff
     * @return array<string,mixed>
     */
    public function proseExport(array $diff, string $path, string $scopeRoot = ''): array
    {
        $left = (string) ($diff['left']['snapshot_id'] ?? '');
        $right = (string) ($diff['right']['snapshot_id'] ?? '');
        $prose = is_array($diff['doc_purposes_prose']['changed'] ?? null) ? $diff['doc_purposes_prose']['changed'] : [];

        $lines = [];
        foreach ($prose as $row) {
            if (! is_array($row)) {
                continue;
            }
            $lines[] = [
                'schema_version' => self::PROSE_SCHEMA,
                'provenance' => AtlasLoopScopeComprehensionModel::PROVENANCE_WRITABLE_PROSE,
                'category' => 'doc_purposes.changed',
                'subject' => (string) ($row['fqcn'] ?? ''),
                'detail' => ['was' => $row['was'] ?? null, 'now' => $row['now'] ?? null],
                'left_snapshot_id' => $left,
                'right_snapshot_id' => $right,
                'scope_root' => $scopeRoot,
            ];
        }
        usort($lines, static fn (array $a, array $b): int => strcmp((string) $a['subject'], (string) $b['subject']));

        $body = '';
        foreach ($lines as $line) {
            $body .= json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        }

        try {
            $ok = @file_put_contents($path, $body);
            if ($ok === false) {
                return ['exported' => 0, 'path' => $path, 'sha256' => '', 'error' => 'write_failed'];
            }
        } catch (Throwable $e) {
            return ['exported' => 0, 'path' => $path, 'sha256' => '', 'error' => 'write_exception:'.$e->getMessage()];
        }

        return [
            'exported' => count($lines),
            'path' => $path,
            'sha256' => hash('sha256', $body),
        ];
    }

    /**
     * @param  array<string,mixed>  $diff
     * @return list<array<string,mixed>>
     */
    private function buildStructuralLines(array $diff, string $left, string $right, string $scopeRoot): array
    {
        $lines = [];
        foreach (self::STRUCTURAL_CATEGORIES as $category) {
            [$top, $bucket] = explode('.', $category, 2);
            $payload = $diff[$top][$bucket] ?? null;
            if ($payload === null) {
                continue;
            }

            foreach ($this->iterateTransitions($category, $payload) as $entry) {
                $lines[] = [
                    'schema_version' => self::SCHEMA,
                    'category' => $category,
                    'subject' => $entry['subject'],
                    'detail' => $entry['detail'],
                    'left_snapshot_id' => $left,
                    'right_snapshot_id' => $right,
                    'scope_root' => $scopeRoot,
                ];
            }
        }

        return $lines;
    }

    /**
     * @param  mixed  $payload
     * @return list<array{subject:string, detail:array<string,mixed>}>
     */
    private function iterateTransitions(string $category, mixed $payload): array
    {
        $entries = [];

        if ($category === 'edges.added' || $category === 'edges.removed') {
            if (! is_array($payload)) {
                return [];
            }
            foreach ($payload as $relPath => $callers) {
                $callers = array_values((array) $callers);
                sort($callers, SORT_STRING);
                $entries[] = ['subject' => (string) $relPath, 'detail' => ['callers' => $callers]];
            }
            usort($entries, static fn (array $a, array $b): int => strcmp($a['subject'], $b['subject']));

            return $entries;
        }

        if ($category === 'inventory.shape_changed') {
            if (! is_array($payload)) {
                return [];
            }
            foreach ($payload as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $entries[] = [
                    'subject' => (string) ($row['fqcn'] ?? ''),
                    'detail' => ['changed_fields' => array_values((array) ($row['changed_fields'] ?? []))],
                ];
            }
            usort($entries, static fn (array $a, array $b): int => strcmp($a['subject'], $b['subject']));

            return $entries;
        }

        // Default: list<string> payload — subject is each member, detail is empty.
        if (! is_array($payload)) {
            return [];
        }
        foreach (array_values($payload) as $subject) {
            $entries[] = ['subject' => (string) $subject, 'detail' => new \stdClass()];
        }
        usort($entries, static fn (array $a, array $b): int => strcmp($a['subject'], $b['subject']));

        return $entries;
    }
}
