<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\NightShift\AreaFocusLoopReadModelService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusCycleRecorderService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusLoopOperationalCertificationService;
use Tests\TestCase;

/**
 * AP-722 · Area Focus Loop Operational Certification contract tests.
 *
 * Findings are injected through the read model's `area_findings` seam so the
 * composition is deterministic and side-effect free; the evidence cycle is
 * pointed at a temp dir so the append-only write never touches real storage.
 */
class AreaFocusLoopOperationalCertificationServiceTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir().'/afl_cert_'.uniqid('', true);
        @mkdir($this->tmpRoot, 0775, true);
        // Point the AP-720 recorder at temp storage and reuse that instance.
        $recorder = app(AreaFocusCycleRecorderService::class);
        $recorder->setStorageRootForTesting($this->tmpRoot);
        $this->app->instance(AreaFocusCycleRecorderService::class, $recorder);
    }

    private function service(): AreaFocusLoopOperationalCertificationService
    {
        return app(AreaFocusLoopOperationalCertificationService::class);
    }

    /**
     * @return array<string,mixed>
     */
    private function areaFindings(): array
    {
        $mk = function (string $seed, string $route, string $sev): array {
            $raw = hash('sha256', 'agentic_engineering_os|'.$seed);

            return [
                'schema_version' => 'atlas.software_company_stewardship.area_finding.v1',
                'area_id' => 'agentic_engineering_os',
                'finding_type' => $seed,
                'title' => 'Finding '.$seed,
                'detail' => 'detail '.$seed,
                'severity' => $sev,
                'risk_level' => $sev,
                'confidence' => 'high',
                'route_hint' => $route,
                'evidence_refs' => ['ref:'.$seed],
                'recommended_action' => 'Operator review required.',
                'finding_id' => 'aef_'.substr($raw, 0, 16),
                'finding_hash' => 'sha256:'.$raw,
                'priority_score' => 209,
            ];
        };

        return [
            'status' => 'ready',
            'report_hash' => 'sha256:'.hash('sha256', 'area_findings_fixture'),
            'findings' => [
                $mk('docs_stale', 'self_directed_evolution', 'medium'),
                $mk('missing_test', 'atlas_dev', 'medium'),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $over
     * @return array<string,mixed>
     */
    private function input(array $over = []): array
    {
        return array_merge([
            'area_id' => AreaFocusLoopReadModelService::PRIORITY_AREA,
            'area_findings' => $this->areaFindings(),
        ], $over);
    }

    public function test_emits_certification_schema_and_check_matrix(): void
    {
        $cert = $this->service()->certify($this->input(['record_evidence' => false]));

        $this->assertSame(AreaFocusLoopOperationalCertificationService::CERT_SCHEMA, $cert['schema_version']);
        $this->assertSame('AP-722', $cert['ap_contract']);
        $this->assertContains($cert['status'], ['operational', 'partial', 'blocked']);
        $this->assertStringStartsWith('sha256:', $cert['cert_hash']);

        $ids = array_map(static fn (array $c): string => $c['id'], $cert['slice_checks']);
        foreach (['read_model', 'inbox', 'work_order_router', 'governance'] as $id) {
            $this->assertContains($id, $ids, "missing slice check {$id}");
        }
        $this->assertSame(
            'Atlas Software Company Stewardship Stack é stack/capability family dentro do Atlas Autonomous Software Company Runtime, não OS novo.',
            $cert['stewardship_stack']['canonical_statement']
        );
    }

    public function test_cert_hash_is_deterministic(): void
    {
        $first = $this->service()->certify($this->input(['record_evidence' => false]));
        $second = $this->service()->certify($this->input(['record_evidence' => false]));

        $this->assertSame($first['cert_hash'], $second['cert_hash']);
    }

    public function test_governance_invariants_hold(): void
    {
        $cert = $this->service()->certify($this->input(['record_evidence' => false]));

        $this->assertTrue($cert['governance']['passed']);
        $this->assertSame([], $cert['governance']['violations']);
        foreach (['mutates_target_repo', 'provider_invoked', 'execution_executed', 'merge_performed', 'deploy_performed', 'secrets_accessed', 'autoapproval_allowed', 'parallel_runtime_created', 'new_os_created'] as $flag) {
            $this->assertFalse($cert['claim_policy'][$flag], "claim_policy.{$flag} must be false");
        }
        $this->assertTrue($cert['claim_policy']['read_only']);
    }

    public function test_inbox_check_is_operator_gated(): void
    {
        $cert = $this->service()->certify($this->input(['record_evidence' => false]));

        $inbox = $this->checkById($cert, 'inbox');
        $this->assertSame('pass', $inbox['status'], $inbox['detail'] ?? '');
    }

    public function test_partial_when_evidence_skipped(): void
    {
        $cert = $this->service()->certify($this->input(['record_evidence' => false]));

        $this->assertSame('partial', $cert['status']);
        $this->assertFalse($cert['operational']);
        $this->assertFalse($cert['evidence']['recorded']);
        $this->assertSame('skipped', $this->checkById($cert, 'durable_cycle')['status']);
    }

    public function test_operational_when_evidence_recorded(): void
    {
        $cert = $this->service()->certify($this->input(['record_evidence' => true]));

        $this->assertSame('operational', $cert['status'], json_encode($cert['slice_coverage']));
        $this->assertTrue($cert['operational']);
        $this->assertTrue($cert['evidence']['recorded']);
        $this->assertStringStartsWith('sha256:', $cert['evidence']['cycle_hash']);
        $this->assertTrue($cert['evidence']['pack_complete']);
        $this->assertSame('pass', $this->checkById($cert, 'durable_cycle')['status']);
        $this->assertSame('pass', $this->checkById($cert, 'evidence_pack')['status']);
    }

    public function test_blocked_when_area_is_unknown(): void
    {
        $cert = $this->service()->certify(['area_id' => 'not_a_real_area', 'record_evidence' => false]);

        $this->assertSame('blocked', $cert['status']);
        $this->assertFalse($cert['operational']);
        $this->assertSame('blocked', $this->checkById($cert, 'read_model')['status']);
    }

    /**
     * @param  array<string,mixed>  $cert
     * @return array<string,mixed>
     */
    private function checkById(array $cert, string $id): array
    {
        foreach ($cert['slice_checks'] as $check) {
            if (($check['id'] ?? '') === $id) {
                return $check;
            }
        }
        $this->fail("slice check {$id} not found");
    }
}
