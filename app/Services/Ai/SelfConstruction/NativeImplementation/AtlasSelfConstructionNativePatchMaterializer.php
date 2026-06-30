<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\NativeImplementation;

/**
 * Pure VIRTUAL materializer. Turns a patch plan into proposed file contents + a deterministic unified
 * diff IN MEMORY only. NEVER writes to the filesystem, NEVER spawns a process, NEVER touches git.
 *
 * Validates that every output path is inside the packet's allowed_files allow-list.
 * The same input ALWAYS produces the same diff bytes (no timestamps, no random seeds).
 *
 * Patch plan shape:
 *   {
 *     allowed_files: list<string>,
 *     patches: list<{
 *       path: string,
 *       mode: 'create'|'modify',
 *       previous?: string,    // virtual previous content for 'modify'
 *       next: string,         // proposed new content
 *     }>
 *   }
 *
 * Output:
 *   {schema_version, files:list<{path, contents}>, diffs:list<{path, unified_diff}>,
 *    blockers:list<string>, accepted:bool}
 */
final class AtlasSelfConstructionNativePatchMaterializer
{
    public const SCHEMA = 'atlas.native_implementation.patch_materializer.v1';

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    public function materialize(array $plan): array
    {
        $allowed = array_values((array) ($plan['allowed_files'] ?? []));
        $patches = array_values((array) ($plan['patches'] ?? []));
        $files = [];
        $diffs = [];
        $blockers = [];
        $seenPaths = [];

        if ($allowed === []) {
            $blockers[] = 'allowed_files_empty';

            return $this->envelope([], [], $blockers);
        }

        foreach ($patches as $patch) {
            if (! is_array($patch)) {
                continue;
            }
            $path = (string) ($patch['path'] ?? '');
            $mode = (string) ($patch['mode'] ?? 'create');
            $previous = (string) ($patch['previous'] ?? '');
            $next = (string) ($patch['next'] ?? '');

            if ($path === '') {
                $blockers[] = 'patch_path_missing';

                continue;
            }
            // Reject path-traversal attempts (relative or absolute escapes).
            if (str_starts_with($path, '/') || str_contains($path, '../') || $path === '..') {
                $blockers[] = 'traversal_path:'.$path;

                continue;
            }
            // Reject duplicate output paths within the same plan.
            if (isset($seenPaths[$path])) {
                $blockers[] = 'duplicate_output_path:'.$path;

                continue;
            }
            if (! in_array($path, $allowed, true)) {
                $blockers[] = 'forbidden_output_path:'.$path;

                continue;
            }
            if (! in_array($mode, ['create', 'modify'], true)) {
                $blockers[] = 'unknown_patch_mode:'.$mode;

                continue;
            }

            $seenPaths[$path] = true;
            $files[] = ['path' => $path, 'contents' => $next];
            $diffs[] = [
                'path' => $path,
                'unified_diff' => $this->renderUnifiedDiff($path, $mode === 'create' ? '' : $previous, $next),
            ];
        }

        return $this->envelope($files, $diffs, $blockers);
    }

    /**
     * Deterministic unified-diff renderer: NO timestamps, NO random ids. Same input ⇒ same bytes.
     */
    private function renderUnifiedDiff(string $path, string $previous, string $next): string
    {
        $prevLines = $previous === '' ? [] : explode("\n", rtrim($previous, "\n"));
        $nextLines = $next === '' ? [] : explode("\n", rtrim($next, "\n"));

        $out = '--- a/'.$path."\n";
        $out .= '+++ b/'.$path."\n";
        $out .= '@@ -1,'.count($prevLines).' +1,'.count($nextLines)." @@\n";
        foreach ($prevLines as $line) {
            $out .= '-'.$line."\n";
        }
        foreach ($nextLines as $line) {
            $out .= '+'.$line."\n";
        }

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $files
     * @param  list<array<string,mixed>>  $diffs
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function envelope(array $files, array $diffs, array $blockers): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'accepted' => $blockers === [],
            'files' => $files,
            'diffs' => $diffs,
            'blockers' => array_values($blockers),
        ];
    }
}
