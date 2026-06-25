<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\RepoWide;

final class AtlasLoopRepoWideConsumersProvider
{
    /**
     * @param  array<string,mixed>  $repoWideModel
     */
    public function __construct(
        private readonly array $repoWideModel,
        private readonly AtlasLoopRepoWideCallerResolver $resolver,
    ) {}

    /**
     * Production seam — federates per-scope comprehension facts through the previously-orphan
     * AtlasLoopRepoWideComprehensionModel and feeds the resulting repo-wide model into the
     * consumers provider. This is the canonical call path for the comprehension model.
     *
     * @param  list<array<string,mixed>>  $perScopeModels
     */
    public static function fromPerScopeModels(
        array $perScopeModels,
        AtlasLoopRepoWideCallerResolver $resolver,
        ?AtlasLoopRepoWideComprehensionModel $comprehension = null,
    ): self {
        $model = ($comprehension ?? new AtlasLoopRepoWideComprehensionModel())->build($perScopeModels);

        // The comprehension model federates the structural facts; the consumers provider also
        // needs the raw file_contents and fqcn-by-path maps from each scope to do the actual
        // caller resolution at lookup time. Merge them in.
        $contents = [];
        $fqcnByPath = [];
        foreach ($perScopeModels as $scope) {
            if (! is_array($scope)) {
                continue;
            }
            foreach (['file_contents_by_path', 'fileContentsByPath'] as $key) {
                foreach ((array) ($scope[$key] ?? []) as $p => $c) {
                    if (is_string($c)) {
                        $contents[(string) $p] = $c;
                    }
                }
            }
            foreach (['fqcn_by_path', 'fqcns_by_path'] as $key) {
                foreach ((array) ($scope[$key] ?? []) as $p => $fqcn) {
                    $fqcnByPath[(string) $p] = (string) $fqcn;
                }
            }
        }
        $model['file_contents_by_path'] = $contents;
        $model['fqcn_by_path'] = $fqcnByPath;

        return new self($model, $resolver);
    }

    /**
     * @return list<string>
     */
    public function consumersOf(string $fqcn): array
    {
        $target = $this->normalizeFqcn($fqcn);
        if ($target === '') {
            return [];
        }

        $fqcnByPath = $this->fqcnByPath();
        $consumers = [];

        foreach ($this->resolver->callersOf($target, $this->fileContentsByPath()) as $path) {
            $consumer = $fqcnByPath[$this->normalizePath($path)] ?? null;
            if ($consumer === null || $consumer === $target) {
                continue;
            }

            $consumers[$consumer] = true;
        }

        $out = array_keys($consumers);
        sort($out, SORT_STRING);

        return $out;
    }

    public function asCallable(): callable
    {
        return fn (string $fqcn): array => $this->consumersOf($fqcn);
    }

    /**
     * @return array<string,string>
     */
    private function fileContentsByPath(): array
    {
        $contents = [];

        foreach (['file_contents_by_path', 'fileContentsByPath'] as $key) {
            if (isset($this->repoWideModel[$key]) && is_array($this->repoWideModel[$key])) {
                foreach ($this->repoWideModel[$key] as $path => $content) {
                    if (is_string($content)) {
                        $contents[$this->normalizePath((string) $path)] = $content;
                    }
                }
            }
        }

        foreach ((array) ($this->repoWideModel['files'] ?? []) as $path => $file) {
            if (is_string($file)) {
                $contents[$this->normalizePath((string) $path)] = $file;
                continue;
            }

            if (! is_array($file)) {
                continue;
            }

            $filePath = $this->pathFrom($file);
            $content = $this->contentFrom($file);
            if ($filePath !== null && $content !== null) {
                $contents[$filePath] = $content;
            }
        }

        foreach ((array) ($this->repoWideModel['symbols'] ?? []) as $symbol) {
            if (! is_array($symbol)) {
                continue;
            }

            $path = $this->pathFrom($symbol);
            $content = $this->contentFrom($symbol);
            if ($path !== null && $content !== null) {
                $contents[$path] = $content;
            }
        }

        ksort($contents);

        return $contents;
    }

    /**
     * @return array<string,string>
     */
    private function fqcnByPath(): array
    {
        $map = [];

        foreach (['fqcn_by_path', 'fqcns_by_path'] as $key) {
            if (isset($this->repoWideModel[$key]) && is_array($this->repoWideModel[$key])) {
                foreach ($this->repoWideModel[$key] as $path => $fqcn) {
                    $normalized = $this->normalizeFqcn((string) $fqcn);
                    if ($normalized !== '') {
                        $map[$this->normalizePath((string) $path)] = $normalized;
                    }
                }
            }
        }

        foreach ((array) ($this->repoWideModel['symbols'] ?? []) as $symbol) {
            if (! is_array($symbol)) {
                continue;
            }

            $path = $this->pathFrom($symbol);
            $fqcn = $this->normalizeFqcn((string) ($symbol['fqcn'] ?? $symbol['symbol_name'] ?? ''));
            if ($path !== null && $fqcn !== '') {
                $map[$path] = $fqcn;
            }
        }

        ksort($map);

        return $map;
    }

    /** @param array<string,mixed> $payload */
    private function pathFrom(array $payload): ?string
    {
        foreach (['path', 'file_path', 'rel_path'] as $key) {
            $value = trim((string) ($payload[$key] ?? ''));
            if ($value !== '') {
                return $this->normalizePath($value);
            }
        }

        return null;
    }

    /** @param array<string,mixed> $payload */
    private function contentFrom(array $payload): ?string
    {
        foreach (['content', 'source', 'source_code', 'file_content'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key])) {
                return $payload[$key];
            }
        }

        return null;
    }

    private function normalizePath(string $path): string
    {
        return ltrim(str_replace('\\', '/', trim($path)), '/');
    }

    private function normalizeFqcn(string $fqcn): string
    {
        return trim(ltrim($fqcn, '\\'));
    }
}
