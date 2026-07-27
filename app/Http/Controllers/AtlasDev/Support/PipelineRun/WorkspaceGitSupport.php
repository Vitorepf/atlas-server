<?php

declare(strict_types=1);

namespace App\Http\Controllers\AtlasDev\Support\PipelineRun;

use App\Services\Ai\Programming\AtlasDev\Gate\WorktreeBaseline;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopePreExistingChange;
use Symfony\Component\Process\Process;

/**
 * Workspace git baseline/restore/diff helpers.
 *
 * Extracted verbatim from PipelineRunExecutor (godfile split, GOD-DEBULK
 * 2026-07-22). Behavior unchanged; cross-family calls route through the
 * sibling sections injected below.
 */
final class WorkspaceGitSupport
{
    /**
     * Capture the operator-owned allowed-file baseline before any provider
     * mutation. Retries restore to this snapshot, never to HEAD.
     *
     * @param  list<string>  $allowedFiles
     * @return array{
     *   scope: WorktreeBaseline,
     *   entries: array<string,array<string,mixed>>
     * }
     */
    public function captureWorkspaceBaseline(string $workspace, array $allowedFiles): array
    {
        $paths = $this->safeRelativePaths($allowedFiles);
        if (! is_dir($workspace) || $paths === []) {
            return ['scope' => WorktreeBaseline::clean(), 'entries' => []];
        }

        $status = $this->gitOutput($workspace, ['git', 'status', '--porcelain=v1', '--', ...$paths]);
        $diff = $this->gitOutput($workspace, ['git', 'diff', '--no-ext-diff', '--binary', '--', ...$paths]);
        $cachedDiff = $this->gitOutput($workspace, ['git', 'diff', '--cached', '--no-ext-diff', '--binary', '--', ...$paths]);
        $diffHash = ($diff === '' && $cachedDiff === '')
            ? null
            : hash('sha256', $diff."\0".$cachedDiff);

        $preExisting = array_map(
            static fn (string $path): ScopePreExistingChange => new ScopePreExistingChange($path, true),
            $this->pathsFromPorcelainStatus($status, $paths),
        );

        $entries = [];
        foreach ($paths as $path) {
            $entries[$path] = $this->captureWorkspaceBaselineEntry($workspace, $path);
        }

        return [
            'scope' => new WorktreeBaseline(
                gitStatusBefore: $status,
                gitDiffBeforeHash: $diffHash,
                preExistingChanges: $preExisting,
            ),
            'entries' => $entries,
        ];
    }

