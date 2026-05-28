<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

/**
 * Atlas Forge Codex CLI Invocation Driver.
 *
 * Provider: `codex_cli`. Resolves the `codex` binary on PATH, validates auth
 * via OPENAI/CODEX env vars, runs the CLI through the safe process runner.
 *
 * This driver does NOT — and must not — use the host model that is processing
 * the Atlas session as a bypass. It exclusively runs the locally configured
 * `codex` CLI under the allowlist + safe runner.
 */
class AtlasForgeCodexCliInvocationDriver extends AtlasForgeBaseCliInvocationDriver
{
    public const PROVIDER = 'codex_cli';

    public static function focusedUnitTestPath(): string
    {
        return 'tests/Unit/Ai/Programming/AtlasForgeCodexCliInvocationDriverTest.php';
    }

    public function provider(): string
    {
        return self::PROVIDER;
    }

    /** @return list<string> */
    protected function candidateBinaries(): array
    {
        return ['codex'];
    }

    /** @return list<string> */
    protected function authEnvVars(): array
    {
        return ['OPENAI_API_KEY', 'CODEX_API_KEY'];
    }

    /** @return list<string> */
    protected function modelPrefixes(): array
    {
        return ['gpt-', 'codex', 'o1', 'o3'];
    }
}
