<?php

namespace App\Services\Ai\Surface\Adapters;

use App\Services\Ai\Kernel\Surface\SurfaceCapability;

final class AtlasCliDevSurfaceAdapter extends BaseSurfaceAdapter
{
    protected const SURFACE_ID = 'atlas_cli_dev';

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
            'default_flow_id' => 'programming.dev',
            'supported_domain_ids' => ['programming'],
            'supported_flow_ids' => [
                'programming.dev',
                'programming.review',
                'programming.repair',
            ],
            'task_flow_map' => [
                'dev' => 'programming.dev',
                'debug' => 'programming.repair',
                'repair' => 'programming.repair',
                'review' => 'programming.review',
                'plan' => 'programming.dev',
            ],
        ];
    }
}
