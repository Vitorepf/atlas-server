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
        $support = new AtlasLoopWorkspaceMaterializerSupport2;
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
            $support->writeWorkspaceFiles($base, $targetRel, $targetContent, $payload);

            // The frozen acceptance tests — durable bodies the loop cannot edit (the judge
            // re-runs them; the explorer treats tests/** as frozen).
        } catch (\Throwable $e) {
            $cleanup();
            throw $e;
        }

        $explorerTask = $support->buildExplorerTask($objective, $base, $acceptance, $payload, $targetRel);

        return [$explorerTask, $cleanup];
    }

    /**
     * ACDE lever #1 — when the multi-engine best-of-N portfolio is armed, agentic CLI engines edit the
     * workspace IN PLACE, so the FrozenJudge SCOPE guard must be real: derive the writable globs from
     * allowed_files (tightest correct scope) when the acceptance carries none, else the judge defaults to
     * ['**'] allow-all and an out-of-scope edit by a rotated engine is not caught. OFF => byte-identical.
     *
     * @param  array<string,mixed>  $explorerTask
     * @param  array<string,mixed>  $acceptance
     */
    private function armCrossProviderScope(array &$explorerTask, array $acceptance, bool $enabled): void
    {
        (new AtlasLoopWorkspaceMaterializerSupport2)->armCrossProviderScope($explorerTask, $acceptance, $enabled);
    }

    /**
     * @param  list<mixed>  $support
     */
    private function supportHas(array $support, string $path): bool
    {
        return (new AtlasLoopWorkspaceMaterializerSupport2)->supportHas($support, $path);
    }

    private function writeFile(string $base, string $relative, string $content): void
    {
        (new AtlasLoopWorkspaceMaterializerSupport2)->writeFile($base, $relative, $content);
    }

    /**
     * Defense in depth: keep every written path inside the scoped workspace (no
     * absolute paths, no `..` traversal). A poisoned payload cannot escape the temp dir.
     */
    private function normalizeRelative(string $path): string
    {
        return (new AtlasLoopWorkspaceMaterializerSupport2)->normalizeRelative($path);
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        return (new AtlasLoopWorkspaceMaterializerSupport2)->stringList($value);
    }
}
