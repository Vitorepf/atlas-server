<?php

declare(strict_types=1);

namespace App\Services\Ai\Hermes;

use App\Models\AiJob;

/**
 * Shared byte-identical helper de-duplicated across this family (workspace).
 */
trait HermesWorkspaceHelper
{
    private function workspace(AiJob $job): ?string
    {
        $workspace = data_get($job->payload, 'workspace')
            ?: data_get($job->payload, 'tool_permissions.workspace')
            ?: data_get($job->metadata, 'workspace');

        if (! is_string($workspace) || trim($workspace) === '') {
            return null;
        }

        $workspace = trim($workspace);

        return realpath($workspace) ?: $workspace;
    }
}
