<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;


/**
 * Atlas Forge Claude CLI Invocation Driver.
 *
 * Provider: `claude_cli`. Resolves the `claude` (or `claude-code`) binary on
 * PATH, validates auth via well-known env vars, and runs the CLI through the
 * safe process runner.
 *
 * NEVER calls the provider during plan() or configured(). Only invoke() may
 * reach the binary, and only after the upstream Invocation Service has
 * validated the three confirmations + dispatch + budget + capacity.
 */
class AtlasForgeClaudeCliInvocationDriver extends AtlasForgeBaseCliInvocationDriver
{
    public const PROVIDER = 'claude_cli';

    public function provider(): string
    {
        return self::PROVIDER;
    }

    /** @return list<string> */
    protected function candidateBinaries(): array
    {
        return ['claude', 'claude-code'];
    }

    /** @return list<string> */
    protected function authEnvVars(): array
    {
        return ['ANTHROPIC_API_KEY', 'CLAUDE_API_KEY', 'CLAUDE_CODE_API_KEY'];
    }

    /** @return list<string> */
    protected function modelPrefixes(): array
    {
        return ['claude-', 'opus', 'sonnet', 'haiku'];
    }
}
