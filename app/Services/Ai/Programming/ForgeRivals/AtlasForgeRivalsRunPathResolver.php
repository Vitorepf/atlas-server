<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use InvalidArgumentException;
use RuntimeException;

/**
 * Atlas Forge Rivals · Run Path Resolver.
 *
 * Resolves the canonical filesystem layout for an isolated rivals run:
 *
 *   /Users/vitorepf/develop/Atlas-rivals/runs/<run_id>/
 *     ├── atlas/          (worktree for the Atlas Forge arm)
 *     ├── rival/          (worktree for the rival baseline arm)
 *     ├── evidence/       (manifest.json, receipts, scorecard, report.md)
 *     └── events.jsonl    (append-only streaming log)
 *
 * Path traversal is impossible: run_id must match `[A-Za-z0-9_\-.]{1,128}`,
 * never contains `..`, and the final base path is verified to start with the
 * configured root prefix.
 *
 * NOTE: This service does not touch the filesystem. Directory creation,
 * `git worktree add`, hygiene checks live in the Slice 1+ Setup service.
 */
final class AtlasForgeRivalsRunPathResolver
{
    public const DEFAULT_ROOT = '/Users/vitorepf/develop/Atlas-rivals/runs';

    public const RUN_ID_PATTERN = '/^[A-Za-z0-9_\-.]{1,128}$/';

    public function rootDirectory(): string
    {
        $candidate = (string) (function_exists('config')
            ? (config('atlas_rivals.runs_root') ?? self::DEFAULT_ROOT)
            : self::DEFAULT_ROOT);
        $candidate = rtrim(trim($candidate), '/');
        if ($candidate === '') {
            return self::DEFAULT_ROOT;
        }

        return $candidate;
    }

    /**
     * @return array{
     *   run_id:string,
     *   root:string,
     *   base:string,
     *   atlas:string,
     *   rival:string,
     *   evidence:string,
     *   events_jsonl:string,
     *   manifest_json:string,
     *   scorecard_json:string,
     *   scorecard_v2_json:string,
     *   report_md:string
     * }
     */
    public function paths(string $runId): array
    {
        $id = $this->sanitize($runId);
        $root = $this->rootDirectory();
        $base = $root.'/'.$id;
        if (! str_starts_with($base, $root.'/')) {
            throw new RuntimeException("Resolved path escaped root: {$base}");
        }
        $evidence = $base.'/evidence';

        return [
            'run_id' => $id,
            'root' => $root,
            'base' => $base,
            'atlas' => $base.'/atlas',
            'rival' => $base.'/rival',
            'evidence' => $evidence,
            'events_jsonl' => $base.'/events.jsonl',
            'manifest_json' => $evidence.'/manifest.json',
            'scorecard_json' => $evidence.'/scorecard.json',
            'scorecard_v2_json' => $evidence.'/scorecard.v2.json',
            'report_md' => $evidence.'/report.md',
        ];
    }

    private function sanitize(string $runId): string
    {
        $id = trim($runId);
        if ($id === '') {
            throw new InvalidArgumentException('run_id must not be empty.');
        }
        if (str_contains($id, '..') || str_contains($id, '/') || str_contains($id, "\0")) {
            throw new InvalidArgumentException("Invalid run_id (traversal-like): '{$runId}'.");
        }
        if (! preg_match(self::RUN_ID_PATTERN, $id)) {
            throw new InvalidArgumentException(
                "Invalid run_id format: '{$runId}'. Must match ".self::RUN_ID_PATTERN.'.'
            );
        }

        return $id;
    }
}
