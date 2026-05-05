<?php

namespace App\Services\Ai\Surface\Adapters;

use App\Services\Ai\Kernel\Surface\SurfaceCapability;

final class AtlasCliForgeSurfaceAdapter extends BaseSurfaceAdapter
{
    protected const SURFACE_ID = 'atlas_cli_forge';

    /**
     * @return array<int,string>
     */
    protected function capabilities(): array
    {
        return [
            SurfaceCapability::TEXT,
            SurfaceCapability::FILES,
            SurfaceCapability::IMAGE_PASTE,
            SurfaceCapability::WORKSPACE_CONTEXT,
            SurfaceCapability::DOMAIN_FLOW_SELECTION,
            SurfaceCapability::ARTIFACT_RENDERING,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function domainFlowHints(): array
    {
        return [
            'default_domain_id' => 'programming',
            'default_flow_id' => 'programming.forge',
            'prefer_default_flow' => true,
            'supported_domain_ids' => ['programming'],
            'supported_flow_ids' => [
                'programming.dev',
                'programming.forge',
                'programming.repair',
                'programming.review',
            ],
            'task_flow_map' => [
                'dev' => 'programming.dev',
                'forge' => 'programming.forge',
                'heavy' => 'programming.forge',
                'build' => 'programming.forge',
                'debug' => 'programming.repair',
                'repair' => 'programming.repair',
                'review' => 'programming.review',
                'plan' => 'programming.forge',
            ],
        ];
    }
}
