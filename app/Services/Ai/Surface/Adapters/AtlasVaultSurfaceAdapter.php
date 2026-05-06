<?php

namespace App\Services\Ai\Surface\Adapters;

use App\Services\Ai\Kernel\Surface\SurfaceCapability;

final class AtlasVaultSurfaceAdapter extends BaseSurfaceAdapter
{
    protected const SURFACE_ID = 'atlas_vault';

    /**
     * @return array<int,string>
     */
    protected function capabilities(): array
    {
        return [
            SurfaceCapability::TEXT,
            SurfaceCapability::FILES,
            SurfaceCapability::HUMAN_KNOWLEDGE_WORKSPACE,
            SurfaceCapability::MANAGED_NOTE_PROJECTION,
        ];
    }
}
