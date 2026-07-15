<?php

declare(strict_types=1);

namespace App\Services\AtlasCode;

use InvalidArgumentException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Atlas Código · C22 read-only topology projection.
 *
 * The service is deliberately small: profiles resolve the repository, Git is
 * invoked with argv arrays, and the parser is public/pure enough to be golden
 * tested without a live repository. No command here may mutate a worktree.
 */
final class AtlasCodeGraphService
{
    public const SCHEMA_VERSION = 'atlas.code.graph.v1';

    private const DEFAULT_LIMIT = 200;

    private const MAX_LIMIT = 500;

    /** @var array<string, string> */
    private array $refFingerprints = [];

    public function __construct(
        private readonly ?AtlasCodeWorkspaceProfileService $profiles = null,
        private readonly int $timeoutSeconds = 30,
        private readonly ?AtlasCodeRepoLocator $locator = null,
    ) {}

    /**
     * @return array{
     *   hash:string,
     *   parents:array<int,string>,
     *   author_name:string,
     *   author_email:string,
     *   authored_at:int,
     *   refs:array<int,string>,
     *   message:string
     * }
     */
    public function parseLogLine(string $line): array
    {
        // The subject is last on purpose: commit messages may contain '|', so
        // the bounded explode keeps them intact instead of truncating them.
        $parts = explode('|', rtrim($line, "\r\n"), 7);
        if (count($parts) !== 7 || trim($parts[0]) === '') {
            throw new InvalidArgumentException('invalid_git_log_line');
        }

        [$hash, $parents, $authorName, $authorEmail, $authoredAt, $refs, $message] = $parts;
        $timestamp = filter_var(trim($authoredAt), FILTER_VALIDATE_INT);
        if ($timestamp === false) {
            throw new InvalidArgumentException('invalid_git_author_timestamp');
        }

        return [
            'hash' => trim($hash),
            'parents' => $this->splitWhitespace($parents),
            'author_name' => trim($authorName),
            'author_email' => trim($authorEmail),
            'authored_at' => (int) $timestamp,
            'refs' => $this->splitCommaList($refs),
            'message' => trim($message),
        ];
    }

    /**
     * @return array<int, array{path:string, branch:?string, head:string}>
     */
    public function parseWorktrees(string $output): array
    {
        $worktrees = [];
        $current = [];
        $flush = function () use (&$worktrees, &$current): void {
            if (($current['path'] ?? '') === '' || ($current['head'] ?? '') === '') {
                $current = [];

                return;
            }
            $worktrees[] = [
                'path' => $current['path'],
                'branch' => $current['branch'] ?? null,
                'head' => $current['head'],
            ];
            $current = [];
        };

        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                $flush();
                continue;
            }
            if (str_starts_with($line, 'worktree ')) {
                $flush();
                $current['path'] = trim(substr($line, strlen('worktree ')));
            } elseif (str_starts_with($line, 'HEAD ')) {
                $current['head'] = trim(substr($line, strlen('HEAD ')));
            } elseif (str_starts_with($line, 'branch ')) {
                $branch = trim(substr($line, strlen('branch ')));
                $current['branch'] = preg_replace('#^refs/heads/#', '', $branch) ?: $branch;
            } elseif ($line === 'detached') {
                $current['branch'] = null;
            }
        }
        $flush();

        return $worktrees;
    }

    /**
     * @return array<string,mixed>
     */
    public function capture(string $repo, ?string $before = null, ?int $limit = null): array
    {
        // O radar mostra a frota do Mac; o grafo abre a mesma frota.
        $located = ($this->locator ?? new AtlasCodeRepoLocator($this->profiles))->locate($repo);
        $path = $located['path'];

        $log = $this->run($path, [
            'git', 'log', '--all', '--topo-order', '--parents',
            '--format=%H|%P|%an|%ae|%at|%D|%s',
        ]);
        $nodes = [];
        foreach (preg_split('/\r?\n/', trim($log)) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            try {
                $nodes[] = $this->parseLogLine($line);
            } catch (InvalidArgumentException) {
                // Git output is a trusted source, but a malformed line must
                // not poison the whole mobile projection.
                continue;
            }
        }

        if ($before !== null && trim($before) !== '') {
            $index = array_search(trim($before), array_column($nodes, 'hash'), true);
            $nodes = $index === false ? [] : array_slice($nodes, $index + 1);
        }

        $boundedLimit = max(1, min($limit ?? self::DEFAULT_LIMIT, self::MAX_LIMIT));
        $hasMore = count($nodes) > $boundedLimit;
        $nodes = array_slice($nodes, 0, $boundedLimit);

        $refs = $this->run($path, ['git', 'for-each-ref', '--format=%(objectname)']);
        $refFingerprint = hash('sha256', $refs);
        $previousFingerprint = $this->refFingerprints[$path] ?? null;
        $this->refFingerprints[$path] = $refFingerprint;

        $head = trim($this->run($path, ['git', 'rev-parse', 'HEAD']));
        $defaultBranch = trim($this->run($path, ['git', 'branch', '--show-current']));
        $worktrees = $this->parseWorktrees($this->run($path, ['git', 'worktree', 'list', '--porcelain']));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'repo' => $located['slug'],
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'head' => $head !== '' ? $head : null,
            'default_branch' => $defaultBranch !== '' ? $defaultBranch : null,
            'nodes' => $nodes,
            'worktrees' => $worktrees,
            'pagination' => [
                'limit' => $boundedLimit,
                'before' => $before,
                'has_more' => $hasMore,
            ],
            'cache' => [
                'strategy' => 'refs_fingerprint',
                'refs_fingerprint' => $refFingerprint,
                'invalidated' => $previousFingerprint !== null && $previousFingerprint !== $refFingerprint,
            ],
        ];
    }

    /**
     * @param  array<int,string>  $command
     */
    private function run(string $cwd, array $command): string
    {
        try {
            $process = new Process($command, $cwd, null, null, $this->timeoutSeconds);
            $process->run();
            if (! $process->isSuccessful()) {
                throw new InvalidArgumentException('git_command_failed');
            }

            return $process->getOutput();
        } catch (Throwable $exception) {
            if ($exception instanceof InvalidArgumentException) {
                throw $exception;
            }
            throw new InvalidArgumentException('git_command_failed');
        }
    }

    /** @return array<int,string> */
    private function splitWhitespace(string $value): array
    {
        return array_values(array_filter(preg_split('/\s+/', trim($value)) ?: []));
    }

    /** @return array<int,string> */
    private function splitCommaList(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $item): bool => $item !== ''));
    }
}
