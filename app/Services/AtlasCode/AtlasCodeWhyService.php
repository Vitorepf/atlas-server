<?php

declare(strict_types=1);

namespace App\Services\AtlasCode;

use InvalidArgumentException;
use Symfony\Component\Process\Process;
use Throwable;

/** H1 · file-level biography from Git history plus C23 provenance ledger. */
final class AtlasCodeWhyService
{
    public const SCHEMA_VERSION = 'atlas.code.why.v1';

    private const DEFAULT_LIMIT = 20;

    private const MAX_LIMIT = 100;

    public function __construct(
        private readonly ?AtlasCodeRepoLocator $locator = null,
        private readonly ?AtlasCodeProvenanceService $provenance = null,
        private readonly int $timeoutSeconds = 30,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function capture(string $repo, string $file, ?int $limit = null, ?int $line = null): array
    {
        $relativeFile = $this->validateFile($file);
        $boundedLimit = max(1, min($limit ?? self::DEFAULT_LIMIT, self::MAX_LIMIT));
        $located = ($this->locator ?? new AtlasCodeRepoLocator())->locate($repo);

        $rows = $this->commitsForFile($located['path'], $relativeFile);
        $commitsTotal = count($rows);
        $visible = array_slice($rows, 0, $boundedLimit);
        $provenance = $this->provenance ?? new AtlasCodeProvenanceService(locator: $this->locator);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'repo' => $located['slug'],
            'file' => $relativeFile,
            'commits_total' => $commitsTotal,
            'truncated' => $commitsTotal > $boundedLimit,
            'commits' => array_map(fn (array $row): array => $this->shapeCommit($row, $located['slug'], $provenance), $visible),
        ];
    }

    private function validateFile(string $file): string
    {
        $value = trim($file);
        if ($value === ''
            || str_starts_with($value, '/')
            || str_contains($value, "\0")
            || str_contains($value, '\\')
        ) {
            throw new InvalidArgumentException('invalid_file');
        }

        $segments = explode('/', $value);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('invalid_file');
            }
        }

        return $value;
    }

    /**
     * @return array<int,array{hash:string,author_email:string,authored_at:int,subject:string}>
     */
    private function commitsForFile(string $cwd, string $file): array
    {
        $output = $this->run($cwd, [
            'git', 'log', '--follow', '--format=%H%x1f%ae%x1f%at%x1f%s', '--', $file,
        ]);

        $rows = [];
        foreach (preg_split('/\r?\n/', trim($output)) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $parts = explode("\x1f", $line, 4);
            if (count($parts) !== 4 || preg_match('/^[0-9a-f]{40}$/i', $parts[0]) !== 1) {
                continue;
            }
            $timestamp = filter_var($parts[2], FILTER_VALIDATE_INT);
            if ($timestamp === false) {
                continue;
            }
            $rows[] = [
                'hash' => strtolower($parts[0]),
                'author_email' => trim($parts[1]),
                'authored_at' => (int) $timestamp,
                'subject' => trim($parts[3]),
            ];
        }

        return $rows;
    }

    /**
     * @param  array{hash:string,author_email:string,authored_at:int,subject:string}  $row
     * @return array<string,mixed>
     */
    private function shapeCommit(array $row, string $repo, AtlasCodeProvenanceService $provenance): array
    {
        $commit = [
            'hash' => $row['hash'],
            'when' => gmdate('Y-m-d\TH:i:s\Z', $row['authored_at']),
            'agent' => $provenance->agentForAuthor($row['author_email']),
            'subject' => $row['subject'],
            'provenance' => null,
        ];

        try {
            $captured = $provenance->capture($row['hash'], $repo);
        } catch (Throwable) {
            return $commit;
        }

        $quote = $this->stringOrNull($captured['operator_quote'] ?? null);
        if ($quote === null) {
            return $commit;
        }

        $commit['provenance'] = [
            'quote' => $quote,
            'obra' => $this->firstString($captured['obra'] ?? null),
            'gates' => $this->stringList($captured['gates'] ?? []),
        ];

        return $commit;
    }

    /**
     * @param  array<int,string>  $command
     */
    private function run(string $cwd, array $command): string
    {
        try {
            $process = new Process($command, $cwd, null, null, $this->timeoutSeconds);
            $process->run();
        } catch (Throwable) {
            throw new InvalidArgumentException('git_unavailable');
        }

        if (! $process->isSuccessful()) {
            throw new InvalidArgumentException('git_unavailable');
        }

        return $process->getOutput();
    }

    private function firstString(mixed $value): ?string
    {
        $direct = $this->stringOrNull($value);
        if ($direct !== null) {
            return $direct;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                $found = $this->firstString($item);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $item): ?string => $this->stringOrNull($item),
            $value,
        )));
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
