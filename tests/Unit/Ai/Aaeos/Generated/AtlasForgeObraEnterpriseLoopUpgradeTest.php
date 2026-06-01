<?php

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasForgeObraEnterpriseLoopUpgradeService;
use Tests\TestCase;

/**
 * Pins the load-bearing rules of the Forge Obra Enterprise Loop Upgrade contract:
 *   - strict audit blocks honestly until every readiness flag holds (Fluxo 3-4);
 *   - a flattened Dev-style Obra (no SDD/packets/providers) is `not_obra_scale`
 *     (quality gate `forge-not-dev-copy`);
 *   - the bounded repair loop caps at max_attempts then opens an incident capsule
 *     (Fluxo 8), and fallback failure / needs-human force the capsule early;
 *   - completion is never claimed without an evidence pack AND a passed
 *     completion gate, and open incidents force a `hold`;
 *   - architectural learning is routed to the curator gate, never auto-applied.
 * Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/atlas-forge-obra-enterprise-loop-upgrade.md
 */
class AtlasForgeObraEnterpriseLoopUpgradeTest extends TestCase
{
    private function service(): AtlasForgeObraEnterpriseLoopUpgradeService
    {
        return new AtlasForgeObraEnterpriseLoopUpgradeService;
    }

    /** A fully ready Obra (every documented readiness flag true + evidence). */
    private function readyObra(): array
    {
        return [
            'obra_id' => 'obra-billing',
            'intent' => 'reestruture billing enterprise',
            'risk_level' => 'high',
            'sdd_ready' => true,
            'workspace_ready' => true,
            'provider_topology_ready' => true,
            'rollback_ready' => true,
            'work_packets_ready' => true,
            'completion_gate_ready' => true,
            'required_evidence' => ['ledger:abc'],
        ];
    }

    public function test_fully_ready_obra_passes_strict_audit_and_may_proceed(): void
    {
        $audit = $this->service()->auditEnterpriseReadiness($this->readyObra(), strict: true);

        $this->assertSame(AtlasForgeObraEnterpriseLoopUpgradeService::SCHEMA_AUDIT, $audit['schema']);
        $this->assertSame(AtlasForgeObraEnterpriseLoopUpgradeService::AUDIT_READY, $audit['strict_status']);
        $this->assertTrue($audit['may_proceed']);
        $this->assertSame([], $audit['blockers']);
    }

    public function test_missing_readiness_flag_blocks_strict_audit_and_forbids_proceed(): void
    {
        $obra = $this->readyObra();
        $obra['completion_gate_ready'] = false; // one flag drops

        $audit = $this->service()->auditEnterpriseReadiness($obra, strict: true);

        $this->assertSame(AtlasForgeObraEnterpriseLoopUpgradeService::AUDIT_BLOCKED, $audit['strict_status']);
        // Strict mode is a real hard stop, not just a label.
        $this->assertFalse($audit['may_proceed']);
        $reasons = array_column($audit['blockers'], 'reason');
        $this->assertContains('readiness_missing', $reasons);
        $fields = array_column($audit['blockers'], 'field');
        $this->assertContains('completion_gate_ready', $fields);
    }

    public function test_flattened_obra_without_scale_signals_is_not_obra_scale(): void
    {
        // No SDD / no packets / no provider topology -> a flat Dev task, not an Obra.
        $audit = $this->service()->auditEnterpriseReadiness([
            'obra_id' => 'obra-x',
            'workspace_ready' => true,
            'rollback_ready' => true,
            'completion_gate_ready' => true,
            'required_evidence' => ['ledger:abc'],
        ], strict: true);

        $this->assertSame(AtlasForgeObraEnterpriseLoopUpgradeService::AUDIT_BLOCKED, $audit['strict_status']);
        $reasons = array_column($audit['blockers'], 'reason');
        $this->assertContains('not_obra_scale', $reasons);
    }

    public function test_non_strict_audit_may_proceed_even_when_blocked(): void
    {
        $obra = $this->readyObra();
        $obra['sdd_ready'] = false;

        $audit = $this->service()->auditEnterpriseReadiness($obra, strict: false);

        $this->assertSame(AtlasForgeObraEnterpriseLoopUpgradeService::AUDIT_BLOCKED, $audit['strict_status']);
        $this->assertTrue($audit['may_proceed']); // non-strict can wave through
    }

