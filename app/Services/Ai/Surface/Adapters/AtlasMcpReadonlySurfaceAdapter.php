<?php

namespace App\Services\Ai\Surface\Adapters;

use App\Services\Ai\Kernel\Surface\SurfaceCapability;

final class AtlasMcpReadonlySurfaceAdapter extends BaseSurfaceAdapter
{
    protected const SURFACE_ID = 'atlas_mcp_readonly';

    /**
     * @return array<int,string>
     */
    protected function capabilities(): array
    {
        return [
            SurfaceCapability::MEMORY_RECALL,
            SurfaceCapability::CONTEXT_COMPOSE,
        ];
    }
}
