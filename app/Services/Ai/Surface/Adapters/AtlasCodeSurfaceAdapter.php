<?php

namespace App\Services\Ai\Surface\Adapters;

use App\Services\Ai\Kernel\Surface\SurfaceCapability;

final class AtlasCodeSurfaceAdapter extends BaseSurfaceAdapter
{
    protected const SURFACE_ID = 'atlas_code';

    /**
     * @return array<int,string>
     */
    protected function capabilities(): array
    {
        return [
            SurfaceCapability::TEXT,
            SurfaceCapability::ATTACHMENTS,
            SurfaceCapability::FILES,
            SurfaceCapability::IMAGE_PASTE,
            SurfaceCapability::WORKSPACE_CONTEXT,
            SurfaceCapability::DOMAIN_FLOW_SELECTION,
            SurfaceCapability::ARTIFACT_RENDERING,
            SurfaceCapability::MEMORY_RECALL,
            SurfaceCapability::CONTEXT_COMPOSE,
            SurfaceCapability::TOOLS_RUNTIME,
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
                'programming.forge',
            ],
            'task_flow_map' => [
                'direct' => 'programming.forge',
                'dev' => 'programming.forge',
                'plan' => 'programming.forge',
                'forge' => 'programming.forge',
                'heavy' => 'programming.forge',
                'build' => 'programming.forge',
                'debug' => 'programming.forge',
                'repair' => 'programming.forge',
                'review' => 'programming.forge',
            ],
        ];
    }
}
