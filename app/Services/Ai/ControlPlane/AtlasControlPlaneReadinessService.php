<?php

namespace App\Services\Ai\ControlPlane;

class AtlasControlPlaneReadinessService
{
    public const SCHEMA = 'atlas.ai.control_plane.readiness.v1';

    public function __construct(
        private readonly AtlasControlPlaneMissionService $mission,
        private readonly AtlasControlPlaneDomainService $domain,
        private readonly AtlasControlPlanePolicyService $policy,
        private readonly AtlasControlPlaneEvidenceService $evidence,
        private readonly AtlasControlPlaneToolService $tool,
        private readonly AtlasControlPlaneRouterService $router,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function report(): array
    {
        $components = [
            $this->mission->tableAvailability() + ['component' => AtlasControlPlaneMissionService::COMPONENT],
            $this->domain->tableAvailability() + ['component' => AtlasControlPlaneDomainService::COMPONENT],
            $this->policy->tableAvailability() + ['component' => AtlasControlPlanePolicyService::COMPONENT],
            $this->evidence->tableAvailability() + ['component' => AtlasControlPlaneEvidenceService::COMPONENT],
            $this->tool->tableAvailability() + ['component' => AtlasControlPlaneToolService::COMPONENT],
            $this->router->tableAvailability() + ['component' => AtlasControlPlaneRouterService::COMPONENT],
        ];

        $statuses = array_map(static fn (array $c): string => (string) $c['status'], $components);
        $overall = AtlasControlPlaneStatus::reduce($statuses);

        $passed = 0;
        $degraded = 0;
        $missing = 0;
        foreach ($statuses as $status) {
            if ($status === AtlasControlPlaneStatus::READY) {
                $passed++;
            } elseif ($status === AtlasControlPlaneStatus::MISSING) {
                $missing++;
            } else {
                $degraded++;
            }
        }

        return [
            'ok' => $overall === AtlasControlPlaneStatus::READY,
            'schema' => self::SCHEMA,
            'status' => $overall,
            'summary' => [
                'total' => count($components),
                'ready' => $passed,
                'degraded' => $degraded,
                'missing' => $missing,
            ],
            'components' => $components,
            'generated_at' => now()->toJSON(),
        ];
    }
}
