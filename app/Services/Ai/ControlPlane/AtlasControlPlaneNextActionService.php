<?php

namespace App\Services\Ai\ControlPlane;

class AtlasControlPlaneNextActionService
{
    public const SCHEMA = 'atlas.ai.control_plane.next_action.v1';

    public function __construct(
        private readonly AtlasControlPlaneReadinessService $readiness,
        private readonly AtlasControlPlaneBlockerService $blockers,
        private readonly AtlasControlPlaneMissionService $mission,
    ) {}

    /**
     * Derive operational next actions from current blockers and readiness gaps.
     * Each action carries a priority bucket (critical|high|medium|low), a
     * kind and a target reference suitable for surfaces to display.
     *
     * @return array<string,mixed>
     */
    public function actions(): array
    {
        $items = [];

        // Readiness gaps become "setup" actions per missing/degraded component.
        $readiness = $this->readiness->report();
        foreach ($readiness['components'] as $component) {
            $status = (string) ($component['status'] ?? '');
            $name = (string) ($component['component'] ?? 'unknown');
            if ($status === AtlasControlPlaneStatus::MISSING) {
                $items[] = [
                    'kind' => 'setup_runtime',
                    'component' => $name,
                    'priority' => 'high',
                    'detail' => "Runtime {$name} is missing tables. Run pending migrations or install Meta backend.",
                ];
            } elseif ($status === AtlasControlPlaneStatus::DEGRADED) {
                $items[] = [
                    'kind' => 'repair_runtime',
                    'component' => $name,
                    'priority' => 'medium',
                    'detail' => "Runtime {$name} is degraded ({$component['tables_present']}/{$component['tables_required']} tables present).",
                ];
            }
        }

        // Blockers become "resolve_blocker" actions.
        $blockerSnapshot = $this->blockers->snapshot(10);
        foreach ($blockerSnapshot['recent'] as $blocker) {
            $items[] = [
                'kind' => 'resolve_blocker',
                'component' => (string) ($blocker['source'] ?? 'unknown'),
                'priority' => $blocker['severity'] ?? ($blocker['source'] === 'evidence' ? 'high' : 'medium'),
                'target_type' => $blocker['target_type'] ?? null,
                'target_id' => $blocker['target_id'] ?? null,
                'detail' => (string) ($blocker['reason'] ?? 'open blocker'),
            ];
        }

        // Missions waiting for approval become "request_approval".
        $missionSummary = $this->mission->summary();
        if (($missionSummary['status'] ?? null) === AtlasControlPlaneStatus::READY) {
            $waitingApproval = (int) ($missionSummary['by_status']['waiting_approval'] ?? 0);
            if ($waitingApproval > 0) {
                $items[] = [
                    'kind' => 'review_pending_approvals',
                    'component' => 'mission',
                    'priority' => 'high',
                    'detail' => "{$waitingApproval} missions waiting for human approval.",
                ];
            }
            $certifying = (int) ($missionSummary['by_status']['certifying'] ?? 0);
            if ($certifying > 0) {
                $items[] = [
                    'kind' => 'finalize_certification',
                    'component' => 'mission',
                    'priority' => 'medium',
                    'detail' => "{$certifying} missions are in certifying state.",
                ];
            }
        }

        if ($items === []) {
            $items[] = [
                'kind' => 'idle',
                'component' => 'control_plane',
                'priority' => 'low',
                'detail' => 'No blockers, missions waiting, or readiness gaps detected.',
            ];
        }

        $priorityRank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        usort(
            $items,
            static fn (array $a, array $b): int => (
                ($priorityRank[$a['priority'] ?? 'low'] ?? 9)
                <=> ($priorityRank[$b['priority'] ?? 'low'] ?? 9)
            ),
        );

        return [
            'schema' => self::SCHEMA,
            'count' => count($items),
            'items' => $items,
            'generated_at' => now()->toJSON(),
        ];
    }
}
