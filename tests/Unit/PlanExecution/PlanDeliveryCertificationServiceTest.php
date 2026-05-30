<?php

declare(strict_types=1);

namespace Tests\Unit\PlanExecution;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Ap786RealCycleCertificationService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusLoopOperationalCertificationService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\PlanDeliveryCertificationService;
use PHPUnit\Framework\TestCase;

/**
 * Fake AP-786 real-cycle cert. The real service is `final` (cannot be
 * subclassed), so this is a duck-typed double exposing the identical
 * certify(array): array surface. It returns a cert whose TOP-LEVEL cycles[]
 * carry selected_finding.finding_id + status, mirroring the real seam's shape
 * so the join key (cycles[].selected_finding.finding_id) is exercised verbatim.
 */
final class FakeRealCycleCert
{
    public const STATUS_CERTIFIED = Ap786RealCycleCertificationService::STATUS_CERTIFIED;

    public const STATUS_BLOCKED = Ap786RealCycleCertificationService::STATUS_BLOCKED;

    public int $calls = 0;

    /** @var array<string,array<string,string>> session_id => [finding_id => status] */
    public array $bySession = [];

    public function certify(array $input = []): array
    {
        $this->calls++;
        $sessionId = (string) ($input['session_id'] ?? '');
        $cycles = [];
        foreach ($this->bySession[$sessionId] ?? [] as $findingId => $status) {
            $cycles[] = [
                'cycle_id' => 'c-'.$findingId,
                'status' => $status,
                'selected_finding' => ['finding_id' => $findingId, 'title' => 'F '.$findingId],
            ];
        }

        return [
            'schema_version' => Ap786RealCycleCertificationService::REPORT_SCHEMA,
            'status' => self::STATUS_CERTIFIED,
            'cycles' => $cycles,
            // per_cycle deliberately omits finding_id to prove we do NOT join on it.
            'three_cycle_audit' => ['per_cycle' => array_map(static fn ($c) => [
                'cycle_id' => $c['cycle_id'], 'status' => $c['status'],
            ], $cycles)],
        ];
    }
}

final class FakeOperationalCert
{
    public int $calls = 0;

    public function __construct(public string $verdict = AreaFocusLoopOperationalCertificationService::STATUS_OPERATIONAL) {}

    public function certify(array $input = []): array
    {
        $this->calls++;

        return [
            'schema_version' => AreaFocusLoopOperationalCertificationService::CERT_SCHEMA,
            'status' => $this->verdict,
            'operational' => $this->verdict === AreaFocusLoopOperationalCertificationService::STATUS_OPERATIONAL,
        ];
    }
}

final class FakeTracker
{
    public int $calls = 0;

    /** @param array<string,mixed> $ledger */
    public function __construct(private array $ledger) {}

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function rollup(array $input): array
    {
        $this->calls++;

        return $this->ledger;
    }
}

