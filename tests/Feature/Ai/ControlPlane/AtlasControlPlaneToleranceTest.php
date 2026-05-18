<?php

namespace Tests\Feature\Ai\ControlPlane;

use App\Services\Ai\ControlPlane\AtlasControlPlaneBlockerService;
use App\Services\Ai\ControlPlane\AtlasControlPlaneReadinessService;
use App\Services\Ai\ControlPlane\AtlasControlPlaneSnapshotService;
use App\Services\Ai\ControlPlane\AtlasControlPlaneStatus;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasControlPlaneToleranceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Intentionally do not bootstrap any per-runtime tables. Each runtime
        // should report status=missing without raising.
        foreach ([
            'ai_missions',
            'ai_objectives',
            'ai_work_orders',
            'ai_mission_events',
            'ai_mission_evidence_refs',
            'ai_mission_certifications',
            'ai_evidence_packs',
            'ai_receipts',
            'ai_claims',
            'ai_artifacts',
            'ai_source_refs',
            'ai_gate_runs',
            'ai_test_results',
            'ai_operator_decisions',
            'ai_certifications',
            'ai_blockers',
            'ai_audit_events',
            'ai_domain_manifests',
            'ai_domain_capabilities',
            'ai_domain_runtime_records',
            'ai_domain_handoffs',
            'ai_domain_maturity_assessments',
            'ai_policy_profiles',
            'ai_permission_gates',
            'ai_approval_requests',
            'ai_budget_envelopes',
            'ai_risk_assessments',
            'ai_safety_decisions',
            'ai_forbidden_actions',
            'ai_tool_definitions',
            'ai_tool_capabilities',
            'ai_tool_plans',
            'ai_tool_invocations',
            'ai_tool_receipts',
            'ai_tool_health_checks',
            'ai_tool_validation_runs',
            'ai_router_decisions',
            'ai_specialist_flow_executions',
            'ai_atlas_intent_classifications',
            'ai_atlas_router_decisions',
            'ai_atlas_flow_routes',
            'ai_atlas_runtime_dispatches',
            'ai_atlas_decision_receipts',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function test_readiness_does_not_throw_when_all_runtimes_missing(): void
    {
        /** @var AtlasControlPlaneReadinessService $svc */
        $svc = app(AtlasControlPlaneReadinessService::class);
        $report = $svc->report();

        $this->assertSame('atlas.ai.control_plane.readiness.v1', $report['schema']);
        $statuses = array_map(static fn (array $c): string => (string) $c['status'], $report['components']);
        // All six should be missing.
        $missingCount = count(array_filter(
            $statuses,
            static fn (string $s): bool => $s === AtlasControlPlaneStatus::MISSING,
        ));
        $this->assertGreaterThanOrEqual(5, $missingCount);
        $this->assertSame(AtlasControlPlaneStatus::DEGRADED, $report['status']);
    }

    public function test_snapshot_does_not_throw_when_all_runtimes_missing(): void
    {
        /** @var AtlasControlPlaneSnapshotService $svc */
        $svc = app(AtlasControlPlaneSnapshotService::class);
        $snap = $svc->snapshot();

        $this->assertSame('atlas.ai.control_plane.snapshot.v1', $snap['schema']);
        $this->assertContains($snap['status'], AtlasControlPlaneStatus::ALLOWED);
        $this->assertSame(AtlasControlPlaneStatus::MISSING, $snap['missions_summary']['status']);
        $this->assertSame(AtlasControlPlaneStatus::MISSING, $snap['evidence_summary']['status']);
    }

    public function test_blockers_aggregator_returns_zero_when_all_missing(): void
    {
        /** @var AtlasControlPlaneBlockerService $svc */
        $svc = app(AtlasControlPlaneBlockerService::class);
        $snap = $svc->snapshot();

        $this->assertSame(0, $snap['total']);
        $this->assertSame(0, $snap['critical']);
    }
}
