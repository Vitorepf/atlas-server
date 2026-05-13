<?php

namespace App\Services\Ai\Programming;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ProgrammingLocalVectorIndex
{
    /**
     * @param  array<int,array<string,mixed>>  $queries
     * @return array<int,array<string,mixed>>
     */
    public function search(string $workspace, string $objective, array $queries, int $limit = 12): array
    {
        $queryText = trim($objective.' '.collect($queries)->pluck('query')->implode(' '));
        $queryVector = $this->vector($queryText);
        if ($queryVector === []) {
            return [];
        }

        $candidates = $this->candidateFiles($workspace, $queryText);

        return collect($candidates)
            ->map(function (string $path) use ($workspace, $queryVector): ?array {
                $absolutePath = $workspace.DIRECTORY_SEPARATOR.$path;
                if (! File::isFile($absolutePath) || File::size($absolutePath) > 250000) {
                    return null;
                }

                $content = (string) File::get($absolutePath);
                $score = $this->cosine($queryVector, $this->vector($path.' '.$content)) + $this->pathBoost($path, $queryVector);
                if ($score <= 0.08) {
                    return null;
                }

                return [
                    'source' => $this->sourceForPath($path),
                    'ref' => $path,
                    'reason' => 'local_semantic_vector_match',
                    'score' => round($score, 4),
                    'scope' => $this->scopeForPath($path),
                    'hash' => hash('sha256', $path.'|'.File::lastModified($absolutePath).'|'.File::size($absolutePath)),
                    'freshness' => 'current',
                    'privacy' => 'provider_safe',
                    'retrieval_channel' => 'local_semantic_vector',
                ];
            })
            ->filter()
            ->sortByDesc('score')
            ->take(max(1, $limit))
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function candidateFiles(string $workspace, string $queryText): array
    {
        $roots = ['app', 'tests', 'docs/engineering-knowledge-base', 'routes', 'database/migrations'];
        $queryVector = $this->vector($queryText);

        return collect($roots)
            ->flatMap(function (string $root) use ($workspace): array {
                $absoluteRoot = $workspace.DIRECTORY_SEPARATOR.$root;
                if (! File::isDirectory($absoluteRoot)) {
                    return [];
                }

                return collect(File::allFiles($absoluteRoot))
                    ->map(fn ($file): string => Str::after($file->getPathname(), $workspace.DIRECTORY_SEPARATOR))
                    ->filter(fn (string $path): bool => $this->supportedPath($path))
                    ->all();
            })
            ->unique()
            ->sortByDesc(fn (string $path): float => $this->pathBoost($path, $queryVector))
            ->take(420)
            ->values()
            ->all();
    }

    private function supportedPath(string $path): bool
    {
        return Str::endsWith($path, ['.php', '.md', '.json', '.ts', '.tsx', '.js', '.jsx', '.vue', '.swift']);
    }

    /**
     * @return array<string,int>
     */
    private function vector(string $text): array
    {
        $expandedText = $text.' '.(preg_replace('/([a-z])([A-Z])/', '$1 $2', $text) ?? $text);
        preg_match_all('/[a-zA-Z0-9_\\\\\/.-]{3,}/', Str::lower($expandedText), $matches);

        return collect($matches[0] ?? [])
            ->reject(fn (string $token): bool => in_array($token, ['the', 'and', 'para', 'com', 'que', 'uma', 'atlas'], true))
            ->countBy()
            ->all();
    }

    /**
     * @param  array<string,int>  $a
     * @param  array<string,int>  $b
     */
    private function cosine(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        $dot = 0;
        foreach ($a as $token => $weight) {
            $dot += $weight * ($b[$token] ?? 0);
        }

        $normA = sqrt(array_sum(array_map(fn (int $value): int => $value * $value, $a)));
        $normB = sqrt(array_sum(array_map(fn (int $value): int => $value * $value, $b)));

        return $normA > 0.0 && $normB > 0.0 ? $dot / ($normA * $normB) : 0.0;
    }

    /**
     * @param  array<string,int>  $queryVector
     */
    private function pathBoost(string $path, array $queryVector): float
    {
        $pathVector = $this->vector($path.' '.str_replace(['/', '.', '-'], ' ', $path));
        $hits = collect(array_keys($queryVector))
            ->filter(fn (string $token): bool => isset($pathVector[$token]) || str_contains(Str::lower($path), $token))
            ->count();

        $exactSymbolBoost = collect(array_keys($queryVector))
            ->filter(fn (string $token): bool => strlen($token) >= 12 && str_contains(Str::lower($path), $token))
            ->count() * 0.45;

        return min(1.25, ($hits * 0.06) + $exactSymbolBoost);
    }

    private function sourceForPath(string $path): string
    {
        if (str_starts_with($path, 'tests/')) {
            return 'related_tests';
        }
        if (str_starts_with($path, 'docs/')) {
            return 'canonical_docs';
        }

        return 'code_symbols';
    }

    private function scopeForPath(string $path): string
    {
        if (str_starts_with($path, 'tests/')) {
            return 'test';
        }
        if (str_starts_with($path, 'docs/')) {
            return 'review';
        }

        return 'patch';
    }
}
