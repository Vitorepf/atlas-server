<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\MinimaxFirst;

use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevStringListNormalizer;
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
            $process = $this->makeProcess([
                $binary,
                'exec',
                '--sandbox',
                'read-only',
                '--ephemeral',
                '--cd',
                $repoRoot,
                $prompt,
            ], $repoRoot, $env, self::TIMEOUT_SECONDS);
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
        $desc  = mb_substr($this->bestNarrative($finding), 0, 500);
        $files = implode(', ', array_slice($allowedFiles, 0, 5));
        $test  = $this->extractTestFilter($validationCommands);
        $anchor = $this->anchorSummary($finding);

        $prompt = "Atlas task. Planning only. Reply ONLY with valid JSON, no other text.\n"
            . "Do not run shell commands. Do not execute tests. Do not edit files.\n"
            . "Task: {$title}. {$desc}\n"
            . ($anchor !== '' ? "Anchor: {$anchor}\n" : '')
            . "Modify ONLY: {$files}\n"
            . "Focused validation command: {$test}\n"
            . "Quality: preserve existing methods/tests; append or narrowly adjust focused tests; no large test deletion; no comment-only/no-op/scaffold output.\n"
            . 'JSON: {"file":string,"method":string,"signature":string,"logic":string,"constraints":[string]}';

        return mb_substr($prompt, 0, self::MAX_PROMPT_CHARS);
    }

    private function bestNarrative(array $finding): string
    {
        $parts = [];
        foreach (['description', 'detail', 'why_it_matters', 'proposed_next_action'] as $key) {
            $value = $this->compactScalar($finding[$key] ?? null, 700);
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        $packetObjective = $this->compactScalar($this->nestedValue($finding, 'self_construction_packet.objective'), 700);
        if ($packetObjective !== '') {
            $parts[] = $packetObjective;
        }

        return implode(' ', AtlasDevStringListNormalizer::uniqueTrimmedStrings($parts));
    }

    private function anchorSummary(array $finding): string
    {
        $parts = [];
        foreach (['target_method', 'target_symbol', 'method_anchor', 'surgical_anchor', 'mutation_anchor'] as $key) {
            $value = $this->compactScalar($finding[$key] ?? $this->nestedValue($finding, 'self_construction_packet.'.$key), 300);
            if ($value !== '') {
                $parts[] = "{$key}={$value}";
            }
        }

        return implode('; ', $parts);
    }

    private function compactScalar(mixed $value, int $limit): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $text = trim((string) $value);
        if ($text === '') {
            return '';
        }

        return mb_substr(preg_replace('/\s+/', ' ', $text) ?? $text, 0, $limit);
    }

    private function nestedValue(array $array, string $path): mixed
    {
        $value = $array;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    private function extractTestFilter(array $validationCommands): string
    {
        foreach ($validationCommands as $cmd) {
            $command = trim((string) $cmd);
            if ($command === '') {
                continue;
            }

            if (preg_match('/^php artisan test\s+tests\/[A-Za-z0-9_\/.-]+\.php(?:\s+--stop-on-failure)?$/', $command) === 1) {
                return $command;
            }

            if (preg_match('/^\.\/vendor\/bin\/phpunit\s+--configuration=phpunit\.xml\s+tests\/[A-Za-z0-9_\/.-]+\.php(?:\s+--stop-on-failure)?$/', $command) === 1) {
                return $command;
            }

            if (str_contains($command, '--filter=')) {
                return $command;
            }
        }

        return 'focused validation command not declared';
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
