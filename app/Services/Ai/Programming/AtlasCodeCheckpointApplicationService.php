<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Http\Controllers\AtlasCodeCheckpointController;
use App\Models\AtlasProject;
use Illuminate\Http\Request;

/**
 * Application façade for checkpoints so operate-path services avoid controller imports.
 */
final class AtlasCodeCheckpointApplicationService
{
    public function __construct(
        private readonly AtlasCodeCheckpointController $checkpoints,
    ) {}

    /**
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function store(AtlasProject $project, string $reason = 'manual'): array
    {
        $request = Request::create('/_internal/checkpoints', 'POST', ['reason' => $reason]);
        $response = $this->checkpoints->store($request, $project);

        return ['status' => $response->getStatusCode(), 'payload' => (array) $response->getData(true)];
    }
}
