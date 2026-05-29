<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\MinimaxFirst;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Calls the local Codex CLI (gpt-5.5) to produce a structured, ultra-precise
 * implementation plan before MiniMax is invoked.
 *
 * Codex reads the complexity of the spec and distils it into a ~200-token
 * JSON instruction. MiniMax then receives that instruction — not a raw spec
 * dump. When Codex is unavailable or times out the caller falls back gracefully.
 */
final class AtlasCodexPlannerService
{
    public const TIMEOUT_SECONDS = 90;

    /** Planning prompt must stay under this limit (characters). */
    public const MAX_PROMPT_CHARS = 2_000;

    /** @var callable|null Test seam: override Process creation. */
    private $processFactory;

    public function setProcessFactoryForTesting(callable $factory): void
    {
        $this->processFactory = $factory;
    }

    /**
     * @return array{available: bool, blocker: string|null}
     */
    public function configured(): array
    {
        $binary = $this->resolveBinary();
        if ($binary === null) {
            return ['available' => false, 'blocker' => 'codex_binary_not_found'];
        }

        return ['available' => true, 'blocker' => null];
    }

    /**
     * @param  array<string,mixed>  $finding
     * @param  list<string>         $allowedFiles
     * @param  list<string>         $validationCommands
     * @return array<string,mixed>|null  null = Codex unavailable / timed out / parse error
     */
    public function plan(array $finding, array $allowedFiles, array $validationCommands, string $repoRoot): ?array
    {
        $binary = $this->resolveBinary();
        if ($binary === null) {
            return null;
        }

        $prompt = $this->buildPlanningPrompt($finding, $allowedFiles, $validationCommands);
        $env    = $this->buildEnv();

        try {
            $process = $this->makeProcess([$binary, 'exec', '--full-auto', $prompt], $repoRoot, $env, self::TIMEOUT_SECONDS);
            $process->run();

            if (! $process->isSuccessful()) {
                return null;
            }

            return $this->parseOutput($process->getOutput());
        } catch (ProcessTimedOutException) {
            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    // ─────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────

    private function buildPlanningPrompt(array $finding, array $allowedFiles, array $validationCommands): string
    {
        $title = mb_substr((string) ($finding['title'] ?? 'implement'), 0, 120);
        $desc  = mb_substr((string) ($finding['description'] ?? ''), 0, 200);
        $files = implode(', ', array_slice($allowedFiles, 0, 5));
        $test  = $this->extractTestFilter($validationCommands);

        $prompt = "Atlas task. Reply ONLY with valid JSON, no other text.\n"
            . "Task: {$title}. {$desc}\n"
            . "Modify ONLY: {$files}\n"
            . "Must pass: {$test}\n"
            . 'JSON: {"file":string,"method":string,"signature":string,"logic":string,"constraints":[string]}';

        return mb_substr($prompt, 0, self::MAX_PROMPT_CHARS);
    }

    private function extractTestFilter(array $validationCommands): string
    {
        foreach ($validationCommands as $cmd) {
            if (str_contains((string) $cmd, '--filter=')) {
                preg_match('/--filter=([\S]+)/', (string) $cmd, $m);

                return $m[1] ?? 'php artisan test';
            }
        }

        return 'php artisan test';
    }

    /** @return array<string,string> */
    private function buildEnv(): array
    {
        $env  = ['PATH' => (string) (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin')];
        $oai  = getenv('OPENAI_API_KEY');
        $codex = getenv('CODEX_API_KEY');

        if (is_string($oai) && $oai !== '') {
            $env['OPENAI_API_KEY'] = $oai;
        }
        if (is_string($codex) && $codex !== '') {
            $env['CODEX_API_KEY'] = $codex;
        }

        return $env;
    }

    private function parseOutput(string $stdout): ?array
    {
        if (preg_match('/\{[\s\S]+\}/U', $stdout, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded) && isset($decoded['file'])) {
                return $decoded;
            }
        }

        return null;
    }

    private function resolveBinary(): ?string
    {
        foreach (['codex'] as $bin) {
            $path = trim((string) shell_exec("which {$bin} 2>/dev/null"));
            if ($path !== '') {
                return $path;
            }
        }

        return null;
    }

    /**
     * @param  list<string>          $cmd
     * @param  array<string,string>  $env
     */
    private function makeProcess(array $cmd, string $cwd, array $env, int $timeout): Process
    {
        if ($this->processFactory !== null) {
            return ($this->processFactory)($cmd, $cwd, $env, $timeout);
        }

        return new Process($cmd, $cwd, $env, null, (float) $timeout);
    }
}
