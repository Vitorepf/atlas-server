<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Vault\GraphAssembler;
use App\Services\Vault\ObsidianVaultReader;
use App\Services\Vault\RepoVaultReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Read-only HTTP surface of the Atlas Truth Cartography.
 *
 * Exposes the assembled graph, individual notes and recent changes from the
 * canonical filesystem (repo docs + AtlasVault Obsidian). Never writes — there
 * is no POST/PUT/DELETE endpoint and there will never be.
 */
final class AtlasCartographyController extends Controller
{
    public function __construct(
        private readonly GraphAssembler $assembler,
        private readonly RepoVaultReader $repoReader,
        private readonly ObsidianVaultReader $vaultReader,
    ) {
    }

    public function graph(): JsonResponse
    {
        // Cold-walk on a large Obsidian vault (5k+ notes in iCloud) can exceed
        // PHP's default 30s `max_execution_time`. Bump the cap for this request
        // only — the Cache::remember below absorbs subsequent calls.
        @set_time_limit(120);
        @ini_set('memory_limit', '512M');

        $ttl = (int) config('atlas_vault.cache_seconds', 2);
        // Bump default TTL when a large vault is in play (filesystem walk is
        // expensive). Operator can still override via env.
        if ($ttl < 30) $ttl = 30;
        $graph = $ttl > 0
            ? Cache::remember('atlas-cartography:graph', $ttl, fn () => $this->assembler->assemble())
            : $this->assembler->assemble();

        return response()->json($graph);
    }

    public function note(string $graphId): JsonResponse
    {
        $graphId = trim($graphId);
        if ($graphId === '' || ! preg_match('/^[A-Za-z0-9._:-]+$/', $graphId)) {
            return response()->json(['error' => 'invalid graph_id'], 400);
        }

        $found = $this->repoReader->read($graphId);
        $source = 'repo';
        if ($found === null) {
            $found = $this->vaultReader->read($graphId);
            $source = 'vault';
        }
        if ($found === null) {
            return response()->json([
                'graph_id' => $graphId,
                'exists' => false,
                'source' => null,
                'source_path' => null,
                'body' => null,
                'frontmatter' => null,
                'modified_at' => null,
            ], 200);
        }

        return response()->json([
            'graph_id' => $graphId,
            'exists' => true,
            'source' => $source,
            'source_path' => $found['relative_path'],
            'frontmatter' => $found['frontmatter'],
            'body' => $found['body'],
            'modified_at' => $found['mtime'] ? gmdate('Y-m-d\TH:i:s\Z', $found['mtime']) : null,
        ]);
    }

    public function recentChanges(): JsonResponse
    {
        $limit = (int) config('atlas_vault.recent_changes_limit', 12);

        // Merge two streams: git log (committed) + filesystem mtime (saved
        // since last commit / inside Vault). Filesystem stream is what makes
        // "ver a documentação nascer" possible — the canon says L2 needs
        // file-save granularity, not commit granularity (ADR-0002 P3.2).
        $gitChanges = $this->collectGitChanges($limit * 3);
        $repoMtimes = $this->collectMtimeChanges($this->repoReader->index(), 'repo', $limit * 2);
        $vaultMtimes = $this->collectMtimeChanges($this->vaultReader->index(), 'vault', $limit * 2);

        $all = array_merge($gitChanges, $repoMtimes, $vaultMtimes);
        usort($all, fn (array $a, array $b): int => ($b['timestamp'] ?? 0) - ($a['timestamp'] ?? 0));

        $enriched = [];
        $seenPaths = [];
        foreach ($all as $change) {
            $path = $change['path'] ?? '';
            if (in_array($path, $seenPaths, true)) continue;
            $seenPaths[] = $path;

            $enriched[] = [
                'graph_id' => (string) ($change['graph_id'] ?? ''),
                'name' => (string) ($change['name'] ?? $path),
                'action' => (string) ($change['action'] ?? 'edited'),
                'author' => (string) ($change['author'] ?? ''),
                'commit' => (string) ($change['commit'] ?? ''),
                'path' => $path,
                'source' => (string) ($change['source'] ?? 'repo'),
                'timestamp' => (int) ($change['timestamp'] ?? 0),
                'seconds_ago' => max(0, time() - (int) ($change['timestamp'] ?? 0)),
                'time' => gmdate('H:i', (int) ($change['timestamp'] ?? 0)),
            ];
            if (count($enriched) >= $limit) break;
        }

        return response()->json([
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'sources' => [
                'git_commits' => count($gitChanges),
                'repo_mtime' => count($repoMtimes),
                'vault_mtime' => count($vaultMtimes),
            ],
            'changes' => $enriched,
        ]);
    }

