<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiTrace;
use App\Models\AtlasEngineeringControlResult;
use App\Models\AtlasEngineeringPatchArtifact;
use App\Models\AtlasEngineeringRun;
use App\Models\AtlasEngineeringTestRun;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

final class AiTraceArtifactsProjection
{
    /**
     * @return array<string, mixed>
     */
    public function forTrace(AiTrace $trace): array
    {
        $run = $this->uniqueRunFor($trace);
        if (! $run instanceof AtlasEngineeringRun) {
            return $this->unavailable($run);
        }

        return [
            'schema_version' => 'atlas.trace_artifacts.v1',
            'state' => 'available',
            'run' => [
                'workspace_label' => $run->workspace_label,
            ],
            'items' => array_map(
                fn (array $artifact): array => $artifact['public'],
                $this->artifactsForRun($run),
            ),
        ];
    }

    /**
     * @return array{state:'ok',data:string,content_type:string,sha256:string}|array{state:'too_large',byte_size:int}|null
     */
    public function contentForTrace(AiTrace $trace, string $artifactId, int $maxBytes): ?array
    {
        $run = $this->uniqueRunFor($trace);
        if (! $run instanceof AtlasEngineeringRun) {
            return null;
        }

        foreach ($this->artifactsForRun($run) as $artifact) {
            if ($artifact['public']['id'] !== $artifactId) {
                continue;
            }

            $byteSize = (int) $artifact['public']['byte_size'];
            if ($byteSize > $maxBytes) {
                return [
                    'state' => 'too_large',
                    'byte_size' => $byteSize,
                ];
            }

            $data = $artifact['data'] ?? File::get($artifact['path']);
            $data = is_string($data) ? $data : '';
            $sha256 = hash('sha256', $data);

            return [
                'state' => 'ok',
                'data' => $data,
                'content_type' => $artifact['content_type'],
                'sha256' => $sha256,
            ];
        }

        return null;
    }

    private function uniqueRunFor(AiTrace $trace): AtlasEngineeringRun|string
    {
        if (! DatabaseTableAvailability::has('atlas_engineering_runs')) {
            return 'no_workspace';
        }

        $runs = AtlasEngineeringRun::query()
            ->where('trace_id', $trace->id)
            ->limit(2)
            ->get();

        if ($runs->isEmpty()) {
            return 'no_run';
        }

        if ($runs->count() !== 1) {
            return 'multiple_runs';
        }

        /** @var AtlasEngineeringRun $run */
        $run = $runs->sole();
        if (! is_string($run->workspace_label) || trim($run->workspace_label) === ''
            || ! is_string($run->workspace_path_hash) || trim($run->workspace_path_hash) === '') {
            return 'no_workspace';
        }

        return $run;
    }

    /**
     * @return array<string, mixed>
     */
    private function unavailable(string $reason): array
    {
        return [
            'schema_version' => 'atlas.trace_artifacts.v1',
            'state' => 'unavailable',
            'reason' => $reason,
        ];
    }

    /**
     * @return list<array{public:array<string,mixed>,content_type:string,path?:string,data?:string}>
     */
    private function artifactsForRun(AtlasEngineeringRun $run): array
    {
        $artifacts = [];

        if (DatabaseTableAvailability::has('atlas_engineering_test_runs')) {
            AtlasEngineeringTestRun::query()
                ->where('engineering_run_id', $run->id)
                ->latest('created_at')
                ->get()
                ->each(function (AtlasEngineeringTestRun $testRun) use ($run, &$artifacts): void {
                    $root = $this->safeArtifactRoot($run, $testRun->artifact_path);
                    if ($root === null) {
                        return;
                    }

                    foreach ($this->filesUnder($root) as $path) {
                        $relativePath = $this->relativePath($root, $path);
                        $artifacts[] = $this->fileArtifact($run, 'test', $relativePath, $path, 'produced');
                    }
                });
        }

        if (DatabaseTableAvailability::has('atlas_engineering_control_results')) {
            AtlasEngineeringControlResult::query()
                ->where('engineering_run_id', $run->id)
                ->latest('created_at')
                ->get()
                ->each(function (AtlasEngineeringControlResult $control) use ($run, &$artifacts): void {
                    $path = $this->safeRunFile($run, $control->artifact_path);
                    if ($path === null) {
                        return;
                    }

                    $artifacts[] = $this->fileArtifact($run, 'control', $this->relativePath($this->runRoot($run) ?? dirname($path), $path), $path, 'produced');
                });
        }

        if (DatabaseTableAvailability::has('atlas_engineering_patch_artifacts')) {
            AtlasEngineeringPatchArtifact::query()
                ->where('engineering_run_id', $run->id)
                ->latest('created_at')
                ->get()
                ->each(function (AtlasEngineeringPatchArtifact $patch) use ($run, &$artifacts): void {
                    $path = $this->safeRunFile($run, $patch->diff_path);
                    if ($path !== null) {
                        $artifacts[] = $this->fileArtifact($run, 'patch', $this->relativePath($this->runRoot($run) ?? dirname($path), $path), $path, 'modified', forcedKind: 'diff');

                        return;
                    }

                    if (is_string($patch->diff_excerpt) && $patch->diff_excerpt !== '') {
                        $data = $patch->diff_excerpt;
                        $relativePath = 'patches/'.$patch->id.'.diff';
                        $artifacts[] = $this->memoryArtifact($run, 'patch', $relativePath, $data, 'modified', 'diff');
                    }
                });
        }

        return $artifacts;
    }

