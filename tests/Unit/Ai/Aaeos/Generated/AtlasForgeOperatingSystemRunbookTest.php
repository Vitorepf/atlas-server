<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasForgeOperatingSystemRunbookService;
use Tests\TestCase;

/**
 * Pins the documented Atlas Forge OS Runbook operational rules.
 *
 * @see docs/engineering-knowledge-base/atlas-forge-operating-system-runbook.md
 */
class AtlasForgeOperatingSystemRunbookTest extends TestCase
{
    private function service(): AtlasForgeOperatingSystemRunbookService
    {
        return new AtlasForgeOperatingSystemRunbookService();
    }

    /**
     * Intake taxonomy: the doc lists exactly 9 types and only `small_patch` runs
     * under compact governance.
     */
    public function test_intake_taxonomy_has_nine_types_and_one_compact(): void
    {
        $this->assertCount(9, AtlasForgeOperatingSystemRunbookService::INTAKE_TYPES);
        $this->assertSame(['small_patch'], AtlasForgeOperatingSystemRunbookService::COMPACT_TYPES);
        $this->assertContains('self_construction', AtlasForgeOperatingSystemRunbookService::INTAKE_TYPES);
        $this->assertContains('multi_agent_project', AtlasForgeOperatingSystemRunbookService::INTAKE_TYPES);
    }

    /**
     * "Trabalho pequeno nao aciona Forge completo por reflexo." A low-criticality
     * small_patch routes to compact governance; a complex type routes to full
     * Forge.
     */
    public function test_small_patch_routes_compact_complex_routes_full_forge(): void
    {
        $svc = $this->service();

        $small = $svc->routeIntake(['type' => 'small_patch', 'criticality' => 'low']);
        $this->assertSame(AtlasForgeOperatingSystemRunbookService::ROUTE_COMPACT_GOVERNANCE, $small['route']);
        $this->assertFalse($small['full_forge']);

        $complex = $svc->routeIntake(['type' => 'multi_agent_project']);
        $this->assertSame(AtlasForgeOperatingSystemRunbookService::ROUTE_FULL_FORGE, $complex['route']);
        $this->assertTrue($svc->requiresFullForge(['type' => 'self_construction']));
    }

    /**
     * "alta criticidade" forces the full factory even for a small_patch; an
     * unknown type fails safe into the full factory (never silently compact).
     */
    public function test_high_criticality_and_unknown_type_escalate_to_full_forge(): void
    {
        $svc = $this->service();

        $escalated = $svc->routeIntake(['type' => 'small_patch', 'criticality' => 'critical']);
        $this->assertSame(AtlasForgeOperatingSystemRunbookService::ROUTE_FULL_FORGE, $escalated['route']);
        $this->assertContains('small_task_escalated_by_criticality:critical', $escalated['reasons']);

        $unknown = $svc->routeIntake(['type' => 'totally_made_up']);
        $this->assertSame(AtlasForgeOperatingSystemRunbookService::ROUTE_FULL_FORGE, $unknown['route']);
        $this->assertFalse($unknown['known_type']);
    }

    /**
     * Quality Gate Matrix: "Cada gate precisa de evidence id. Gate pulado sem
     * motivo vira falha." A passed gate with no evidence id fails; a skipped gate
     * with a reason is acceptable; a skipped gate with no reason fails.
     */
    public function test_gate_matrix_evidence_and_skip_reason_rules(): void
    {
        $svc = $this->service();

        $passNoEvidence = $svc->evaluateGate(['name' => 'ci', 'status' => 'passed']);
        $this->assertSame(AtlasForgeOperatingSystemRunbookService::GATE_FAIL, $passNoEvidence['verdict']);
        $this->assertContains('passed_gate_missing_evidence_id', $passNoEvidence['reasons']);

        $skipNoReason = $svc->evaluateGate(['name' => 'dry-run', 'status' => 'skipped']);
        $this->assertSame(AtlasForgeOperatingSystemRunbookService::GATE_FAIL, $skipNoReason['verdict']);
        $this->assertContains('skipped_without_reason_is_failure', $skipNoReason['reasons']);

        $skipReasoned = $svc->evaluateGate(['name' => 'dry-run', 'status' => 'skipped', 'skip_reason' => 'low risk']);
        $this->assertSame(AtlasForgeOperatingSystemRunbookService::GATE_SKIPPED_OK, $skipReasoned['verdict']);
    }

