<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusLoopCertificationService;
use Tests\TestCase;

/**
 * AP-725 contract tests for the Area Focus Loop structural certification.
 *
 * The `probes_default` / `probes` input seams let each scenario assert
 * presence/absence deterministically without touching the filesystem or the
 * container. A real (un-seamed) run is also exercised for the live repo.
 */
class AreaFocusLoopCertificationServiceTest extends TestCase
{
    private function service(): AreaFocusLoopCertificationService
    {
        return app(AreaFocusLoopCertificationService::class);
    }

    public function test_ready_read_only_with_fake_full_component_map(): void
    {
        $report = $this->service()->certify(['probes_default' => true]);

        $this->assertSame(AreaFocusLoopCertificationService::CERT_SCHEMA, $report['schema_version']);
        $this->assertSame(AreaFocusLoopCertificationService::STATUS_READY_READ_ONLY, $report['status']);
        $this->assertSame([], $report['missing_components']);
        $this->assertSame($report['component_count'], $report['present_count']);
        $this->assertFalse($report['mutation_ready']);
        $this->assertTrue($report['read_only']);
    }

    public function test_blocked_when_a_required_ap_is_missing(): void
    {
        $report = $this->service()->certify([
            'probes_default' => true,
            'probes' => ['ap_716' => false],
        ]);

        $this->assertSame(AreaFocusLoopCertificationService::STATUS_BLOCKED, $report['status']);
        $this->assertContains('ap_716', $report['missing_components']);
        $this->assertSame(1, $report['missing_count']);
    }

    public function test_blocked_when_a_required_service_is_missing(): void
    {
        $report = $this->service()->certify([
            'probes_default' => true,
            'probes' => ['svc_router' => false],
        ]);

        $this->assertSame(AreaFocusLoopCertificationService::STATUS_BLOCKED, $report['status']);
        $this->assertContains('svc_router', $report['missing_components']);
    }

    public function test_status_never_claims_autonomous_mutation(): void
    {
        foreach ([true, false] as $default) {
            $report = $this->service()->certify(['probes_default' => $default]);

            $this->assertContains($report['status'], [
                AreaFocusLoopCertificationService::STATUS_READY_READ_ONLY,
                AreaFocusLoopCertificationService::STATUS_BLOCKED,
            ]);
            $this->assertNotSame('ready_for_autonomous_mutation', $report['status']);
            $this->assertFalse($report['mutation_ready']);
            $this->assertFalse($report['claim_policy']['ready_for_autonomous_mutation']);
            $this->assertFalse($report['claim_policy']['runs_loop']);
            $this->assertFalse($report['claim_policy']['destructive_change']);
        }
    }

    public function test_deterministic_certification_hash_for_same_component_map(): void
    {
        $input = ['probes_default' => true, 'probes' => ['ap_717' => false]];

        $a = $this->service()->certify($input);
        $b = $this->service()->certify($input);

        $this->assertSame($a['certification_hash'], $b['certification_hash']);
        $this->assertStringStartsWith('sha256:', $a['certification_hash']);
        $this->assertSame($a['components'], $b['components']);
    }

    public function test_unsupported_area_is_blocked(): void
    {
        $report = $this->service()->certify(['area_id' => 'blackink', 'probes_default' => true]);

        $this->assertSame(AreaFocusLoopCertificationService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('unsupported_area', $report['reason']);
        $this->assertFalse($report['mutation_ready']);
    }

    public function test_pending_slice_aps_are_informational_not_blocking(): void
    {
        // AP-723/AP-724 absent must NOT block read-only readiness.
        $report = $this->service()->certify([
            'probes_default' => true,
            'probes' => ['pending_ap_723' => false, 'pending_ap_724' => false],
        ]);

        $this->assertSame(AreaFocusLoopCertificationService::STATUS_READY_READ_ONLY, $report['status']);
        $this->assertSame('not_yet_allocated', $report['pending_slice_aps']['AP-723']);
        $this->assertSame('not_yet_allocated', $report['pending_slice_aps']['AP-724']);
    }

    public function test_real_repo_run_resolves_live_components(): void
    {
        // No seam: probes the real repo. Every required component exists now, so
        // the live certification must be ready_read_only.
        $report = $this->service()->certify();

        $this->assertSame(
            AreaFocusLoopCertificationService::STATUS_READY_READ_ONLY,
            $report['status'],
            'live missing: '.implode(',', $report['missing_components']),
        );
        $this->assertGreaterThanOrEqual(25, $report['component_count']);
    }
}
