<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Engineering\AtlasUniversalRealityCartographyService;
use App\Services\Vault\GraphAssembler;
use App\Services\Vault\ObsidianVaultReader;
use App\Services\Vault\RepoVaultReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        private readonly AtlasUniversalRealityCartographyService $universalRealityCartography,
    ) {}

    public function graph(Request $request): JsonResponse
    {
        // Cold-walk on a large Obsidian vault (5k+ notes in iCloud) can exceed
        // PHP's default 30s `max_execution_time` and 128M `memory_limit`. Lift
        // both for this request only — raise-only, never clamping a CLI/test
        // context that already granted more (the Cache::remember below absorbs
        // subsequent calls).
        $this->ensureGraphTimeBudget(120);
        $this->ensureGraphMemoryFloor();

        $ttl = (int) config('atlas_vault.cache_seconds', 2);
        // Bump default TTL when a large vault is in play (filesystem walk is
        // expensive). Operator can still override via env.
        if ($ttl < 30) {
            $ttl = 30;
        }
        $graph = $ttl > 0
            ? Cache::remember('atlas-cartography:graph', $ttl, fn () => $this->assembler->assemble())
            : $this->assembler->assemble();

        $workspace = $this->stringQuery($request, 'workspace');
        $clarity = $this->universalRealityCartography->map('flow', $workspace);
        $graph['workspace_scope'] = $clarity['workspace_scope'];
        $graph['human_clarity_contract'] = [
            'workspace_scope' => $clarity['workspace_scope'],
            'human_clarity' => $clarity['human_clarity'],
            'visual_scene' => $clarity['visual_scene'],
            'human_route_map' => $clarity['human_route_map'],
            'semantic_zoom_scenes' => $clarity['semantic_zoom_scenes'],
            'writes' => false,
        ];
        $graph['checksum'] = hash('sha256', json_encode([
            'base_checksum' => $graph['checksum'] ?? null,
            'workspace_scope' => $graph['workspace_scope'],
        ], JSON_THROW_ON_ERROR));

        return response()->json($graph);
    }

    /**
     * Extend this request's execution time to at least $seconds for the cold
     * vault walk — but never shorten an already-larger or unlimited budget. In
     * php-fpm (default 30s) the full repo+vault assembly needs the headroom. In
     * a CLI/test context `max_execution_time` is 0 (unlimited); clamping that
     * down to a finite ceiling leaks into the rest of the process and would
     * hard-kill unrelated slow jobs later (e.g. the reality-audit CLI, which
     * expects unlimited PHP time and self-governs via its own wall-clock budget).
     */
    private function ensureGraphTimeBudget(int $seconds): void
    {
        $current = (int) ini_get('max_execution_time');
        // 0 == unlimited (CLI default): already higher than any finite budget.
        if ($current === 0) {
            return;
        }

        if ($current >= $seconds) {
            return;
        }

        @set_time_limit($seconds);
    }

    /**
     * Raise this request's memory ceiling to a 512M floor for the cartography
     * walk — but never *lower* it. In php-fpm (default 128M) the full repo+vault
     * graph assembly needs the headroom, so we lift the cap. In a CLI/test
     * context that already granted more (phpunit grants 1024M), or an unlimited
     * process, clamping back down to 512M would starve the process under the
     * accumulated peak of repeated graph builds — so we leave the higher limit
     * intact. Mirrors AtlasAiArchitectureValidationService::ensureStaticScanMemoryFloor.
     */
    private function ensureGraphMemoryFloor(): void
    {
        $current = ini_get('memory_limit');
        if ($current === false || $current === '-1') {
            return;
        }

        if ($this->memoryLimitToBytes($current) >= 512 * 1024 * 1024) {
            return;
        }

        @ini_set('memory_limit', '512M');
    }

    private function memoryLimitToBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $amount = (int) $value;

        return match ($unit) {
            'g' => $amount * 1024 * 1024 * 1024,
            'm' => $amount * 1024 * 1024,
            'k' => $amount * 1024,
            default => $amount,
        };
    }

    public function humanClarity(Request $request): JsonResponse
    {
        $payload = $this->universalRealityCartography->map('flow', $this->stringQuery($request, 'workspace'));

        return response()->json([
            'schema_version' => $payload['schema_version'],
            'status' => data_get($payload, 'human_clarity.status') === 'ready' ? $payload['status'] : 'review',
            'workspace_scope' => $payload['workspace_scope'],
            'human_clarity' => $payload['human_clarity'],
            'visual_scene' => $payload['visual_scene'],
            'human_route_map' => $payload['human_route_map'],
            'semantic_zoom_scenes' => $payload['semantic_zoom_scenes'],
            'claim_policy' => $payload['claim_policy'],
            'writes' => false,
        ]);
    }

    public function note(string $graphId): JsonResponse
    {
        $graphId = trim($graphId);
        if ($graphId === '' || str_contains($graphId, '/') || str_contains($graphId, '\\') || str_contains($graphId, "\0")) {
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

    /**
     * Server-Sent Events stream that signals the client when the canonical
     * graph mutates. Strategy: every 2s, read the current assembler checksum
     * from cache (or recompute) and compare to the last broadcast value. When
     * it moves, emit `event: graph_changed` with the new checksum. Otherwise
     * a heartbeat goes out every 15s so the client knows the channel is alive.
     *
     * The connection is bounded to ~25s so `php artisan serve` (single-thread
     * worker) recycles cleanly; the EventSource on the client auto-reconnects.
     * In production behind php-fpm this bound becomes unnecessary.
     */
    public function stream(): StreamedResponse
    {
        @set_time_limit(30);
        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', '0');

        $response = new StreamedResponse(function (): void {
            $deadline = microtime(true) + 25.0;
            $lastChecksum = null;
            $lastHeartbeat = 0.0;

            // Initial emit: send current checksum so the client aligns its state
            // immediately on connect (no need to wait for the first mutation).
            $initial = $this->currentChecksum();
            if ($initial !== null) {
                $this->emitSse('graph_changed', ['checksum' => $initial, 'reason' => 'connect']);
                $lastChecksum = $initial;
            }

            while (microtime(true) < $deadline) {
                if (connection_aborted()) {
                    return;
                }
                $now = microtime(true);
                $checksum = $this->currentChecksum();
                if ($checksum !== null && $checksum !== $lastChecksum) {
                    $this->emitSse('graph_changed', ['checksum' => $checksum, 'reason' => 'mutation']);
                    $lastChecksum = $checksum;
                }
                if ($now - $lastHeartbeat >= 15.0) {
                    $this->emitSse('heartbeat', ['at' => gmdate('Y-m-d\TH:i:s\Z')]);
                    $lastHeartbeat = $now;
                }
                usleep(2_000_000); // 2s
            }
            // Polite close so the client knows the bound was reached and reconnects.
            $this->emitSse('reconnect', ['reason' => 'worker_cycle']);
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->headers->set('X-Accel-Buffering', 'no'); // disables nginx buffering
        $response->headers->set('Connection', 'keep-alive');

        return $response;
    }

    /**
     * Read the cached checksum or compute it. Cheaper than `assemble()` on
     * each tick because Laravel's cache TTL absorbs the repeat reads.
     */
    private function currentChecksum(): ?string
    {
        $ttl = (int) config('atlas_vault.cache_seconds', 2);
        if ($ttl < 30) {
            $ttl = 30;
        }
        $graph = Cache::remember('atlas-cartography:graph', $ttl, fn () => $this->assembler->assemble());

        return is_array($graph) && isset($graph['checksum']) ? (string) $graph['checksum'] : null;
    }

    /**
     * SSE frame · `event: <name>\ndata: <json>\n\n` + flush.
     *
     * @param  array<string, mixed>  $data
     */
    private function emitSse(string $event, array $data): void
    {
        echo 'event: '.$event."\n";
        echo 'data: '.json_encode($data)."\n\n";
        @ob_flush();
        @flush();
    }

    private function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
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
            if (in_array($path, $seenPaths, true)) {
                continue;
            }
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
            if (count($enriched) >= $limit) {
                break;
            }
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
            if ($mtime <= 0 || $mtime < $cutoff) {
                continue;
            }
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