    /**
     * The matrix is green only when no gate fails; a single missing-evidence gate
     * turns the whole matrix red and names the failing gate.
     */
    public function test_gate_matrix_rollup_is_red_when_any_gate_fails(): void
    {
        $svc = $this->service();

        $matrix = $svc->evaluateGateMatrix([
            ['name' => 'constitution', 'status' => 'passed', 'evidence_id' => 'EV-1'],
            ['name' => 'release', 'status' => 'passed'], // missing evidence => fail
            ['name' => 'dry-run', 'status' => 'skipped', 'skip_reason' => 'low risk'],
        ]);

        $this->assertFalse($matrix['green']);
        $this->assertSame(AtlasForgeOperatingSystemRunbookService::GATE_FAIL, $matrix['status']);
        $this->assertSame(['release'], $matrix['failing']);
        $this->assertSame(1, $matrix['skipped_ok']);
    }

    /**
     * Rerun rules: a rerun with no new evidence is blocked ("Rerun sem evidence
     * nova"); a clean rerun with same contract + new evidence is allowed.
     */
    public function test_rerun_requires_new_evidence_and_governed_basis(): void
    {
        $svc = $this->service();

        $noEvidence = $svc->decideRerun([
            'scope' => 'tests',
            'same_contract' => true,
            'new_evidence' => false,
        ]);
        $this->assertSame(AtlasForgeOperatingSystemRunbookService::RERUN_BLOCK, $noEvidence['verdict']);
        $this->assertFalse($noEvidence['may_run']);

        $noBasis = $svc->decideRerun([
            'scope' => 'tests',
            'same_contract' => false,
            'repair_delta' => false,
            'new_evidence' => true,
        ]);
        $this->assertSame(AtlasForgeOperatingSystemRunbookService::RERUN_BLOCK, $noBasis['verdict']);
        $this->assertContains('rerun_needs_same_contract_or_repair_delta', $noBasis['reasons']);

        $allowed = $svc->decideRerun([
            'scope' => 'tests',
            'same_contract' => true,
            'new_evidence' => true,
            'auto_attempt' => 1,
            'risk' => 'low',
        ]);
        $this->assertSame(AtlasForgeOperatingSystemRunbookService::RERUN_ALLOW, $allowed['verdict']);
        $this->assertTrue($allowed['may_run']);
    }

    /**
     * "retry automatico tem limite; falha repetida vira learning proposal" and
     * "high-risk rerun exige review ou escalacao".
     */
    public function test_rerun_retry_cap_and_high_risk_routing(): void
    {
        $svc = $this->service();

        // Attempt beyond the default cap of 3 => learning proposal, not a loop.
        $exhausted = $svc->decideRerun([
            'scope' => 'packet',
            'same_contract' => true,
            'new_evidence' => true,
            'auto_attempt' => 4,
        ]);
        $this->assertSame(AtlasForgeOperatingSystemRunbookService::RERUN_LEARNING_PROPOSAL, $exhausted['verdict']);
        $this->assertSame(0, $exhausted['remaining_auto_retries']);

        $repeated = $svc->decideRerun([
            'scope' => 'packet',
            'repair_delta' => true,
            'new_evidence' => true,
            'repeated_failure' => true,
        ]);
        $this->assertSame(AtlasForgeOperatingSystemRunbookService::RERUN_LEARNING_PROPOSAL, $repeated['verdict']);

        $highRisk = $svc->decideRerun([
            'scope' => 'integration_queue_item',
            'same_contract' => true,
            'new_evidence' => true,
            'auto_attempt' => 1,
            'risk' => 'high',
        ]);
        $this->assertSame(AtlasForgeOperatingSystemRunbookService::RERUN_REVIEW, $highRisk['verdict']);
        $this->assertFalse($highRisk['may_run']);
    }
}
