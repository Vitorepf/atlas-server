<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Http\Controllers\AtlasCodeWorkController;
use App\Models\AtlasProject;

/**
 * Application façade for Obra state projection so operate-path cert services
 * do not import Http Controllers.
 */
final class AtlasCodeWorkStateApplicationService
{
    public function __construct(
        private readonly AtlasCodeWorkController $work,
    ) {}

    /**
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function state(AtlasProject $project): array
    {
        $response = $this->work->state($project);

        return ['status' => $response->getStatusCode(), 'payload' => (array) $response->getData(true)];
    }
}