    /**
     * M2/M4: restore allowed files to the captured operator baseline before a
     * retry/reapply. Returns a refusal reason instead of silently wiping when
     * the current path shape is ambiguous.
     *
     * @param  list<string>  $allowedFiles
     * @param  array{entries?: array<string,array<string,mixed>>}  $baseline
     */
    public function revertWorkspaceChanges(string $workspace, array $allowedFiles, array $baseline): ?string
    {
        if (! is_dir($workspace)) {
            return null;
        }

        $paths = $this->safeRelativePaths($allowedFiles);
        if ($paths === []) {
            return null;
        }

        $entries = $baseline['entries'] ?? null;
        if (! is_array($entries)) {
            return 'baseline_missing';
        }

        foreach ($paths as $path) {
            $entry = $entries[$path] ?? ['kind' => 'missing'];
            $error = $this->restoreWorkspaceBaselineEntry($workspace, $path, $entry);
            if ($error !== null) {
                return $path.':'.$error;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $argv
     */
    public function gitOutput(string $workspace, array $argv): string
    {
        $process = new Process($argv, $workspace, null, null, 15.0);
        $process->run();

        return $process->isSuccessful() || $process->getExitCode() === 1
            ? (string) $process->getOutput()
            : '';
    }

    /**
     * @param  list<string>  $allowedFiles
     * @return list<string>
     */
    public function pathsFromPorcelainStatus(string $status, array $allowedFiles): array
    {
        $allowed = array_flip($allowedFiles);
        $paths = [];
        foreach (explode("\n", $status) as $line) {
            if (strlen($line) < 4) {
                continue;
            }
            $payload = trim(substr($line, 3));
            foreach (str_contains($payload, ' -> ') ? explode(' -> ', $payload) : [$payload] as $path) {
                $path = trim($path, "\" \t\n\r\0\x0B");
                if (isset($allowed[$path])) {
                    $paths[] = $path;
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @return array<string,mixed>
     */
    public function captureWorkspaceBaselineEntry(string $workspace, string $path): array
    {
        $absolute = rtrim($workspace, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$path;
        if (is_link($absolute)) {
            return [
                'kind' => 'symlink',
                'target' => (string) readlink($absolute),
            ];
        }
        if (is_file($absolute)) {
            return [
                'kind' => 'file',
                'contents' => (string) file_get_contents($absolute),
                'mode' => @fileperms($absolute) !== false ? (@fileperms($absolute) & 0o777) : null,
            ];
        }
        if (is_dir($absolute)) {
            return ['kind' => 'directory'];
        }

        return ['kind' => 'missing'];
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    public function restoreWorkspaceBaselineEntry(string $workspace, string $path, array $entry): ?string
    {
        $absolute = rtrim($workspace, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$path;
        $kind = (string) ($entry['kind'] ?? 'missing');

        if ($kind === 'missing') {
            return $this->removeFileLikePath($absolute) ? null : 'path_type_changed_to_directory';
        }

        if ($kind === 'directory') {
            return is_dir($absolute) || @mkdir($absolute, 0o755, true) ? null : 'directory_restore_failed';
        }

        if (is_dir($absolute) && ! is_link($absolute)) {
            return 'path_type_changed_to_directory';
        }
        if (! $this->ensureParentDirectory(dirname($absolute))) {
            return 'parent_directory_restore_failed';
        }
        if (! $this->removeFileLikePath($absolute)) {
            return 'path_type_changed_to_directory';
        }

        if ($kind === 'symlink') {
            return @symlink((string) ($entry['target'] ?? ''), $absolute) ? null : 'symlink_restore_failed';
        }

        if ($kind === 'file') {
            if (@file_put_contents($absolute, (string) ($entry['contents'] ?? ''), LOCK_EX) === false) {
                return 'file_restore_failed';
            }
            if (is_int($entry['mode'] ?? null)) {
                @chmod($absolute, (int) $entry['mode']);
            }

            return null;
        }

        return 'unknown_baseline_entry';
    }

    public function removeFileLikePath(string $absolute): bool
    {
        if (is_dir($absolute) && ! is_link($absolute)) {
            return false;
        }
        if (file_exists($absolute) || is_link($absolute)) {
            return @unlink($absolute);
        }

        return true;
    }

    public function ensureParentDirectory(string $directory): bool
    {
        return is_dir($directory) || @mkdir($directory, 0o755, true);
    }

    /**
     * Changed (tracked, staged, untracked, and explicit ignored-forbidden)
     * workspace paths, repo-relative, used to compute scope violations for
     * providers (like Hermes) that mutate the worktree directly but do not
     * return a structured changed-files list.
     *
     * Important: scope inspection must not pathspec regular untracked files to
     * allowed_files. Otherwise a provider can create an out-of-scope file and
     * still pass by also changing an allowed file. Staged files need their own
     * cached diff because `git add` removes them from the untracked set. Ignored
     * files are limited to explicit forbidden_files to avoid failing every real
     * workspace that already has ignored local artifacts such as dependency
     * folders or machine-local environment files.
     *
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $forbiddenFiles
     * @return list<string>
     */
    /**
     * @param  array<string,string>|null  $preIgnoredForbidden  snapshot pré-provider de ignoredForbiddenSnapshot()
     */
    public function changedFilePathsInWorkspace(string $workspace, array $allowedFiles, array $forbiddenFiles = [], ?array $preIgnoredForbidden = null): array
    {
        if (! is_dir($workspace)) {
            return [];
        }

        $paths = [];

        foreach ([
            ['git', 'diff', '--no-ext-diff', '--name-only'],
            ['git', 'diff', '--cached', '--no-ext-diff', '--name-only'],
        ] as $argv) {
            $paths = array_merge($paths, $this->gitNameOnlyPaths($workspace, $argv));
        }

        // Untracked comuns: mesmo princípio pré/pós dos ignorados-proibidos —
        // um untracked que JÁ EXISTIA antes do provider rodar (com a mesma
        // assinatura) é estado do operador/run anterior, não mutação deste
        // provider (matriz real 03/07: teste criado por um cenário anterior
        // derrubava o cenário seguinte como scope violation). Untracked NOVO
        // ou alterado continua contando (invariante do scope guard preservado).
        $untracked = $this->gitNameOnlyPaths($workspace, ['git', 'ls-files', '--others', '--exclude-standard']);
        if ($preIgnoredForbidden !== null) {
            $untracked = array_values(array_filter(
                $untracked,
                function (string $path) use ($workspace, $preIgnoredForbidden): bool {
                    $pre = $preIgnoredForbidden[$path] ?? null;

                    return $pre === null || $pre !== $this->fileSignature($workspace.'/'.$path);
                },
            ));
        }
        $paths = array_merge($paths, $untracked);

        $ignoredForbidden = $this->safeRelativePaths($forbiddenFiles);
        if ($ignoredForbidden !== []) {
            $argv = ['git', 'ls-files', '--others', '--ignored', '--exclude-standard', '--'];
            array_push($argv, ...$ignoredForbidden);
            $ignoredNow = $this->gitNameOnlyPaths($workspace, $argv);
            // Só o DELTA contra o snapshot pré-provider conta como mutação:
            // num repo real, vendor/ e caches (phpunit, storage/framework)
            // PRÉ-EXISTEM ignorados — listá-los inteiros fazia todo run em
            // repo real virar scope violation (achado do fire test 03/07 em
            // worktree do atlas-server). Sem snapshot (caller legado), o
            // comportamento antigo se mantém fail-closed.
            if ($preIgnoredForbidden !== null) {
                $ignoredNow = array_values(array_filter(
                    $ignoredNow,
                    function (string $path) use ($workspace, $preIgnoredForbidden): bool {
                        // Caches efêmeros de test-runner nunca são mutação de
                        // escopo: o provider RODA a validação (permitido pelo
                        // prompt) e o phpunit atualiza o próprio cache.
                        if ($this->isEphemeralInfraPath($path)) {
                            return false;
                        }
                        $sig = $this->fileSignature($workspace.'/'.$path);

                        return ($preIgnoredForbidden[$path] ?? null) !== $sig;
                    },
                ));
            }
            $paths = array_merge($paths, $ignoredNow);
        }

        return array_values(array_unique($paths));
    }

    /**
     * Snapshot (path => assinatura size:mtime) dos arquivos ignorados que
     * casam os padrões proibidos — capturado ANTES do provider rodar para o
     * detector reportar só o que o provider realmente criou/alterou.
     *
     * @param  list<string>  $forbiddenFiles
     * @return array<string,string>
     */
    public function ignoredForbiddenSnapshot(string $workspace, array $forbiddenFiles): array
    {
        if (! is_dir($workspace)) {
            return [];
        }
        $ignoredForbidden = $this->safeRelativePaths($forbiddenFiles);
        if ($ignoredForbidden === []) {
            return [];
        }

        $argv = ['git', 'ls-files', '--others', '--ignored', '--exclude-standard', '--'];
        array_push($argv, ...$ignoredForbidden);

        $snapshot = [];
        foreach ($this->gitNameOnlyPaths($workspace, $argv) as $path) {
            $snapshot[$path] = $this->fileSignature($workspace.'/'.$path);
        }
        // Untracked comuns pré-existentes: mesma semântica delta (o detector
        // pós-run só reporta untracked novo/alterado como mutação do provider).
        foreach ($this->gitNameOnlyPaths($workspace, ['git', 'ls-files', '--others', '--exclude-standard']) as $path) {
            $snapshot[$path] = $this->fileSignature($workspace.'/'.$path);
        }

        return $snapshot;
    }

    public function isEphemeralInfraPath(string $path): bool
    {
        return preg_match(
            '#^storage/framework/|(^|/)\.phpunit\.(cache|result\.cache)|(^|/)phpunit-cache/|(^|/)node_modules/\.cache/#',
            $path,
        ) === 1;
    }

    public function fileSignature(string $absolutePath): string
    {
        $stat = @stat($absolutePath);
        if ($stat === false) {
            return 'missing';
        }

        return $stat['size'].':'.$stat['mtime'];
    }

    /**
     * @param  list<string>  $argv
     * @return list<string>
     */
    public function gitNameOnlyPaths(string $workspace, array $argv): array
    {
        $process = new Process($argv, $workspace, null, null, 15.0);
        $process->run();
        if (! $process->isSuccessful() && $process->getExitCode() !== 1) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $line): string => trim($line),
            explode("\n", (string) $process->getOutput()),
        ), static fn (string $path): bool => $path !== ''));
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    public function safeRelativePaths(array $paths): array
    {
        return array_values(array_filter(array_map(
            static function (string $path): string {
                $path = ltrim(trim($path), '/');

                return $path !== '' && ! str_contains($path, '..') ? $path : '';
            },
            $paths,
        ), static fn (string $path): bool => $path !== ''));
    }

    /**
     * @param  list<string>  $allowedFiles
     */
    public function workspaceDiff(string $workspace, array $allowedFiles): string
    {
        if (! is_dir($workspace)) {
            return '';
        }

        $paths = array_values(array_filter(array_map(
            static fn (mixed $path): string => is_string($path) ? trim($path) : '',
            $allowedFiles,
        ), static fn (string $path): bool => $path !== '' && ! str_starts_with($path, '/') && ! str_contains($path, '..')));

        $argv = ['git', 'diff', '--no-ext-diff', '--'];
        array_push($argv, ...$paths);

        $process = new Process($argv, $workspace, null, null, 15.0);
        $process->run();
        if (! $process->isSuccessful() && $process->getExitCode() !== 1) {
            return '';
        }

        $diff = (string) $process->getOutput();
        $diff .= $this->untrackedAllowedFilesDiff($workspace, $paths);

        return $diff !== '' && ! str_ends_with($diff, "\n") ? $diff."\n" : $diff;
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    public function stringList(array $values): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $value): string => is_string($value) ? trim($value) : '',
            $values,
        ), static fn (string $value): bool => $value !== ''));
    }

    /**
     * @param  list<string>  $allowedFiles
     */
    public function pathAllowed(string $path, array $allowedFiles): bool
    {
        $path = ltrim(trim($path), '/');
        if ($path === '') {
            return false;
        }

        return in_array($path, $allowedFiles, true);
    }

    /**
     * git diff omits untracked files. Cursor-style workspace mutators often
     * satisfy missing-test findings by creating a brand-new allowed test file,
     * so Atlas must promote those files into a unified diff before parsing.
     *
     * @param  list<string>  $allowedFiles
     */
    public function untrackedAllowedFilesDiff(string $workspace, array $allowedFiles): string
    {
        if ($allowedFiles === []) {
            return '';
        }

        $argv = ['git', 'ls-files', '--others', '--exclude-standard', '--'];
        array_push($argv, ...$allowedFiles);

        $process = new Process($argv, $workspace, null, null, 15.0);
        $process->run();
        if (! $process->isSuccessful()) {
            return '';
        }

        $untracked = array_values(array_filter(array_map(
            static fn (string $line): string => trim($line),
            explode("\n", (string) $process->getOutput()),
        ), static fn (string $path): bool => $path !== ''));

        $diff = '';
        foreach ($untracked as $path) {
            if (! in_array($path, $allowedFiles, true)) {
                continue;
            }
            $filePath = rtrim($workspace, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$path;
            if (! is_file($filePath)) {
                continue;
            }
            $fileDiff = new Process(['git', 'diff', '--no-index', '--', '/dev/null', $path], $workspace, null, null, 15.0);
            $fileDiff->run();
            $output = (string) $fileDiff->getOutput();
            if ($output === '') {
                continue;
            }
            $diff .= (str_ends_with($diff, "\n") || $diff === '' ? '' : "\n").$output;
        }

        return $diff;
    }

    /**
     * E3: best-effort resolution of the repo root for the scoped infection
     * invocation. Falls back to base_path() (Laravel kernel) and finally to
     * the CWD so plain-PHPunit contexts never crash. Returns null only when
     * no resolution path is available.
     */
    public function workspaceRoot(): ?string
    {
        try {
            return base_path();
        } catch (\Throwable) {
            return getcwd() ?: null;
        }
    }
}
