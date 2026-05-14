<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

/**
 * Atlas Forge Gemini CLI Invocation Driver.
 *
 * Provider: `gemini_cli`. Resolves the `gemini` binary on PATH, validates
 * auth via Google env vars, runs the CLI through the safe process runner.
 */
class AtlasForgeGeminiCliInvocationDriver extends AtlasForgeBaseCliInvocationDriver
{
    public const PROVIDER = 'gemini_cli';

    public function provider(): string
    {
        return self::PROVIDER;
    }

    /** @return list<string> */
    protected function candidateBinaries(): array
    {
        return ['gemini'];
    }

    /** @return list<string> */
    protected function authEnvVars(): array
    {
        return ['GEMINI_API_KEY', 'GOOGLE_API_KEY', 'GOOGLE_GENERATIVE_AI_API_KEY'];
    }

    /** @return list<string> */
    protected function modelPrefixes(): array
    {
        return ['gemini-'];
    }
}
