<?php

namespace App\Services\Ai\Surface\Adapters;

use App\Services\Ai\Kernel\Surface\SurfaceCapability;

final class AtlasCliChatSurfaceAdapter extends BaseSurfaceAdapter
{
    protected const SURFACE_ID = 'atlas_cli_chat';

    /**
     * @return array<int,string>
     */
    protected function capabilities(): array
    {
        return [
            SurfaceCapability::TEXT,
            SurfaceCapability::FILES,
            SurfaceCapability::IMAGE_PASTE,
            SurfaceCapability::CONVERSATION_CONTEXT,
            SurfaceCapability::MEMORY_RECALL,
            SurfaceCapability::CONTEXT_COMPOSE,
            SurfaceCapability::TOOLS_RUNTIME,
        ];
    }
}