final class PlanDeliveryCertificationServiceTest extends TestCase
{
    /**
     * @param  list<string>  $sliceIds
     * @return array<string,mixed>
     */
    private function plan(array $sliceIds, array $dependsOn = []): array
    {
        $slices = [];
        $seq = 1;
        foreach ($sliceIds as $id) {
            $slices[] = [
                'slice_id' => $id,
                'sequence' => $seq++,
                'label' => $id,
                'depends_on' => $dependsOn[$id] ?? [],
                'finding' => ['finding_id' => $id, 'title' => 'F '.$id],
            ];
        }

        return [
            'schema_version' => 'atlas.plan_execution.decomposed_plan.v1',
            'plan_id' => 'PLAN-1',
            'slices' => $slices,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function deliveredState(string $id): array
    {
        return [
            'slice_id' => $id,
            'state' => 'delivered',
            'merge_hash' => 'm-'.$id,
            'provider_proof' => true,
            'acceptance_met' => true,
            'finding_id' => $id,
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $sliceStates
     * @return array<string,mixed>
     */
    private function ledger(array $sliceStates): array
    {
        return [
            'schema_version' => 'atlas.plan_execution.plan_completion_ledger.v1',
            'plan_id' => 'PLAN-1',
            'status' => 'ready',
            'total_slices' => count($sliceStates),
            'slice_states' => $sliceStates,
        ];
    }

    private function service(
        FakeTracker $tracker,
        FakeRealCycleCert $cert,
        FakeOperationalCert $op,
    ): PlanDeliveryCertificationService {
        $svc = new PlanDeliveryCertificationService();
        $svc->setTrackerForTesting($tracker);
        $svc->setRealCycleCertForTesting($cert);
        $svc->setOperationalCertForTesting($op);

        return $svc;
    }

    public function test_happy_path_full_delivery_is_complete(): void
    {
        $ids = ['S1', 'S2', 'S3'];
        $states = [];
        foreach ($ids as $id) {
            $states[$id] = $this->deliveredState($id);
        }
        $cert = new FakeRealCycleCert();
        $cert->bySession['sess-1'] = ['S1' => $cert::STATUS_CERTIFIED, 'S2' => $cert::STATUS_CERTIFIED, 'S3' => $cert::STATUS_CERTIFIED];

        $tracker = new FakeTracker($this->ledger($states));
        $op = new FakeOperationalCert();

        $out = $this->service($tracker, $cert, $op)->certify([
            'decomposed_plan' => $this->plan($ids),
            'area_id' => 'agentic_engineering_os',
            'session_ids' => ['sess-1'],
            'integration_check' => ['green' => true, 'evidence_refs' => ['e1']],
        ]);

        $this->assertSame(PlanDeliveryCertificationService::STATUS_COMPLETE, $out['status']);
        $this->assertSame(3, $out['delivered_slices']);
        $this->assertSame(100.0, $out['completion_pct']);
        $this->assertTrue($out['all_slices_merged_with_provider_proof']);
        $this->assertTrue($out['all_acceptance_met']);
        $this->assertTrue($out['dependency_order_preserved']);
        $this->assertTrue($out['integration_green']);
        $this->assertSame('operational', $out['operational_gate']);
        $this->assertStringStartsWith('sha256:', $out['plan_delivery_cert_hash']);
        // composition proof: each seam was actually called
        $this->assertSame(1, $op->calls);
        $this->assertSame(1, $tracker->calls);
        $this->assertSame(1, $cert->calls);
        foreach ($out['per_slice'] as $s) {
            $this->assertTrue($s['real_cycle_certified']);
        }
    }

    public function test_five_of_six_delivered_is_partial_not_complete(): void
    {
        $ids = ['S1', 'S2', 'S3', 'S4', 'S5', 'S6'];
        $states = [];
        foreach ($ids as $id) {
            $states[$id] = $this->deliveredState($id);
        }
        // S6 not delivered
        $states['S6']['state'] = 'in_progress';
        $states['S6']['merge_hash'] = null;
        $states['S6']['provider_proof'] = false;
        $states['S6']['acceptance_met'] = false;

        $cert = new FakeRealCycleCert();
        $cert->bySession['sess-1'] = [];
        foreach (['S1', 'S2', 'S3', 'S4', 'S5'] as $id) {
            $cert->bySession['sess-1'][$id] = $cert::STATUS_CERTIFIED;
        }

        $out = $this->service(new FakeTracker($this->ledger($states)), $cert, new FakeOperationalCert())->certify([
            'decomposed_plan' => $this->plan($ids),
            'area_id' => 'a',
            'session_ids' => ['sess-1'],
            'integration_check' => ['green' => true, 'evidence_refs' => []],
        ]);

        $this->assertSame(PlanDeliveryCertificationService::STATUS_PARTIAL, $out['status']);
        $this->assertSame(5, $out['delivered_slices']);
    }

    public function test_operational_gate_not_operational_blocks_before_slice_judgement(): void
    {
        $cert = new FakeRealCycleCert();
        $tracker = new FakeTracker($this->ledger([]));
        $op = new FakeOperationalCert(AreaFocusLoopOperationalCertificationService::STATUS_BLOCKED);

        $out = $this->service($tracker, $cert, $op)->certify([
            'decomposed_plan' => $this->plan(['S1']),
            'area_id' => 'a',
            'session_ids' => ['sess-1'],
            'integration_check' => ['green' => true],
        ]);

        $this->assertSame(PlanDeliveryCertificationService::STATUS_BLOCKED, $out['status']);
        $this->assertSame('blocked', $out['operational_gate']);
        $this->assertSame([], $out['per_slice']);
        // pre-gate: tracker/cert must NOT be consulted once operational gate fails
        $this->assertSame(0, $tracker->calls);
        $this->assertSame(0, $cert->calls);
        $this->assertContains('operational_gate_not_operational:blocked', $out['blockers']);
    }

    public function test_tracker_delivered_but_session_uncertified_downgrades_to_partial(): void
    {
        $ids = ['S1', 'S2'];
        $states = ['S1' => $this->deliveredState('S1'), 'S2' => $this->deliveredState('S2')];

        $cert = new FakeRealCycleCert();
        // S1 certified; S2 present but NOT certified (blocked) -> uncertified
        $cert->bySession['sess-1'] = ['S1' => $cert::STATUS_CERTIFIED, 'S2' => $cert::STATUS_BLOCKED];

        $out = $this->service(new FakeTracker($this->ledger($states)), $cert, new FakeOperationalCert())->certify([
            'decomposed_plan' => $this->plan($ids),
            'area_id' => 'a',
            'session_ids' => ['sess-1'],
            'integration_check' => ['green' => true],
        ]);

        $this->assertSame(PlanDeliveryCertificationService::STATUS_PARTIAL, $out['status']);
        $byId = [];
        foreach ($out['per_slice'] as $s) {
            $byId[$s['slice_id']] = $s;
        }
        $this->assertTrue($byId['S1']['real_cycle_certified']);
        $this->assertFalse($byId['S2']['real_cycle_certified']);
        $this->assertContains('no_certified_real_cycle', $byId['S2']['blockers']);
    }

    public function test_join_key_is_top_level_cycles_finding_id(): void
    {
        $ids = ['S1', 'S2', 'S3', 'S4', 'S5', 'S6'];
        $states = [];
        foreach ($ids as $id) {
            $states[$id] = $this->deliveredState($id);
        }
        $cert = new FakeRealCycleCert();
        // certify only S1,S3,S5 — odd slices
        $cert->bySession['sess-1'] = ['S1' => $cert::STATUS_CERTIFIED, 'S3' => $cert::STATUS_CERTIFIED, 'S5' => $cert::STATUS_CERTIFIED];

        $out = $this->service(new FakeTracker($this->ledger($states)), $cert, new FakeOperationalCert())->certify([
            'decomposed_plan' => $this->plan($ids),
            'area_id' => 'a',
            'session_ids' => ['sess-1'],
            'integration_check' => ['green' => true],
        ]);

        $byId = [];
        foreach ($out['per_slice'] as $s) {
            $byId[$s['slice_id']] = $s['real_cycle_certified'];
        }
        $this->assertSame(
            ['S1' => true, 'S2' => false, 'S3' => true, 'S4' => false, 'S5' => true, 'S6' => false],
            $byId,
        );
    }

    public function test_integration_absent_or_red_is_never_complete(): void
    {
        $ids = ['S1'];
        $states = ['S1' => $this->deliveredState('S1')];
        $cert = new FakeRealCycleCert();
        $cert->bySession['sess-1'] = ['S1' => $cert::STATUS_CERTIFIED];

        // integration_check absent
        $out = $this->service(new FakeTracker($this->ledger($states)), $cert, new FakeOperationalCert())->certify([
            'decomposed_plan' => $this->plan($ids),
            'area_id' => 'a',
            'session_ids' => ['sess-1'],
        ]);
        $this->assertFalse($out['integration_green']);
        $this->assertNotSame(PlanDeliveryCertificationService::STATUS_COMPLETE, $out['status']);

        // integration_check green=false
        $cert2 = new FakeRealCycleCert();
        $cert2->bySession['sess-1'] = ['S1' => $cert2::STATUS_CERTIFIED];
        $out2 = $this->service(new FakeTracker($this->ledger($states)), $cert2, new FakeOperationalCert())->certify([
            'decomposed_plan' => $this->plan($ids),
            'area_id' => 'a',
            'session_ids' => ['sess-1'],
            'integration_check' => ['green' => false, 'evidence_refs' => []],
        ]);
        $this->assertFalse($out2['integration_green']);
        $this->assertNotSame(PlanDeliveryCertificationService::STATUS_COMPLETE, $out2['status']);
    }

    public function test_dependency_order_violation_blocks_complete(): void
    {
        $ids = ['S1', 'S2'];
        // S2 delivered but its dependency S1 is NOT delivered
        $states = [
            'S1' => ['slice_id' => 'S1', 'state' => 'in_progress', 'merge_hash' => null, 'provider_proof' => false, 'acceptance_met' => false],
            'S2' => $this->deliveredState('S2'),
        ];
        $cert = new FakeRealCycleCert();
        $cert->bySession['sess-1'] = ['S2' => $cert::STATUS_CERTIFIED];

        $out = $this->service(new FakeTracker($this->ledger($states)), $cert, new FakeOperationalCert())->certify([
            'decomposed_plan' => $this->plan($ids, ['S2' => ['S1']]),
            'area_id' => 'a',
            'session_ids' => ['sess-1'],
            'integration_check' => ['green' => true],
        ]);

        $this->assertFalse($out['dependency_order_preserved']);
        $this->assertSame(PlanDeliveryCertificationService::STATUS_PARTIAL, $out['status']);
        $this->assertContains('dependency_order_not_preserved', $out['blockers']);
    }

    public function test_zero_slices_is_blocked(): void
    {
        $out = $this->service(new FakeTracker($this->ledger([])), new FakeRealCycleCert(), new FakeOperationalCert())->certify([
            'decomposed_plan' => $this->plan([]),
            'area_id' => 'a',
            'session_ids' => [],
            'integration_check' => ['green' => true],
        ]);

        $this->assertSame(PlanDeliveryCertificationService::STATUS_BLOCKED, $out['status']);
        $this->assertContains('zero_slices', $out['blockers']);
    }

    public function test_anti_fake_delivered_without_provider_proof_not_complete(): void
    {
        $ids = ['S1'];
        // tracker claims delivered but provider_proof=false (forged shape)
        $states = ['S1' => [
            'slice_id' => 'S1', 'state' => 'delivered', 'merge_hash' => 'm-S1',
            'provider_proof' => false, 'acceptance_met' => true,
        ]];
        $cert = new FakeRealCycleCert();
        $cert->bySession['sess-1'] = ['S1' => $cert::STATUS_CERTIFIED];

        $out = $this->service(new FakeTracker($this->ledger($states)), $cert, new FakeOperationalCert())->certify([
            'decomposed_plan' => $this->plan($ids),
            'area_id' => 'a',
            'session_ids' => ['sess-1'],
            'integration_check' => ['green' => true],
        ]);

        $this->assertNotSame(PlanDeliveryCertificationService::STATUS_COMPLETE, $out['status']);
        $this->assertSame(0, $out['delivered_slices']);
        $this->assertFalse($out['all_slices_merged_with_provider_proof']);
    }

    public function test_claim_policy_is_hardcoded_false(): void
    {
        $out = $this->service(new FakeTracker($this->ledger([])), new FakeRealCycleCert(), new FakeOperationalCert())->certify([
            'decomposed_plan' => $this->plan([]),
            'area_id' => 'a',
        ]);

        $this->assertSame([
            'ready_from_synthetic_shape' => false,
            'benchmark' => false,
            'rivals' => false,
            'superiority' => false,
        ], $out['claim_policy']);
    }

    public function test_hash_is_deterministic_over_verdict(): void
    {
        $ids = ['S1'];
        $states = ['S1' => $this->deliveredState('S1')];
        $make = function () use ($ids, $states) {
            $cert = new FakeRealCycleCert();
            $cert->bySession['sess-1'] = ['S1' => $cert::STATUS_CERTIFIED];

            return $this->service(new FakeTracker($this->ledger($states)), $cert, new FakeOperationalCert())->certify([
                'decomposed_plan' => $this->plan($ids),
                'area_id' => 'a',
                'session_ids' => ['sess-1'],
                'integration_check' => ['green' => true],
            ]);
        };
        $a = $make();
        $b = $make();
        $this->assertSame($a['plan_delivery_cert_hash'], $b['plan_delivery_cert_hash']);
    }
}
