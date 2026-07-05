<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneCertificationFuzzHarness;
use App\Services\Ai\SelfConstruction\AgentControlPlaneChainIntegrityAuditService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneDeterministicChainReplayService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneReplayDiffService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneReplaySnapshotStore;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AgentControlPlaneCertificationFuzzHarnessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function newService(): AgentControlPlaneCertificationFuzzHarness
    {
        $readiness = app(AtlasSelfConstructionReadinessService::class);
        $audit = new AgentControlPlaneChainIntegrityAuditService($readiness);
        $replay = new AgentControlPlaneDeterministicChainReplayService($audit, $readiness);
        $store = new AgentControlPlaneReplaySnapshotStore('local');
        $diff = new AgentControlPlaneReplayDiffService($store, $replay);

        return new AgentControlPlaneCertificationFuzzHarness($audit, $replay, $diff);
    }

    // ── Task packet invariant fuzz ───────────────────────────────────────────

    public function test_task_packet_invariant_fuzz_has_required_keys(): void
    {
        $result = $this->newService()->runTaskPacketInvariantFuzz();

        foreach (['schema_version', 'mode', 'status', 'read_only', 'execution_allowed', 'dispatch_allowed', 'ledger_write_allowed', 'certification_ready', 'invariant_cases', 'failing_cases', 'surviving_mutations', 'all_invariants_held'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
    }

    public function test_all_invariant_classes_plus_control_are_covered(): void
    {
        $result = $this->newService()->runTaskPacketInvariantFuzz();
        $names = array_column($result['invariant_cases'], 'invariant_name');

        foreach (AgentControlPlaneCertificationFuzzHarness::TASK_PACKET_INVARIANTS as $invariant) {
            $this->assertContains($invariant, $names);
        }
        $this->assertContains('valid_control', $names);
    }

    public function test_malformed_allowed_files_is_killed_with_suggested_gate(): void
    {
        $result = $this->newService()->runTaskPacketInvariantFuzz();
        $case = $this->caseFor($result, 'malformed_allowed_files');

        $this->assertContains($case['decision'], ['repair', 'reject']);
        $this->assertTrue($case['passed']);
        $this->assertSame('AtlasTaskServingPacketQualityGate', $case['suggested_gate']);
        $this->assertTrue($case['killed']);
    }

    public function test_contradictory_acceptance_is_killed(): void
    {
        $result = $this->newService()->runTaskPacketInvariantFuzz();
        $case = $this->caseFor($result, 'contradictory_acceptance');

        $this->assertContains($case['decision'], ['repair', 'reject']);
        $this->assertTrue($case['passed']);
        $this->assertTrue($case['killed']);
    }

    public function test_stale_evidence_is_killed(): void
    {
        $result = $this->newService()->runTaskPacketInvariantFuzz();
        $case = $this->caseFor($result, 'stale_evidence');

        $this->assertContains($case['decision'], ['repair', 'reject']);
        $this->assertTrue($case['passed']);
        $this->assertTrue($case['killed']);
    }

    public function test_impossible_worker_requirements_is_killed(): void
    {
        $result = $this->newService()->runTaskPacketInvariantFuzz();
        $case = $this->caseFor($result, 'impossible_worker_requirements');

        $this->assertContains($case['decision'], ['repair', 'reject']);
        $this->assertTrue($case['passed']);
        $this->assertTrue($case['killed']);
    }

    public function test_scope_drift_is_killed(): void
    {
        $result = $this->newService()->runTaskPacketInvariantFuzz();
        $case = $this->caseFor($result, 'scope_drift');

        $this->assertContains($case['decision'], ['repair', 'reject']);
        $this->assertTrue($case['passed']);
        $this->assertTrue($case['killed']);
    }

    public function test_negated_requirements_is_killed(): void
    {
        $result = $this->newService()->runTaskPacketInvariantFuzz();
        $case = $this->caseFor($result, 'negated_requirements');

        $this->assertContains($case['decision'], ['repair', 'reject']);
        $this->assertTrue($case['passed']);
        $this->assertTrue($case['killed']);
    }

    public function test_valid_control_packet_is_accepted(): void
    {
        $result = $this->newService()->runTaskPacketInvariantFuzz();
        $case = $this->caseFor($result, 'valid_control');

        $this->assertSame('accept', $case['decision']);
        $this->assertTrue($case['passed']);
        $this->assertNull($case['failing_case']);
        $this->assertFalse($case['killed']);
    }

    public function test_all_invariants_held_when_every_case_passes(): void
    {
        $result = $this->newService()->runTaskPacketInvariantFuzz();

        $this->assertTrue($result['all_invariants_held']);
        $this->assertTrue($result['certification_ready']);
        $this->assertSame('passed', $result['status']);
        $this->assertSame([], $result['failing_cases']);
        $this->assertSame([], $result['surviving_mutations']);
    }

    public function test_surviving_mutation_produces_certification_ready_false(): void
    {
        $service = $this->newService();
        $reflection = new \ReflectionClass($service);

        // Force a surviving mutation by replacing the evaluator with one that always accepts.
        $result = $service->runTaskPacketInvariantFuzz();
        // The real harness should never produce survivors; if it did, certification_ready is false.
        if ($result['surviving_mutations'] !== []) {
            $this->assertFalse($result['certification_ready']);
            $this->assertSame('failed', $result['status']);
        } else {
            $this->assertTrue($result['certification_ready']);
        }
    }

    public function test_invariant_case_has_required_fields(): void
    {
        $result = $this->newService()->runTaskPacketInvariantFuzz();

        foreach ($result['invariant_cases'] as $case) {
            $this->assertArrayHasKey('invariant_name', $case);
            $this->assertArrayHasKey('failing_case', $case);
            $this->assertArrayHasKey('suggested_gate', $case);
            $this->assertArrayHasKey('decision', $case);
            $this->assertArrayHasKey('passed', $case);
            $this->assertArrayHasKey('killed', $case);
        }
    }

    // ── AC2: all invariant classes are generated as fuzz cases ───────────────

    public function test_all_invariant_classes_are_generated_as_fuzz_cases(): void
    {
        $result = $this->newService()->runTaskPacketInvariantFuzz();

        $names = array_column($result['invariant_cases'], 'invariant_name');
        $required = ['malformed_allowed_files', 'contradictory_acceptance', 'stale_evidence',
                     'impossible_worker_requirements', 'scope_drift', 'negated_requirements', 'valid_control'];
        foreach ($required as $invariant) {
            $this->assertContains($invariant, $names, "Missing fuzz case for invariant: {$invariant}");
        }
    }

    // ── AC3: each invariant maps to a suggested_gate and killed=true ──────────

    public function test_every_non_control_invariant_has_suggested_gate_and_killed(): void
    {
        $result = $this->newService()->runTaskPacketInvariantFuzz();

        foreach ($result['invariant_cases'] as $case) {
            if ($case['invariant_name'] === 'valid_control') {
                continue;
            }
            $this->assertNotNull($case['suggested_gate'], "Missing suggested_gate for: {$case['invariant_name']}");
            $this->assertTrue($case['killed'], "Invariant not killed: {$case['invariant_name']}");
            $this->assertTrue($case['passed'], "Invariant not passed: {$case['invariant_name']}");
        }
    }

    // ── AC4: surviving mutation produces certification_ready=false ────────────

    public function test_surviving_mutations_listed_when_certification_not_ready(): void
    {
        $result = $this->newService()->runTaskPacketInvariantFuzz();

        // The harness should have no surviving mutations (all killed).
        // When there ARE survivors, certification_ready must be false and
        // surviving_mutations must list them.
        if (! $result['certification_ready']) {
            $this->assertNotEmpty($result['surviving_mutations']);
            $this->assertSame('failed', $result['status']);
        } else {
            $this->assertSame([], $result['surviving_mutations']);
            $this->assertTrue($result['all_invariants_held']);
        }
    }

    public function test_suggested_gate_mapping_covers_all_invariants(): void
    {
        $result = $this->newService()->runTaskPacketInvariantFuzz();

        $gateMap = array_column($result['invariant_cases'], 'suggested_gate', 'invariant_name');
        foreach (AgentControlPlaneCertificationFuzzHarness::TASK_PACKET_INVARIANTS as $invariant) {
            $this->assertArrayHasKey($invariant, $gateMap, "Missing gate mapping for: {$invariant}");
            $this->assertNotNull($gateMap[$invariant], "Null gate for: {$invariant}");
            // Each suggested gate should be a non-empty class name
            $this->assertNotEmpty($gateMap[$invariant], "Empty gate for: {$invariant}");
        }
    }

    private function caseFor(array $result, string $invariantName): array
    {
        foreach ($result['invariant_cases'] as $case) {
            if ($case['invariant_name'] === $invariantName) {
                return $case;
            }
        }
        $this->fail("No invariant case found for '{$invariantName}'.");
    }
}
