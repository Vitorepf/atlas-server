<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use RuntimeException;

final class AtlasLoopWorkspaceMaterializerSupport2
{
    /**
     * @param  array<string,mixed>  $payload
     */
    public function writeWorkspaceFiles(string $base, string $targetRel, string $targetContent, array $payload): void
    {
        $this->writeFile($base, $targetRel, $targetContent);

        $support = is_array($payload['support_files'] ?? null) ? $payload['support_files'] : [];
        if (! $this->supportHas($support, 'composer.json')) {
            $this->writeFile($base, 'composer.json', "{}\n");
        }
        foreach (array_merge(
            $support,
            is_array($payload['frozen_tests'] ?? null) ? $payload['frozen_tests'] : [],
        ) as $file) {
            if (is_array($file) && is_string($file['path'] ?? null) && is_string($file['content'] ?? null)) {
                $this->writeFile($base, $this->normalizeRelative($file['path']), $file['content']);
            }
        }
    }

    /**
     * @param  array<string,mixed>  $acceptance
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function buildExplorerTask(string $objective, string $base, array $acceptance, array $payload, string $targetRel): array
    {
        $explorerTask = [
            'objective' => $objective,
            'base_workspace' => $base,
            'acceptance' => $acceptance,
            'allowed_files' => $this->stringList($payload['allowed_files'] ?? [$targetRel]),
            'validation_commands' => $this->stringList($payload['validation_commands'] ?? []),
        ];
        $this->armCrossProviderScope($explorerTask, $acceptance, (bool) ($payload['cross_provider_best_of_n'] ?? false));
        $provider = trim((string) ($payload['provider'] ?? ''));
        if ($provider !== '') {
            $explorerTask['provider'] = $provider; // per-task pin; else loop default (provider-agnostic)
        }
        $explorerTask += array_intersect_key($payload, [
            'scenario_strategies' => true,
            'scenario_strategy_keys' => true,
            'deep_strategy_portfolio' => true,
            'min_scenarios' => true,
            'max_scenarios' => true,
            'search_patience' => true,
            'search_time_budget_seconds' => true,
            'keep_workspaces' => true,
        ]);

        return $explorerTask;
    }

    /**
     * @param  array<string,mixed>  $explorerTask
     * @param  array<string,mixed>  $acceptance
     */
    public function armCrossProviderScope(array &$explorerTask, array $acceptance, bool $enabled): void
    {
        if (! $enabled) {
            return;
        }
        $globs = is_array($acceptance['allowed_globs'] ?? null)
            ? array_filter($acceptance['allowed_globs'], static fn ($g): bool => is_string($g) && trim($g) !== '')
            : [];
        if ($globs === [] && ($explorerTask['allowed_files'] ?? []) !== []) {
            $explorerTask['acceptance']['allowed_globs'] = $explorerTask['allowed_files'];
        }
    }

    /**
     * @param  list<mixed>  $support
     */
    public function supportHas(array $support, string $path): bool
    {
        foreach ($support as $file) {
            if (is_array($file) && $this->normalizeRelative((string) ($file['path'] ?? '')) === $path) {
                return true;
            }
        }

        return false;
    }

    public function writeFile(string $base, string $relative, string $content): void
    {
        $relative = $this->normalizeRelative($relative);
        if ($relative === '') {
            return;
        }
        $full = $base.'/'.$relative;
        $dir = dirname($full);
        if (! is_dir($dir) && ! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new RuntimeException('materialize: cannot create dir '.$dir);
        }
        if (file_put_contents($full, $content) === false) {
            throw new RuntimeException('materialize: cannot write '.$relative);
        }
    }

    /**
     * Defense in depth: keep every written path inside the scoped workspace (no
     * absolute paths, no `..` traversal). A poisoned payload cannot escape the temp dir.
     */
    public function normalizeRelative(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = ltrim($path, '/');
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    public function stringList(mixed $value): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $v): string => is_string($v) ? trim($v) : '',
            is_array($value) ? $value : [],
        ), static fn (string $v): bool => $v !== ''));
    }
}
