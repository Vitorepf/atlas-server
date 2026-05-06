<?php

namespace App\Services\Ai\Surface\Adapters;

use App\Services\Ai\Kernel\Surface\SurfaceCapability;

final class AtlasAppSurfaceAdapter extends BaseSurfaceAdapter
{
    protected const SURFACE_ID = 'atlas_app';

    /**
     * @return array<int,string>
     */
    protected function capabilities(): array
    {
        return [
            SurfaceCapability::TEXT,
            SurfaceCapability::ATTACHMENTS,
            SurfaceCapability::IMAGE_UPLOADS,
            SurfaceCapability::THREAD_CONTEXT,
            SurfaceCapability::DOMAIN_FLOW_SELECTION,
            SurfaceCapability::MEMORY_RECALL,
            SurfaceCapability::CONTEXT_COMPOSE,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function domainFlowHints(): array
    {
        return [
            'accepts_catalog_domain_flow_selection' => true,
            'default_domain_id' => 'general',
            'default_flow_id' => 'general.answer',
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
