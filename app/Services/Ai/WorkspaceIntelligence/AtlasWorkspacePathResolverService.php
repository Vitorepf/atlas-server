<?php

declare(strict_types=1);

namespace App\Services\Ai\WorkspaceIntelligence;

use App\Services\AtlasCode\AtlasCodeWorkspaceProfileService;

final class AtlasWorkspacePathResolverService
{
    public function __construct(
        private readonly AtlasCodeWorkspaceProfileService $profiles,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function resolveForExecution(?string $requested): array
    {
        if ($requested === null || trim($requested) === '') {
            return ['status' => 'blocked', 'reason' => 'missing_workspace'];
        }

        $requested = trim($requested);
        $profile = $this->profiles->findBySlug($requested)
            ?? $this->profiles->findByPath($requested)
            ?? $this->findProfileContainingPath($requested);

        if ($profile === null) {
            return ['status' => 'blocked', 'reason' => 'workspace_not_registered'];
        }

        $workspacePath = $this->pathInsideProfile($requested, $profile)
            ? $requested
            : (string) ($profile['workspace_path'] ?: ($profile['repo_root'] ?? ''));

        if ($workspacePath === '') {
            return ['status' => 'blocked', 'reason' => 'workspace_path_missing'];
        }

        return [
            'status' => 'ready',
            'workspace_slug' => (string) $profile['slug'],
            'workspace_path' => $workspacePath,
            'profile' => $profile,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findProfileContainingPath(string $path): ?array
    {
        foreach ($this->profiles->listProfiles() as $profile) {
            if ($this->pathInsideProfile($path, $profile)) {
                return $profile;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $profile
     */
    private function pathInsideProfile(string $path, array $profile): bool
    {
        $needle = realpath($path);
        if ($needle === false) {
            return false;
        }

        foreach (['workspace_path', 'repo_root'] as $key) {
            $root = realpath((string) ($profile[$key] ?? ''));
            if ($root !== false && ($needle === $root || str_starts_with($needle, $root.DIRECTORY_SEPARATOR))) {
                return true;
            }
        }

        return false;
    }
}
