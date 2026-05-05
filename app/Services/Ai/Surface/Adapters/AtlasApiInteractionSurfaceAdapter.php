<?php

namespace App\Services\Ai\Surface\Adapters;

use App\Services\Ai\Kernel\Surface\SurfaceCapability;

final class AtlasApiInteractionSurfaceAdapter extends BaseSurfaceAdapter
{
    protected const SURFACE_ID = 'atlas_api_interaction';

    /**
     * @return array<int,string>
     */
    protected function capabilities(): array
    {
        return [
            SurfaceCapability::TEXT,
            SurfaceCapability::ATTACHMENTS,
            SurfaceCapability::THREAD_CONTEXT,
            SurfaceCapability::DOMAIN_FLOW_SELECTION,
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
        ];
    }
}
