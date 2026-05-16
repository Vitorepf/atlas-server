<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Discovery;

use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Thin wrapper around the `rg` binary, scoped to Atlas Dev Discovery.
 *
 * Used to confirm candidate paths/symbols. Never used to author content.
 * Safe: arguments are passed as an array (Process avoids shell expansion),
 * cwd is bounded to the provided workspace, output is bounded by line count,
 * timeout is short.
 *
 * Absence of the binary is a first-class outcome — callers should fall back
 * to is_file() checks rather than failing the run.
 */
class RipgrepRunner
{
    public const DEFAULT_TIMEOUT_SECONDS = 5;

    public const DEFAULT_MAX_RESULTS = 64;

    /**
     * @var int seconds
     */
    private readonly int $timeoutSeconds;

    private readonly int $maxResults;

    private readonly ?string $binaryPath;

    public function __construct(
        ?string $binaryPath = null,
        int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        int $maxResults = self::DEFAULT_MAX_RESULTS,
    ) {
        $this->binaryPath = $binaryPath ?? (new ExecutableFinder)->find('rg');
        $this->timeoutSeconds = max(1, $timeoutSeconds);
        $this->maxResults = max(1, $maxResults);
    }

    public function isAvailable(): bool
    {
        return is_string($this->binaryPath) && $this->binaryPath !== '';
    }

    /**
     * Search for a fixed-string `needle` inside `workspace`.
     *
     * @param  list<string>  $includeGlobs  e.g. ['*.php', '*.ts'] (passed via -g)
     * @return list<array{file:string,line:int,text:string}> empty when binary missing, no match, or timeout
     */
    public function search(string $workspace, string $needle, array $includeGlobs = []): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        if ($needle === '' || ! is_dir($workspace)) {
            return [];
        }

        $args = [
            (string) $this->binaryPath,
            '--fixed-strings',
            '--no-heading',
            '--with-filename',
            '--line-number',
            '--color=never',
            '--max-count=8',
            '--max-filesize=2M',
            '--ignore-case',
            '-uu',
        ];

        foreach ($includeGlobs as $glob) {
            if (! is_string($glob) || $glob === '') {
                continue;
            }
            $args[] = '-g';
            $args[] = $glob;
        }

        $args[] = '--';
        $args[] = $needle;
        $args[] = '.';

        try {
            $process = new Process(
                command: $args,
                cwd: $workspace,
                env: null,
                input: null,
                timeout: (float) $this->timeoutSeconds,
            );
            $process->run();
        } catch (ExceptionInterface) {
            return [];
        }

        $exit = $process->getExitCode();
        // rg returns 1 when nothing matched — that is not an error.
        if ($exit === null || ($exit !== 0 && $exit !== 1)) {
            return [];
        }

        $output = (string) $process->getOutput();
        if ($output === '') {
            return [];
        }

        $matches = [];
        $lines = preg_split('/\R/', $output) ?: [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $parts = explode(':', $line, 3);
            if (count($parts) < 3) {
                continue;
            }
            [$file, $lineNo, $text] = $parts;
            $matches[] = [
                'file' => ltrim($file, './'),
                'line' => (int) $lineNo,
                'text' => $text,
            ];
            if (count($matches) >= $this->maxResults) {
                break;
            }
        }

        return $matches;
    }
}
