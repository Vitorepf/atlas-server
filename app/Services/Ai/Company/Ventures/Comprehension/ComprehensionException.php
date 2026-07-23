<?php

namespace App\Services\Ai\Company\Ventures\Comprehension;

class ComprehensionException extends \RuntimeException
{
    public static function workspaceUnavailable(string $path): self
    {
        return new self("venture_comprehension: workspace path [{$path}] is missing or not a directory.");
    }

    public static function missingWorkspace(string $ventureId): self
    {
        return new self("venture_comprehension: venture [{$ventureId}] has no workspace_path configured (set the project repo path).");
    }

    public static function invalidCapability(string $capability): self
    {
        return new self("venture_comprehension: unknown capability [{$capability}].");
    }
}
