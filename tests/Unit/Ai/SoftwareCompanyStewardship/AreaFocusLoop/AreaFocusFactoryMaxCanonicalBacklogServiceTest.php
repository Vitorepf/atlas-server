<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusFactoryMaxCanonicalBacklogService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusSelfConstructionAdmissionBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;
use Tests\TestCase;

/**
 * AP-806/AP-790 canonical backlog depth.
 *
 * Provider-free proof: canonical AAEOS docs become high-value findings, those
 * findings decompose into bounded Self-Construction packets, and the first
 * packet is selectable by factory_max without falling to recovery/filler.
 */
final class AreaFocusFactoryMaxCanonicalBacklogServiceTest extends TestCase
{
    private function backlog(): AreaFocusFactoryMaxCanonicalBacklogService
    {
        return app(AreaFocusFactoryMaxCanonicalBacklogService::class);
    }

    public function test_canonical_backlog_exposes_five_plus_high_value_findings_with_real_sources(): void
    {
        $findings = $this->backlog()->findings('agentic_engineering_os', 'dev_forge');

        $this->assertGreaterThanOrEqual(5, count($findings));
        $this->assertLessThanOrEqual(8, count($findings));

        foreach ($findings as $finding) {
            $this->assertStringStartsWith('canonical_aaeos_', (string) ($finding['finding_id'] ?? ''));
            $this->assertSame('canonical_aaeos_backlog', $finding['origin'] ?? null);
            $this->assertSame('runtime_gap', $finding['origin_type'] ?? null);
            $this->assertSame('high', $finding['severity'] ?? null);
            $this->assertSame('atlas_dev', $finding['owner_candidate'] ?? null);
            $this->assertTrue((bool) ($finding['auto_execution_allowed'] ?? false));
            $this->assertFalse((bool) ($finding['operator_review_required'] ?? true));
            $this->assertNotEmpty($finding['value_reason'] ?? '');
            $this->assertFileExists(base_path((string) ($finding['source_doc'] ?? '')));

            $source = (string) (($finding['affected_files'] ?? [])[0] ?? '');
            $this->assertStringStartsWith('app/', $source);
            $this->assertFileExists(base_path($source));

            $tests = (array) data_get($finding, 'spec_seed.tests_required', []);
            $this->assertNotEmpty($tests);
            $this->assertFileExists(base_path((string) $tests[0]));
            $this->assertNotContains('missing_test', $finding);
            $this->assertStringNotContainsString('rivals', strtolower((string) ($finding['finding_id'] ?? '')));
        }
    }

    public function test_admission_report_proves_fifteen_plus_eligible_packets_without_provider_or_loop(): void
    {
        $report = $this->backlog()->admissionReport(
            app(AreaFocusSelfConstructionAdmissionBridgeService::class),
            'agentic_engineering_os',
            'dev_forge',
        );

        $this->assertSame(AreaFocusFactoryMaxCanonicalBacklogService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertFalse((bool) ($report['provider_invoked'] ?? true));
        $this->assertFalse((bool) ($report['loop_run'] ?? true));
        $this->assertGreaterThanOrEqual(5, (int) $report['eligible_parent_finding_count']);
        $this->assertGreaterThanOrEqual(15, (int) $report['eligible_packet_count']);
        $this->assertLessThanOrEqual(25, (int) $report['eligible_packet_count']);

        foreach ((array) $report['items'] as $item) {
            $this->assertNotEmpty($item['parent_finding_id'] ?? '');
            $this->assertNotEmpty($item['source_doc'] ?? '');
            $this->assertNotEmpty($item['value_reason'] ?? '');
            $this->assertNotEmpty($item['allowed_files'] ?? []);
            $this->assertNotEmpty($item['required_tests'] ?? []);
            $this->assertSame('high', $item['risk'] ?? null);
            $this->assertGreaterThanOrEqual(2, (int) ($item['packet_count'] ?? 0));
            $this->assertLessThanOrEqual(5, (int) ($item['packet_count'] ?? 0));
            $this->assertGreaterThanOrEqual(1, (int) ($item['safe_packet_count'] ?? 0));
            $this->assertTrue((bool) ($item['first_packet_selectable'] ?? false));
        }
    }

    public function test_every_canonical_parent_is_authority_gated_and_first_packet_clears_factory_max(): void
    {
        $session = app(AutonomousEvolutionSessionService::class);
        $candidateRejection = new \ReflectionMethod($session, 'candidateRejectionReason');
        $allowedFiles = new \ReflectionMethod($session, 'allowedFiles');
        $bridge = app(AreaFocusSelfConstructionAdmissionBridgeService::class);

        foreach ($this->backlog()->findings('agentic_engineering_os', 'dev_forge') as $finding) {
            $parentReason = (string) $candidateRejection->invoke(
                $session,
                $finding,
                $allowedFiles->invoke($session, $finding),
                [],
                AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX,
                'agentic_engineering_os',
                'dev_forge',
                [],
                false,
                [],
                null,
            );
            $this->assertContains(
                $parentReason,
                AreaFocusSelfConstructionAdmissionBridgeService::ADMISSIBLE_REJECTION_REASONS,
                'Parent should be bridge-admissible, got '.$parentReason.' for '.(string) ($finding['finding_id'] ?? ''),
            );

            $admission = $bridge->admit($finding, $parentReason, 'agentic_engineering_os', 'dev_forge');
            $this->assertTrue((bool) ($admission['admissible'] ?? false));

            $packetFinding = (array) ($admission['first_packet_finding'] ?? []);
            $packetReason = (string) $candidateRejection->invoke(
                $session,
                $packetFinding,
                $allowedFiles->invoke($session, $packetFinding),
                [],
                AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX,
                'agentic_engineering_os',
                'dev_forge',
                [],
                false,
                [],
                null,
            );
            $this->assertSame('', $packetReason, 'First packet must clear factory_max; got '.$packetReason);
        }
    }
}
