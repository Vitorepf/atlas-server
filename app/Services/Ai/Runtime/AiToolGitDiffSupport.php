<?php

namespace App\Services\Ai\Runtime;

use App\Services\Ai\Support\JsonFileStore;
use App\Support\AtlasSecurity;
use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * GIT/DIFF/PATCH HELPER concern, extracted from the god-class
 * {@see AiToolRuntime}.
 *
 * Owns git diff inspection (gitDiff, gitStatusOutput), unified diff
 * generation (unifiedDiff), patch path extraction (patchPaths), git-status
 * parsing (parseStatusChangedFiles), checkpoint snapshotting (checkpoint)
 * and the PHP fallback code search (phpCodeSearch).
 *
 * Capabilities that STAY in AiToolRuntime (runProcess, relativePath,
 * workspacePath) are passed in as Closures — the SAME closure-binding
 * pattern used by AtlasLoopRefillerSupplyLaneCoordinator and
 * AtlasLoopVerificationAtomNormalizer.
 */
class AiToolGitDiffSupport
{
    /**
     * @param  Closure(array<int,string>, string, int): array{exit_code:int,stdout:string,stderr:string,duration_ms:int,command:array<int,string>}  $runProcess
     * @param  Closure(string, string): string  $relativePath
     * @param  Closure(ToolInvocation, string, bool): string  $workspacePath
     */
    public function __construct(
        private readonly Closure $runProcess,
        private readonly Closure $relativePath,
        private readonly Closure $workspacePath,
    ) {}

    /**
     * @param  array<int,string>  $paths
     */
    public function gitDiff(string $workspace, array $paths): string
    {
        $args = ['git', 'diff', '--'];
        foreach ($paths as $path) {
            $args[] = ($this->relativePath)($workspace, $path);
        }

        return ($this->runProcess)($args, $workspace, 60)['stdout'];
    }

    public function gitStatusOutput(string $workspace): string
    {
        return ($this->runProcess)(['git', 'status', '--short'], $workspace, 60)['stdout'];
    }

    public function unifiedDiff(string $before, string $after, string $label): string
    {
        $old = tempnam(sys_get_temp_dir(), 'atlas-old-');
        $new = tempnam(sys_get_temp_dir(), 'atlas-new-');
        if (! $old || ! $new) {
            return $before === $after ? '' : "--- {$label}\n+++ {$label}\n";
        }

        File::put($old, $before);
        File::put($new, $after);
        $result = ($this->runProcess)(['diff', '-u', $old, $new], getcwd() ?: base_path(), 60);
        File::delete($old);
        File::delete($new);

        return str_replace([$old, $new], ["a/{$label}", "b/{$label}"], $result['stdout']);
    }

    /**
     * @return array<int,string>
     */
    public function patchPaths(string $patch, string $workspace): array
    {
        preg_match_all('/^(?:---|\+\+\+)\s+(?:a|b)\/(.+)$/m', $patch, $matches);

        return collect($matches[1] ?? [])
            ->map(fn (string $path): string => trim($path))
            ->reject(fn (string $path): bool => $path === '' || $path === '/dev/null')
            ->map(fn (string $path): string => ($this->workspacePath)(ToolInvocation::make('git.apply_patch', $workspace), $path, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    public function parseStatusChangedFiles(string $status): array
    {
        return collect(explode("\n", $status))
            ->map(fn (string $line): string => trim(substr($line, 3)))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int,string>  $paths
     */
    public function checkpoint(string $workspace, array $paths, string $reason): ?string
    {
        $dir = storage_path('app/ai/checkpoints/'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6)));
        $metadata = [
            'workspace' => $workspace,
            'reason' => $reason,
            'created_at' => now()->toJSON(),
            'files' => [],
        ];

        foreach ($paths as $path) {
            if (! AtlasSecurity::pathIsInside($path, $workspace)) {
                continue;
            }

            $relative = ($this->relativePath)($workspace, $path);
            $metadata['files'][] = [
                'path' => $relative,
                'existed' => File::exists($path),
            ];

            if (File::isFile($path)) {
                $target = $dir.DIRECTORY_SEPARATOR.$relative;
                File::ensureDirectoryExists(dirname($target));
                File::copy($path, $target);
            }
        }

        JsonFileStore::write($dir.'/checkpoint.json', $metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $dir;
    }

    public function phpCodeSearch(string $workspace, string $query, ?string $path = null): string
    {
        $root = $path ? $workspace.DIRECTORY_SEPARATOR.ltrim($path, DIRECTORY_SEPARATOR) : $workspace;
        $root = realpath($root) ?: $root;
        if (! AtlasSecurity::pathIsInside($root, $workspace) || (! is_dir($root) && ! is_file($root))) {
            return '';
        }

        $files = is_file($root)
            ? [new \SplFileInfo($root)]
            : new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $lines = [];

        foreach ($files as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                continue;
            }
            $real = $file->getRealPath();
            if (! is_string($real) || str_contains($real, DIRECTORY_SEPARATOR.'.git'.DIRECTORY_SEPARATOR) || str_contains($real, DIRECTORY_SEPARATOR.'node_modules'.DIRECTORY_SEPARATOR) || str_contains($real, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $handle = @fopen($real, 'r');
            if ($handle === false) {
                continue;
            }
            $lineNumber = 0;
            while (($line = fgets($handle)) !== false) {
                $lineNumber++;
                if (str_contains($line, $query)) {
                    $lines[] = ($this->relativePath)($workspace, $real).':'.$lineNumber.':'.rtrim($line, "\r\n");
                }
                if (count($lines) >= 200) {
                    break 2;
                }
            }
            fclose($handle);
        }

        return $lines === [] ? '' : implode("\n", $lines)."\n";
    }
}
