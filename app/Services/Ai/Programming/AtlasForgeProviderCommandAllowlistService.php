<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

/**
 * Atlas Forge Provider Command Allowlist.
 *
 * Validates that a provider driver only ever spawns canonical binaries
 * (`claude`, `claude-code`, `codex`, `gemini`) and that the command argv
 * cannot smuggle shell injection, redirects, sudo, network probes or
 * filesystem mutators.
 *
 * NEVER turns a string into a shell command — drivers must hand the runner
 * an explicit `argv` array. This service inspects each argv entry and
 * blocks anything that looks like:
 *
 *   - pipes/redirects (`|`, `>`, `<`, `;`, `&&`, `||`, backticks)
 *   - subshells (`$(...)`)
 *   - forbidden binaries (`rm`, `curl`, `wget`, `ssh`, `scp`, `sudo`,
 *     `chmod`, `chown`, `mv`, `cp -r`, `dd`)
 *   - escape outside the workspace cwd
 *
 * Schema: atlas.forge.provider_command_allowlist.v1
 *
 * Doc: docs/engineering-knowledge-base/atlas-forge-real-provider-drivers-v1.md
 */
class AtlasForgeProviderCommandAllowlistService
{
    public const SCHEMA_VERSION = 'atlas.forge.provider_command_allowlist.v1';

    public const BLOCKER_NOT_ALLOWED = 'provider_command_not_allowed';
    public const BLOCKER_FORBIDDEN_BINARY = 'provider_command_forbidden_binary';
    public const BLOCKER_SHELL_METACHARACTER = 'provider_command_shell_metacharacter';
    public const BLOCKER_PATH_ESCAPE = 'provider_command_path_escape';
    public const BLOCKER_EMPTY_ARGV = 'provider_command_empty_argv';

    /** @var list<string> Binaries the Atlas Forge runtime is allowed to spawn. */
    public const ALLOWED_BINARIES = [
        'claude',
        'claude-code',
        'codex',
        'gemini',
    ];

    /** @var list<string> Binaries that are NEVER allowed, even if argv[0]. */
    public const FORBIDDEN_BINARIES = [
        'rm', 'rmdir', 'mv', 'cp',
        'curl', 'wget', 'ssh', 'scp', 'rsync',
        'sudo', 'su', 'chmod', 'chown', 'chgrp',
        'dd', 'mkfs',
        'bash', 'sh', 'zsh', 'fish',
        'eval', 'exec',
        'python', 'python3', 'node', 'php',
    ];

    /**
     * @param  array<int,string>  $argv  Explicit argv (no shell parsing).
     * @return array<string,mixed>
     */
    public function evaluate(array $argv, ?string $cwd = null): array
    {
        $blockers = [];

        if ($argv === []) {
            $blockers[] = self::BLOCKER_EMPTY_ARGV;
        }

        $binary = (string) ($argv[0] ?? '');
        $binaryName = $this->basename($binary);

        if (in_array($binaryName, self::FORBIDDEN_BINARIES, true)) {
            $blockers[] = self::BLOCKER_FORBIDDEN_BINARY;
        }

        if (! in_array($binaryName, self::ALLOWED_BINARIES, true) && $argv !== []) {
            $blockers[] = self::BLOCKER_NOT_ALLOWED;
        }

        foreach ($argv as $idx => $arg) {
            if (! is_string($arg)) {
                $blockers[] = self::BLOCKER_NOT_ALLOWED;
                continue;
            }
            if ($this->containsShellMetacharacter($arg)) {
                $blockers[] = self::BLOCKER_SHELL_METACHARACTER;
            }
            // Disallow `..` traversal in argv except in literal strings the
            // runner will pass as content (we still flag if it appears as a
            // standalone path-like token).
            if (preg_match('/(^|\/)\.\.(\/|$)/', $arg) === 1 && $idx > 0) {
                $blockers[] = self::BLOCKER_PATH_ESCAPE;
            }
        }

        if ($cwd !== null && $this->containsShellMetacharacter($cwd)) {
            $blockers[] = self::BLOCKER_SHELL_METACHARACTER;
        }

        $blockers = array_values(array_unique($blockers));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'argv' => array_values(array_map(static fn ($v): string => is_string($v) ? $v : '', $argv)),
            'binary' => $binaryName,
            'binary_path' => $binary,
            'cwd' => $cwd,
            'allowed_binaries' => self::ALLOWED_BINARIES,
            'blockers' => $blockers,
            'allowed' => $blockers === [],
            'note' => 'Allowlist canonica. Drivers usam argv array; nunca shell raw.',
        ];
    }

    public function isAllowed(array $argv, ?string $cwd = null): bool
    {
        return (bool) $this->evaluate($argv, $cwd)['allowed'];
    }

    private function basename(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        // Take last path segment so allowlist works with /usr/local/bin/claude.
        $segments = preg_split('#[\\\\/]+#', $value) ?: [$value];
        $last = end($segments);

        return is_string($last) ? $last : '';
    }

    private function containsShellMetacharacter(string $value): bool
    {
        // Forbid pipes, redirects, command separators, subshells, backticks,
        // env expansion and command substitution. Provider CLIs accept the
        // prompt as a positional arg or via stdin (the runner handles stdin
        // directly, never via shell echo).
        return preg_match('/[|;&`$<>]|\$\(|\\\$\{|\\$\\(/', $value) === 1
            || strpos($value, "\n") !== false;
    }
}
