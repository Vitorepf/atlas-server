<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasUniversalFailureModeCatalogService;
use Tests\TestCase;

/**
 * Pins the consolidated-catalog contract from the Universal Failure Mode Catalog
 * doc: the closed 19-mode catalog with the `atlas.failure_mode.entry.v1` schema;
 * the severity policy (critical->halt, high->block, medium->mitigate, low->track)
 * with critical never masked by a low; the recurrence rule (>3 in 7d escalates,
 * tripping at 4); critical-without-tested-recovery blocks runtime; and ungoverned
 * modes escalate instead of being silently allowed. Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/atlas-universal-failure-mode-catalog.md
 */
class AtlasUniversalFailureModeCatalogTest extends TestCase
{
    private function service(): AtlasUniversalFailureModeCatalogService
    {
        return new AtlasUniversalFailureModeCatalogService;
    }

    public function test_catalog_has_nineteen_governed_modes_with_entry_schema_and_required_fields(): void
    {
        $catalog = $this->service()->catalog();

        // The snapshot table lists exactly 19 rows; +1 (repeat_work_loop) is the
        // documented "Regras para IA" repetition mode. The closed governed set is 19.
        $this->assertCount(19, $catalog);
        $this->assertCount(19, $this->service()->modeIds());

        foreach ($catalog as $id => $entry) {
            $this->assertSame('atlas.failure_mode.entry.v1', $entry['schema']);
            $this->assertSame($id, $entry['id']);
            // Each mode MUST carry severity, blast radius, detection and recovery
            // (quality_gates: all-modes-have-recovery / all-modes-have-severity /
            // blast-radius-classified). No empty recovery -> "Modo sem recovery".
            $this->assertContains($entry['severity'], AtlasUniversalFailureModeCatalogService::SEVERITY_ORDER);
            $this->assertContains($entry['blast_radius'], AtlasUniversalFailureModeCatalogService::BLAST_ORDER);
            $this->assertNotEmpty($entry['recovery'], "mode {$id} must have a recovery runbook");
            $this->assertNotEmpty($entry['detection'], "mode {$id} must have a detection signal");
        }
    }

    public function test_severity_policy_maps_each_level_to_its_documented_action(): void
    {
        $svc = $this->service();

        // "Severity policy": critical->halt, high->block+escalate, medium->mitigate, low->track.
        $this->assertSame('halt_runtime', $svc->actionFor(AtlasUniversalFailureModeCatalogService::SEVERITY_CRITICAL));
        $this->assertSame('block_escalate', $svc->actionFor(AtlasUniversalFailureModeCatalogService::SEVERITY_HIGH));
        $this->assertSame('mitigate_monitor', $svc->actionFor(AtlasUniversalFailureModeCatalogService::SEVERITY_MEDIUM));
        $this->assertSame('track', $svc->actionFor(AtlasUniversalFailureModeCatalogService::SEVERITY_LOW));

        // Only high & critical halt the runtime.
        $this->assertTrue($svc->isHalting(AtlasUniversalFailureModeCatalogService::SEVERITY_CRITICAL));
        $this->assertTrue($svc->isHalting(AtlasUniversalFailureModeCatalogService::SEVERITY_HIGH));
        $this->assertFalse($svc->isHalting(AtlasUniversalFailureModeCatalogService::SEVERITY_MEDIUM));
        $this->assertFalse($svc->isHalting(AtlasUniversalFailureModeCatalogService::SEVERITY_LOW));
    }

