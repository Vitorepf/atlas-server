<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Rebuilds a scoped, self-contained base workspace from a DURABLE task payload.
 *
 * The per-task engine ({@see AtlasEvolutionScenarioExplorer}) grinds a transient
 * `base_workspace` temp dir — which never survives a process restart. For a 24h
 * campaign that may crash and resume hours later, the task must therefore carry a
 * fully durable SNAPSHOT (the target file's content + the frozen test bodies +
 * acceptance), not a dead temp path. This materializer turns that durable snapshot
 * back into a fresh, self-contained workspace the explorer can grind — making a
 * queued task 100% reproducible, independent of repo state at grind time.
 *
 * Provider-agnostic: the payload may pin a `provider`, else the explorer resolves
 * the loop default. No provider is named here.
 */
final class AtlasLoopWorkspaceMaterializer
{
    public const SCHEMA = 'atlas.loop.workspace_materialization.v1';

    /**
     * @param  array<string,mixed>  $payload  the durable task spec (see class doc)
     * @return array{0: array<string,mixed>, 1: callable}  [explorerTask, cleanup]
     */
    public function materialize(string $objective, array $payload): array
    {
        $targetRel = $this->normalizeRelative((string) ($payload['target_relative_path'] ?? ''));
        $targetContent = $payload['target_content'] ?? null;
        $acceptance = is_array($payload['acceptance'] ?? null) ? $payload['acceptance'] : [];

        if ($targetRel === '' || ! is_string($targetContent) || ($acceptance['commands'] ?? []) === []) {
            throw new RuntimeException('materialize: payload requires target_relative_path, target_content and acceptance.commands');
        }

        $base = sys_get_temp_dir().'/atlas-loop-task-'.bin2hex(random_bytes(5));
        $cleanup = static function () use ($base): void {
            if (is_dir($base)) {
                (new Process(['rm', '-rf', $base]))->run();
            }
        };

        try {
            $this->writeFile($base, $targetRel, $targetContent);

            // A self-contained PHP workspace needs a composer.json present (the engine's
            // scenario baseline + the frozen `php` tests assume it). Honor an override.
            $support = is_array($payload['support_files'] ?? null) ? $payload['support_files'] : [];
            if (! $this->supportHas($support, 'composer.json')) {
                $this->writeFile($base, 'composer.json', "{}\n");
            }
            foreach ($support as $file) {
                if (is_array($file) && is_string($file['path'] ?? null) && is_string($file['content'] ?? null)) {
                    $this->writeFile($base, $this->normalizeRelative($file['path']), $file['content']);
                }
            }

            // The frozen acceptance tests — durable bodies the loop cannot edit (the judge
            // re-runs them; the explorer treats tests/** as frozen).
            foreach ((is_array($payload['frozen_tests'] ?? null) ? $payload['frozen_tests'] : []) as $test) {
                if (is_array($test) && is_string($test['path'] ?? null) && is_string($test['content'] ?? null)) {
                    $this->writeFile($base, $this->normalizeRelative($test['path']), $test['content']);
                }
            }
        } catch (\Throwable $e) {
            $cleanup();
            throw $e;
        }

        $explorerTask = [
            'objective' => $objective,
            'base_workspace' => $base,
            'acceptance' => $acceptance,
            'allowed_files' => $this->stringList($payload['allowed_files'] ?? [$targetRel]),
            'validation_commands' => $this->stringList($payload['validation_commands'] ?? []),
        ];
        $provider = trim((string) ($payload['provider'] ?? ''));
        if ($provider !== '') {
            $explorerTask['provider'] = $provider; // per-task pin; else loop default (provider-agnostic)
        }
        foreach (['scenario_strategies', 'scenario_strategy_keys', 'min_scenarios', 'max_scenarios', 'search_patience', 'search_time_budget_seconds', 'keep_workspaces'] as $passthrough) {
            if (array_key_exists($passthrough, $payload)) {
                $explorerTask[$passthrough] = $payload[$passthrough];
            }
        }

        return [$explorerTask, $cleanup];
    }

    /**
     * @param  list<mixed>  $support
     */
    private function supportHas(array $support, string $path): bool
    {
        foreach ($support as $file) {
            if (is_array($file) && $this->normalizeRelative((string) ($file['path'] ?? '')) === $path) {
                return true;
            }
        }

        return false;
    }

    private function writeFile(string $base, string $relative, string $content): void
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
    private function normalizeRelative(string $path): string
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
    private function stringList(mixed $value): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $v): string => is_string($v) ? trim($v) : '',
            is_array($value) ? $value : [],
        ), static fn (string $v): bool => $v !== ''));
    }
}
