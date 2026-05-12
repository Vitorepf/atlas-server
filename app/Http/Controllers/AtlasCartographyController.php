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
        $ttl = (int) config('atlas_vault.cache_seconds', 2);
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
        $limit = (int) config('atlas_vault.recent_changes_limit', 8);
        $changes = $this->collectGitChanges($limit);
        $repoIndex = $this->repoReader->index();

        $enriched = [];
        $seenPaths = [];
        foreach ($changes as $change) {
            if (in_array($change['path'], $seenPaths, true)) {
                continue;
            }
            $seenPaths[] = $change['path'];

            $matched = null;
            foreach ($repoIndex as $id => $entry) {
                if ($entry['relative_path'] === $change['path']) {
                    $matched = ['graph_id' => $id, 'name' => $entry['frontmatter']['title'] ?? $id];
                    break;
                }
            }
            if ($matched === null) {
                continue;
            }
            $enriched[] = array_merge($change, [
                'graph_id' => $matched['graph_id'],
                'name' => $matched['name'],
                'seconds_ago' => max(0, time() - $change['timestamp']),
                'time' => gmdate('H:i', $change['timestamp']),
                'source' => 'repo',
            ]);
            if (count($enriched) >= $limit) {
                break;
            }
        }

        return response()->json([
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'changes' => $enriched,
        ]);
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