    public function test_classify_surfaces_worst_severity_and_widest_blast_radius(): void
    {
        // A low notice (dept_blocker_stale, department) + a critical (collision_silent,
        // single_obra). Worst severity must dominate -> critical / halt, execution
        // withheld; widest blast is department (department > single_obra).
        $verdict = $this->service()->classify([
            AtlasUniversalFailureModeCatalogService::MODE_DEPT_BLOCKER_STALE,
            AtlasUniversalFailureModeCatalogService::MODE_COLLISION_SILENT,
        ]);

        $this->assertSame('critical', $verdict['worst_severity']);
        $this->assertSame('halt_runtime', $verdict['action']);
        $this->assertTrue($verdict['withhold_execution']);
        $this->assertSame('department', $verdict['widest_blast_radius']);
        $this->assertFalse($verdict['has_ungoverned']);
        $this->assertSame(2, $verdict['active_count']);

        // A purely low/medium set must NOT withhold execution.
        $soft = $this->service()->classify([
            AtlasUniversalFailureModeCatalogService::MODE_DEPT_BLOCKER_STALE,
            AtlasUniversalFailureModeCatalogService::MODE_DOCS_HEALTH_DRIFT,
        ]);
        $this->assertSame('medium', $soft['worst_severity']);
        $this->assertSame('mitigate_monitor', $soft['action']);
        $this->assertFalse($soft['withhold_execution']);
    }

    public function test_repair_loop_infinite_routes_to_escalate_and_freeze_with_learning(): void
    {
        // "repair_loop_infinite | critical | single_intent | repair_loop_iterations > 3
        //  | escalate operator + freeze intent". The flow always ends in register learning.
        $route = $this->service()->route(AtlasUniversalFailureModeCatalogService::MODE_REPAIR_LOOP_INFINITE);

        $this->assertTrue($route['governed']);
        $this->assertSame('critical', $route['severity']);
        $this->assertSame('single_intent', $route['blast_radius']);
        $this->assertSame('halt_runtime', $route['action']);
        $this->assertTrue($route['halt_runtime']);
        $this->assertContains('escalate operator', $route['recovery']);
        $this->assertContains('freeze intent', $route['recovery']);
        $this->assertTrue($route['register_learning']);
    }

    public function test_recurrence_over_three_in_seven_days_escalates_and_critical_without_tested_recovery_blocks(): void
    {
        $svc = $this->service();

        // "Recurrence > 3 em 7 dias -> escalate Architect": strictly > 3 (trips at 4).
        $this->assertFalse($svc->recurrenceEscalates(0));
        $this->assertFalse($svc->recurrenceEscalates(3));
        $this->assertTrue($svc->recurrenceEscalates(4));

        // "Critical sem recovery testado -> bloqueia runtime."
        $this->assertTrue($svc->criticalNeedsTestedRecovery(AtlasUniversalFailureModeCatalogService::SEVERITY_CRITICAL, false));
        $this->assertFalse($svc->criticalNeedsTestedRecovery(AtlasUniversalFailureModeCatalogService::SEVERITY_CRITICAL, true));
        // A high mode without tested recovery does NOT trip this specific critical-only rule.
        $this->assertFalse($svc->criticalNeedsTestedRecovery(AtlasUniversalFailureModeCatalogService::SEVERITY_HIGH, false));
    }

    public function test_ungoverned_mode_escalates_instead_of_being_silently_allowed(): void
    {
        $svc = $this->service();

        // "Failure mode sem entry aqui nao tem runbook governado."
        $this->assertFalse($svc->isGoverned('totally_unknown_mode'));
        $entry = $svc->entry('totally_unknown_mode');
        $this->assertFalse($entry['governed']);
        $this->assertEmpty($entry['recovery']);

        // An ungoverned mode mixed with only a low notice must still withhold and
        // route to escalate (no silent continue on an unknown failure).
        $verdict = $svc->classify([
            AtlasUniversalFailureModeCatalogService::MODE_DEPT_BLOCKER_STALE,
            'totally_unknown_mode',
        ]);
        $this->assertTrue($verdict['has_ungoverned']);
        $this->assertTrue($verdict['withhold_execution']);
        $this->assertSame('escalate_ungoverned', $verdict['action']);
        $this->assertSame(['totally_unknown_mode'], $verdict['ungoverned_modes']);
    }
}
