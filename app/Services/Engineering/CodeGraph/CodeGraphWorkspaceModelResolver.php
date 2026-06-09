<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeGraph;

use App\Models\AiCodebaseWorldModel;

/**
 * AP-815 · W-3 — workspace-aware world-model resolution.
 *
 * The readers (MCP tools / WorldModelGraphRanker / runtime invoker) historically resolved
 * "the latest world model globally" — which, once a SECOND workspace (or the cross-domain
 * model) is built, silently shadows atlas-server's graph (the exact hazard AP-814 §11
 * flagged for cross-domain persist). This resolver replaces that with SCOPE-aware lookup:
 * each workspace's symbol graph lives under scope "<workspace_id>-symbols" and its
 * module graph under scope "<workspace_id>", so a reader always gets the RIGHT graph.
 *
 * Ordering is by created_at (then id) because world-model ids are random UUIDv4 — ordering
 * by id is NOT chronological. Pure read; no writes, no clock. [php] Kernel resolution.
 */
class CodeGraphWorkspaceModelResolver
{
    public const DEFAULT_WORKSPACE = 'atlas-server';

    /**
     * Latest SYMBOL-level world model for a workspace (scope "<ws>-symbols").
     */
    public function symbolModel(string $workspaceId): ?AiCodebaseWorldModel
    {
        return $this->latestForScope($this->symbolScope($workspaceId));
    }

    /**
     * Latest MODULE-level world model for a workspace (scope "<ws>").
     */
    public function moduleModel(string $workspaceId): ?AiCodebaseWorldModel
    {
        return $this->latestForScope($this->normalize($workspaceId));
    }

    /**
     * Latest world model for an exact scope string (the chronological latest, not by id).
     */
    public function latestForScope(string $scope): ?AiCodebaseWorldModel
    {
        return AiCodebaseWorldModel::query()
            ->where('scope', $scope)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    public function symbolScope(string $workspaceId): string
    {
        return $this->normalize($workspaceId).'-symbols';
    }

    private function normalize(string $workspaceId): string
    {
        $ws = trim($workspaceId);

        return $ws !== '' ? $ws : self::DEFAULT_WORKSPACE;
    }
}
