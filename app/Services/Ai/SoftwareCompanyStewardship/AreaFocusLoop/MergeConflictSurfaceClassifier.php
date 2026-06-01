<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

final class MergeConflictSurfaceClassifier
{
    private const SCHEMA_VERSION = 'atlas.loop.merge_conflict_surface.v1';

    /** Higher rank wins for worst_severity and the dominant-by-severity tie-break. */
    private const SEVERITY_RANK = [
        'modify_delete' => 3,
        'add_add' => 2,
        'rename' => 2,
        'content' => 1,
        'unknown' => 0,
    ];

    /**
     * @param  array{merge_tree_output?: string, changed_files?: list<string>}  $input
     * @return array{schema_version: string, conflict_file_count: int, conflict_paths: list<string>, conflict_kind: string, worst_severity: string, governance_path_in_conflict: bool}
     */
    public function classify(array $input): array
    {
        $output = is_string($input['merge_tree_output'] ?? null) ? $input['merge_tree_output'] : '';

        $paths = [];
        $kinds = [];
        foreach (preg_split('/\r\n|\r|\n/', $output) ?: [] as $line) {
            if (! preg_match('/^\s*CONFLICT\s*\(([^)]+)\)\s*:\s*(.+?)\s*$/', $line, $match)) {
                continue;
            }
            $path = $this->extractPath($match[2]);
            if ($path === '') {
                continue;
            }
            $paths[$path] = true;
            $kinds[] = $this->mapKind($match[1]);
        }

        $uniquePaths = array_keys($paths);
        sort($uniquePaths, SORT_STRING);

        $governanceInConflict = array_any(
            $uniquePaths,
            static fn (string $path): bool => str_starts_with($path, '.atlas/') || $path === '.atlas',
        );

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'conflict_file_count' => count($uniquePaths),
            'conflict_paths' => array_values($uniquePaths),
            'conflict_kind' => $this->resolveKind($kinds),
            'worst_severity' => $this->resolveSeverity($kinds, $governanceInConflict),
            'governance_path_in_conflict' => $governanceInConflict,
        ];
    }

    private function mapKind(string $token): string
    {
        $token = strtolower(trim($token));

        return match (true) {
            str_starts_with($token, 'content') => 'content',
            str_starts_with($token, 'add/add') => 'add_add',
            str_starts_with($token, 'modify/delete') => 'modify_delete',
            str_starts_with($token, 'rename') => 'rename',
            default => 'unknown',
        };
    }

    private function extractPath(string $detail): string
    {
        if (preg_match('/Merge conflict in (.+)$/', $detail, $m)) {
            $detail = $m[1];
        } elseif (preg_match('/^(\S+) (?:deleted|renamed|added|modified|is) /', $detail, $m)) {
            $detail = $m[1];
        }
        $detail = trim($detail);

        return str_starts_with($detail, './') ? substr($detail, 2) : $detail;
    }

    /** @param  list<string>  $kinds */
    private function resolveKind(array $kinds): string
    {
        if ($kinds === []) {
            return 'unknown';
        }
        $distinct = array_values(array_unique($kinds));
        if (count($distinct) === 1) {
            return $distinct[0];
        }
        usort($distinct, fn (string $a, string $b): int => (self::SEVERITY_RANK[$b] ?? 0) <=> (self::SEVERITY_RANK[$a] ?? 0));

        return $distinct[0];
    }

    /** @param  list<string>  $kinds */
    private function resolveSeverity(array $kinds, bool $governanceInConflict): string
    {
        if ($governanceInConflict || in_array('modify_delete', $kinds, true)) {
            return 'high';
        }

        return (in_array('add_add', $kinds, true) || in_array('rename', $kinds, true)) ? 'medium' : 'low';
    }
}