    /**
     * Convert an index map into `recent_change`-shaped rows ordered by mtime
     * desc, filtered to actually-existing files. Only keeps entries newer
     * than the oldest interesting threshold (30 days) so we don't pollute
     * the live timeline with ancient files.
     *
     * @param  array<string, array{path: string, relative_path: string, frontmatter: array<string, mixed>, mtime: int, exists: true}>  $index
     * @return list<array<string, mixed>>
     */
    private function collectMtimeChanges(array $index, string $source, int $limit): array
    {
        $cutoff = time() - 30 * 24 * 60 * 60; // 30d
        $rows = [];
        foreach ($index as $id => $entry) {
            $mtime = (int) ($entry['mtime'] ?? 0);
            if ($mtime <= 0 || $mtime < $cutoff) continue;
            $rows[] = [
                'graph_id' => (string) $id,
                'name' => (string) ($entry['frontmatter']['title'] ?? $id),
                'action' => 'saved',
                'author' => 'filesystem',
                'commit' => '',
                'path' => $entry['relative_path'] ?? '',
                'source' => $source,
                'timestamp' => $mtime,
            ];
        }
        usort($rows, fn (array $a, array $b): int => ($b['timestamp'] ?? 0) - ($a['timestamp'] ?? 0));
        return array_slice($rows, 0, $limit);
    }

    /**
     * @return list<array{path: string, action: string, author: string, timestamp: int, commit: string}>
     */
    private function collectGitChanges(int $limit): array
    {
        $repoDocs = (string) config('atlas_vault.repo_docs_path');
        if (! is_dir($repoDocs)) {
            return [];
        }
        $repoBase = base_path();
        $relative = ltrim(str_replace($repoBase, '', $repoDocs), DIRECTORY_SEPARATOR);

        $cmd = sprintf(
            'cd %s && git log --max-count=%d --name-only --pretty=format:"%%H|%%at|%%an|%%s" -- %s 2>/dev/null',
            escapeshellarg($repoBase),
            max($limit * 3, 20),
            escapeshellarg($relative)
        );
        $output = @shell_exec($cmd);
        if (! is_string($output) || trim($output) === '') {
            return [];
        }

        $lines = preg_split('/\r?\n/', $output) ?: [];
        $changes = [];
        $currentCommit = null;
        foreach ($lines as $line) {
            if (str_contains($line, '|') && substr_count($line, '|') >= 3 && ! str_ends_with($line, '.md')) {
                $parts = explode('|', $line, 4);
                $currentCommit = [
                    'commit' => $parts[0],
                    'timestamp' => (int) $parts[1],
                    'author' => $parts[2],
                    'subject' => $parts[3] ?? '',
                ];
                continue;
            }
            $path = trim($line);
            if ($path === '' || $currentCommit === null) {
                continue;
            }
            if (! str_ends_with($path, '.md')) {
                continue;
            }
            $changes[] = [
                'path' => $path,
                'action' => 'edited',
                'author' => $currentCommit['author'],
                'timestamp' => $currentCommit['timestamp'],
                'commit' => substr($currentCommit['commit'], 0, 8),
            ];
        }

        return $changes;
    }
}