    /**
     * @return list<string>
     */
    private function filesUnder(string $root): array
    {
        $files = [];
        foreach (File::allFiles($root) as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @return array{public:array<string,mixed>,content_type:string,path:string}
     */
    private function fileArtifact(
        AtlasEngineeringRun $run,
        string $source,
        string $relativePath,
        string $path,
        string $origin,
        ?string $forcedKind = null,
    ): array {
        $bytes = File::size($path);
        $contentType = $this->contentType($path);
        $kind = $forcedKind ?? $this->kind($relativePath, $contentType);

        return [
            'public' => $this->publicItem(
                $run,
                $source,
                $relativePath,
                $kind,
                $bytes,
                hash_file('sha256', $path) ?: hash('sha256', ''),
                File::lastModified($path),
                $origin,
            ),
            'content_type' => $kind === 'diff' ? 'text/x-diff; charset=utf-8' : $contentType,
            'path' => $path,
        ];
    }

    /**
     * @return array{public:array<string,mixed>,content_type:string,data:string}
     */
    private function memoryArtifact(
        AtlasEngineeringRun $run,
        string $source,
        string $relativePath,
        string $data,
        string $origin,
        string $kind,
    ): array {
        return [
            'public' => $this->publicItem(
                $run,
                $source,
                $relativePath,
                $kind,
                strlen($data),
                hash('sha256', $data),
                null,
                $origin,
            ),
            'content_type' => 'text/x-diff; charset=utf-8',
            'data' => $data,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function publicItem(
        AtlasEngineeringRun $run,
        string $source,
        string $relativePath,
        string $kind,
        int $bytes,
        string $sha256,
        ?int $mtime,
        string $origin,
    ): array {
        $item = [
            'id' => $this->artifactId($run, $source, $relativePath),
            'kind' => $kind,
            'name' => basename($relativePath),
            'byte_size' => $bytes,
            'sha256' => $sha256,
            'origin' => $origin,
        ];
        $relativeDir = $this->safeRelativeDir($relativePath);
        if ($relativeDir !== null) {
            $item['relative_dir'] = $relativeDir;
        }
        if ($mtime !== null) {
            $item['created_at'] = date(DATE_ATOM, $mtime);
        }

        return $item;
    }

    private function artifactId(AtlasEngineeringRun $run, string $source, string $relativePath): string
    {
        return 'art_'.substr(hash('sha256', $run->id.'|'.$source.'|'.$relativePath), 0, 16);
    }

    private function safeArtifactRoot(AtlasEngineeringRun $run, mixed $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $root = $this->runRoot($run);
        $resolved = realpath($path);
        if ($root === null || ! $resolved || ! File::isDirectory($resolved)) {
            return null;
        }

        $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $artifactRoot = rtrim($resolved, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return Str::startsWith($artifactRoot, $rootPrefix) || $artifactRoot === $rootPrefix
            ? rtrim($resolved, DIRECTORY_SEPARATOR)
            : null;
    }

    private function safeRunFile(AtlasEngineeringRun $run, mixed $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $root = $this->runRoot($run);
        $resolved = realpath($path);
        if ($root === null || ! $resolved || ! File::isFile($resolved)) {
            return null;
        }

        $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return Str::startsWith($resolved, $rootPrefix) ? $resolved : null;
    }

    private function runRoot(AtlasEngineeringRun $run): ?string
    {
        $root = realpath(storage_path('app/engineering-runs/'.$run->id));

        return $root !== false ? $root : null;
    }

    private function relativePath(string $root, string $path): string
    {
        return str_replace(DIRECTORY_SEPARATOR, '/', ltrim(Str::after($path, rtrim($root, DIRECTORY_SEPARATOR)), DIRECTORY_SEPARATOR));
    }

    private function safeRelativeDir(string $relativePath): ?string
    {
        $dir = str_replace(DIRECTORY_SEPARATOR, '/', dirname($relativePath));
        if ($dir === '.' || $dir === '') {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $dir), fn (string $segment): bool => $segment !== '' && $segment !== '.' && $segment !== '..'));
        if ($segments === []) {
            return null;
        }

        return implode('/', array_slice($segments, 0, 2));
    }

    private function contentType(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'md' || $extension === 'markdown') {
            return 'text/markdown; charset=utf-8';
        }
        if (in_array($extension, ['diff', 'patch'], true)) {
            return 'text/x-diff; charset=utf-8';
        }

        try {
            $mime = File::mimeType($path);
        } catch (\Throwable) {
            $mime = null;
        }

        return is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
    }

    private function kind(string $path, string $contentType): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match (true) {
            str_starts_with($contentType, 'image/') => 'image',
            in_array($extension, ['md', 'markdown'], true) => 'markdown',
            in_array($extension, ['diff', 'patch'], true) => 'diff',
            str_starts_with($contentType, 'text/'),
            in_array($extension, ['txt', 'log', 'json', 'xml', 'html', 'htm', 'css', 'js', 'ts', 'tsx', 'jsx', 'yml', 'yaml', 'csv'], true) => 'text',
            default => 'file',
        };
    }
}
