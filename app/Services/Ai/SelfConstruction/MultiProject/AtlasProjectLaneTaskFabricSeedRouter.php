<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\MultiProject;

/**
 * Routes Task Fabric seeds to the correct project lane from
 * workspace, capability and allowed_files evidence.
 *
 * Read-only: never starts processes, never calls providers.
 */
final class AtlasProjectLaneTaskFabricSeedRouter
{
    public const SCHEMA = 'atlas.self_construction.project_lane_task_fabric_seed_router.v1';

    /**
     * @param  array<string, mixed>  $seed
     * @return array<string, mixed>
     */
    public function route(array $seed): array
    {
        $workspace = (string) ($seed['workspace'] ?? '');
        $capability = (string) ($seed['capability'] ?? '');
        $allowedFiles = (array) ($seed['allowed_files'] ?? []);

        $blockers = [];

        if ($workspace === '') {
            $blockers[] = 'missing_workspace';
        }
        if ($capability === '') {
            $blockers[] = 'missing_capability';
        }
        if ($allowedFiles === []) {
            $blockers[] = 'missing_allowed_files';
        }

        // Check for ambiguous workspace evidence (empty or relative multi-dot)
        if ($workspace !== '' && ! str_starts_with($workspace, '/')) {
            $blockers[] = 'ambiguous_workspace';
        }

        if ($blockers !== []) {
            return [
                'schema' => self::SCHEMA,
                'routed' => false,
                'lane' => null,
                'blockers' => $blockers,
                'workspace' => $workspace,
                'capability' => $capability,
            ];
        }

        // Route to lane based on workspace name
        $lane = $this->resolveLane($workspace, $allowedFiles);

        return [
            'schema' => self::SCHEMA,
            'routed' => true,
            'lane' => $lane,
            'blockers' => [],
            'workspace' => $workspace,
            'capability' => $capability,
            'allowed_files' => $allowedFiles,
        ];
    }

    private function resolveLane(string $workspace, array $allowedFiles): string
    {
        // Extract project name from workspace path
        $parts = explode('/', rtrim($workspace, '/'));
        $projectName = end($parts) ?: 'default';

        return 'lane:'.$projectName;
    }
}
