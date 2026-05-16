<?php

declare(strict_types=1);

namespace Fixtures\AtlasDev\Discovery\WorkspaceA;

final class AtlasCliDevWorkflowService
{
    public function resolveWorkspace(?string $hint): string
    {
        return $hint ?? '/tmp/atlas-dev-fixture';
    }
}
