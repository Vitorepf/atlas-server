<?php

namespace App\Services\Ai\Hermes;

use Illuminate\Filesystem\Filesystem;

/**
 * GUARANTEES Hermes's self-learning survives an Atlas-MANAGED HERMES_HOME relocation.
 *
 * Hermes persists everything it LEARNS inside its home (~/.hermes): the session/curator
 * `state.db` (+ `-wal`/`-shm`), `skills/`, `skill-bundles/`, MCP `mcp-tokens/`, credentials
 * `.env`, optional `MEMORY.md`/`USER.md`, and a top-level `memory:` block in `config.yaml`.
 * When Atlas relocates HERMES_HOME to an ephemeral managed dir (see
 * {@see HermesManagedMcpConfigProvisioner}), Hermes would write its learning into that dir
 * and lose it on teardown. This linker SYMLINKS the operator's real learning state INTO the
 * managed home (links point AT the operator's files — never copied, never mutated) and reads
 * the operator's `memory:` config so the managed config.yaml can keep memory enabled.
 *
 * SAFETY: never reads/writes/deletes inside the operator's real home; only creates symlinks
 * inside the managed home and READS (never writes) the operator config.yaml. Never throws.
 * Idempotent — running twice does not error and does not duplicate links.
 */
class HermesLearningHomeLinker
{
    /**
     * Exact names of the learning assets Hermes persists in its home.
     *
     * @var list<string>
     */
    private const LEARNING_ASSETS = [
        'state.db',
        'state.db-wal',
        'state.db-shm',
        'skills',
        'skill-bundles',
        'mcp-tokens',
        'plugins',
        '.env',
        'MEMORY.md',
        'USER.md',
    ];

    public function __construct(
        private readonly Filesystem $files,
    ) {}

    /**
     * Symlinks each learning asset that EXISTS in the operator home and is NOT already
     * present/linked in the managed home. Skips (never errors) absent operator assets and
     * managed entries that already exist. Only adds links into the managed home; never
     * copies and never writes into the operator home.
     *
     * @return array{operator_home: string|null, linked: list<string>, skipped: list<string>}
     */
    public function linkLearningState(string $managedHome, ?string $operatorHome = null): array
    {
        $operatorHome ??= $this->operatorHome();

        if ($operatorHome === null || ! $this->files->isDirectory($operatorHome)) {
            return ['operator_home' => null, 'linked' => [], 'skipped' => []];
        }

        $managedHome = rtrim($managedHome, '/');
        $this->files->ensureDirectoryExists($managedHome, 0700);

        $linked = [];
        $skipped = [];

        foreach (self::LEARNING_ASSETS as $asset) {
            $target = $operatorHome.'/'.$asset;
            $link = $managedHome.'/'.$asset;

            // Absent in the operator home, or already present/linked in the managed home.
            if (! file_exists($target) || file_exists($link) || is_link($link)) {
                $skipped[] = $asset;

                continue;
            }

            if (@symlink($target, $link)) {
                $linked[] = $asset;
            } else {
                $skipped[] = $asset;
            }
        }

        return ['operator_home' => $operatorHome, 'linked' => $linked, 'skipped' => $skipped];
    }

    /**
     * Reads (text) operatorHome/config.yaml and extracts the TOP-LEVEL `memory:` block so the
     * managed config.yaml can keep memory enabled. Line-based parse (symfony/yaml may be absent).
     * Returns [] when there is no config.yaml or no memory block. Never throws on malformed input.
     *
     * @return array<string,mixed>
     */
    public function memoryConfig(?string $operatorHome = null): array
    {
        $operatorHome ??= $this->operatorHome();

        if ($operatorHome === null) {
            return [];
        }

        $configPath = $operatorHome.'/config.yaml';
        if (! is_file($configPath) || ! is_readable($configPath)) {
            return [];
        }

        $raw = @file_get_contents($configPath);
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];

        $inBlock = false;
        $config = [];

        foreach ($lines as $line) {
            if (! $inBlock) {
                if (preg_match('/^memory:\s*$/', $line) === 1) {
                    $inBlock = true;
                }

                continue;
            }

            // A line back at column 0 ends the block (blank lines stay inside it).
            if (trim($line) !== '' && preg_match('/^\s/', $line) !== 1) {
                break;
            }

            if (trim($line) === '') {
                continue;
            }

            if (preg_match('/^\s+([A-Za-z0-9_]+)\s*:\s*(.*?)\s*$/', $line, $m) !== 1) {
                continue;
            }

            $key = $m[1];
            $value = $this->stripInline($m[2]);

            if ($value === '') {
                // Keep the LAST non-empty value if the block repeats the key.
                continue;
            }

            $config[$key] = $this->coerce($key, $value);
        }

        return $config;
    }

    /**
     * Resolution helper: env HERMES_HOME, else HOME/.hermes. Public so callers/tests can use it.
     */
    public function operatorHome(): ?string
    {
        $env = getenv('HERMES_HOME');
        if (is_string($env) && trim($env) !== '') {
            return rtrim(trim($env), '/');
        }

        $base = getenv('HOME');

        return is_string($base) && trim($base) !== '' ? rtrim(trim($base), '/').'/.hermes' : null;
    }

    /**
     * Strips surrounding quotes and a trailing `# comment` from a scalar YAML value.
     */
    private function stripInline(string $value): string
    {
        $value = trim($value);

        // Quoted scalar: take the quoted span, discard anything after the closing
        // quote (e.g. a trailing `# comment`). Handles `"2200" # cap` and `'x' #y`.
        if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
            $quote = $value[0];
            $close = strpos($value, $quote, 1);
            if ($close !== false) {
                return substr($value, 1, $close - 1);
            }

            // Unterminated quote: drop the leading quote, fall through to comment strip.
            $value = substr($value, 1);
        }

        // Unquoted scalar: drop an inline trailing comment.
        if (($hash = strpos($value, '#')) !== false) {
            $value = substr($value, 0, $hash);
        }

        return trim($value);
    }

    private function coerce(string $key, string $value): mixed
    {
        if ($key === 'memory_enabled') {
            return strtolower($value) === 'true';
        }

        if ($key === 'memory_char_limit') {
            return (int) $value;
        }

        return $value;
    }
}