    public function test_repair_loop_retries_within_budget_then_opens_incident_capsule_when_exhausted(): void
    {
        $service = $this->service();

        // Attempt 2 of 3 -> bounded repair still admitted, no capsule.
        $retry = $service->evaluateRepairLoop([
            'obra_id' => 'obra-billing',
            'phase' => 'migrate-schema',
            'provider' => 'engine-a',
            'cause' => 'migration timeout',
            'attempt' => 2,
            'max_attempts' => 3,
            'evidence' => ['log:run-2'],
        ]);
        $this->assertSame(AtlasForgeObraEnterpriseLoopUpgradeService::REPAIR_RETRY, $retry['action']);
        $this->assertNull($retry['incident_capsule']);
        $this->assertSame(1, $retry['remaining_attempts']);

        // Attempt 3 of 3 -> exhausted -> incident capsule with mitigation.
        $exhausted = $service->evaluateRepairLoop([
            'obra_id' => 'obra-billing',
            'phase' => 'migrate-schema',
            'provider' => 'engine-a',
            'cause' => 'migration timeout',
            'attempt' => 3,
            'max_attempts' => 3,
            'evidence' => ['log:run-3'],
            'rollback' => 'restore-snapshot-7',
        ]);
        $this->assertSame(AtlasForgeObraEnterpriseLoopUpgradeService::REPAIR_INCIDENT, $exhausted['action']);
        $this->assertIsArray($exhausted['incident_capsule']);
        $this->assertSame(
            AtlasForgeObraEnterpriseLoopUpgradeService::SCHEMA_INCIDENT_CAPSULE,
            $exhausted['incident_capsule']['schema']
        );
        $this->assertSame('restore-snapshot-7', $exhausted['incident_capsule']['mitigation']);
        $this->assertTrue($exhausted['incident_capsule']['needs_human']);
        $this->assertContains('repair_attempts_exhausted:3/3', $exhausted['reasons']);
    }

    public function test_provider_fallback_failure_opens_incident_capsule_even_within_budget(): void
    {
        // Attempt 1 of 3 but the provider fallback itself failed -> capsule now,
        // and the fallback failure is surfaced, never hidden.
        $ledger = $this->service()->evaluateRepairLoop([
            'obra_id' => 'obra-billing',
            'phase' => 'deploy',
            'provider' => 'engine-b',
            'cause' => 'provider outage',
            'attempt' => 1,
            'max_attempts' => 3,
            'fallback_failed' => true,
            'evidence' => ['log:fallback'],
        ]);

        $this->assertSame(AtlasForgeObraEnterpriseLoopUpgradeService::REPAIR_INCIDENT, $ledger['action']);
        $this->assertTrue($ledger['fallback_failed']);
        $this->assertContains('provider_fallback_failed', $ledger['reasons']);
    }

    public function test_completion_is_held_without_evidence_pack_or_passed_gate(): void
    {
        // Obra-scale receipt but no evidence pack and gate not passed -> hold.
        $receipt = $this->service()->evaluateCompletionGate([
            'obra_id' => 'obra-billing',
            'run_id' => 'run-1',
            'phases' => ['design', 'build'],
            'provider_decision_refs' => ['decide:1'],
            'work_packet_refs' => ['wp:1'],
            'evidence_pack_ref' => '',
            'completion_gate_passed' => false,
        ]);

        $this->assertSame(AtlasForgeObraEnterpriseLoopUpgradeService::RELEASE_HOLD, $receipt['release_decision']);
        $this->assertSame('held', $receipt['status']);
        $reasons = array_column($receipt['blockers'], 'reason');
        $this->assertContains('evidence_pack_missing', $reasons);
        $this->assertContains('completion_gate_not_passed', $reasons);
        $this->assertFalse($this->service()->mayClaimCompletion([
            'phases' => ['design'], 'provider_decision_refs' => ['d'], 'work_packet_refs' => ['w'],
            'evidence_pack_ref' => '', 'completion_gate_passed' => false,
        ]));
    }

    public function test_completion_promotes_with_evidence_and_gate_and_routes_learning_to_curator(): void
    {
        $receipt = $this->service()->evaluateCompletionGate([
            'obra_id' => 'obra-billing',
            'run_id' => 'run-9',
            'phases' => ['design', 'build', 'verify'],
            'provider_decision_refs' => ['decide:1', 'decide:2'],
            'work_packet_refs' => ['wp:1', 'wp:2'],
            'evidence_pack_ref' => 'evidence-pack:9',
            'completion_gate_passed' => true,
            'open_blockers' => [],
            'open_incident_capsules' => [],
            'learning_proposals' => ['learn:rearchitect-billing'],
        ]);

        $this->assertSame(AtlasForgeObraEnterpriseLoopUpgradeService::RELEASE_PROMOTE, $receipt['release_decision']);
        $this->assertSame('completed', $receipt['status']);
        $this->assertSame([], $receipt['blockers']);
        // Learning is NEVER auto-applied: routed to curator, auto_applied=false.
        $this->assertCount(1, $receipt['learning_proposals']);
        $this->assertSame('curator_approval_gate', $receipt['learning_proposals'][0]['route']);
        $this->assertFalse($receipt['learning_proposals'][0]['auto_applied']);
    }

    public function test_open_incident_capsule_forces_hold_even_with_evidence_and_gate(): void
    {
        // Everything green EXCEPT an unresolved incident -> still a hold, never hidden.
        $receipt = $this->service()->evaluateCompletionGate([
            'obra_id' => 'obra-billing',
            'phases' => ['build'],
            'provider_decision_refs' => ['decide:1'],
            'work_packet_refs' => ['wp:1'],
            'evidence_pack_ref' => 'evidence-pack:1',
            'completion_gate_passed' => true,
            'open_incident_capsules' => ['incident:deploy-outage'],
        ]);

        $this->assertSame(AtlasForgeObraEnterpriseLoopUpgradeService::RELEASE_HOLD, $receipt['release_decision']);
        $reasons = array_column($receipt['blockers'], 'reason');
        $this->assertContains('open_incident_capsule', $reasons);
    }
}
