<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Http\Controllers\AtlasCodeForgeExecutionController;
use App\Models\AtlasProject;
use App\Services\Ai\DualCore\ForgeIntakeRouteDecisionRecorder;
use App\Services\Ai\Programming\Forge\ForgeIntakeService;
use Illuminate\Http\Request;

/**
 * Application façade for Forge live execution dispatch without FastPath importing controllers.
 */
final class ForgeLiveExecutionApplicationService
{
    public function __construct(
        private readonly AtlasCodeForgeExecutionController $execution,
        private readonly AtlasForgeLiveExecutionService $live,
        private readonly ForgeIntakeRouteDecisionRecorder $routeDecisions,
        private readonly ForgeIntakeService $forgeIntake,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function executeSync(AtlasProject $project, bool $simulateFailure = false): array
    {
        return $this->execution->executeAndPersist($project, $this->live, $simulateFailure);
    }

    /**
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function startAsync(AtlasProject $project, bool $simulateFailure = false): array
    {
        $request = Request::create('/_internal/forge/async', 'POST', ['simulate_failure' => $simulateFailure]);
        $response = $this->execution->startAsync($request, $project, $this->routeDecisions);

        return ['status' => $response->getStatusCode(), 'payload' => (array) $response->getData(true)];
    }

    /**
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function storeSync(AtlasProject $project, bool $simulateFailure = false): array
    {
        $request = Request::create('/_internal/forge/sync', 'POST', ['simulate_failure' => $simulateFailure]);
        $response = $this->execution->store(
            $request,
            $project,
            $this->live,
            $this->routeDecisions,
            $this->forgeIntake,
        );

        return ['status' => $response->getStatusCode(), 'payload' => (array) $response->getData(true)];
    }

    /**
     * Read-only history replay (no external provider).
     *
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function showHistory(AtlasProject $project, string $historyId): array
    {
        $response = $this->execution->showHistory($project, $historyId);

        return ['status' => $response->getStatusCode(), 'payload' => (array) $response->getData(true)];
    }
}
