<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Readiness;

use App\Services\Ai\SelfConstruction\Readiness\ReadinessCertificationWorkbenchQuartetBuilder;
use Tests\TestCase;

class ReadinessCertificationWorkbenchQuartetBuilderTest extends TestCase
{
    public function test_contract_stage_produces_expected_envelope(): void
    {
        $verdict = ReadinessCertificationWorkbenchQuartetBuilder::build(
            'certification_evidence_query',
            'Certification Evidence Query',
            'atlas.certification_evidence_query.v1',
            'App\\Services\\Ai\\SelfConstruction\\AgentControlPlaneCertificationEvidenceQueryService',
            'contract',
        );

        self::assertSame(
            'atlas.self_construction_agent_control_plane_certification_evidence_query_contract.v1',
            $verdict['schema_version'],
        );
        self::assertSame('agent_control_plane_certification_evidence_query_contract_ready', $verdict['status']);
        self::assertSame('read_only_agent_control_plane_certification_evidence_query_contract', $verdict['mode']);
        self::assertFalse($verdict['execution_allowed']);
        self::assertFalse($verdict['dispatch_allowed']);
        self::assertFalse($verdict['ledger_write_allowed']);
        self::assertFalse($verdict['runtime_write_allowed']);
        self::assertArrayHasKey('agent_control_plane_certification_evidence_query_contract', $verdict);
        self::assertArrayHasKey('agent_control_plane_certification_evidence_query_contract_hash', $verdict);
        self::assertNotEmpty($verdict['agent_control_plane_certification_evidence_query_contract_hash']);
    }

    public function test_preflight_stage_includes_service_class_exists_check(): void
    {
        $verdict = ReadinessCertificationWorkbenchQuartetBuilder::build(
            'certification_evidence_query',
            'Certification Evidence Query',
            'atlas.certification_evidence_query.v1',
            'App\\Services\\Ai\\SelfConstruction\\AgentControlPlaneCertificationEvidenceQueryService',
            'preflight',
        );

        $inner = $verdict['agent_control_plane_certification_evidence_query_preflight'];

        self::assertArrayHasKey('preflight_checks', $inner);
        self::assertArrayHasKey('service_class_exists', $inner['preflight_checks']);
        self::assertArrayHasKey('blocking_count', $inner);
        self::assertArrayHasKey('blocking_reasons', $inner);
    }

    public function test_preflight_stage_flags_missing_service_class(): void
    {
        $verdict = ReadinessCertificationWorkbenchQuartetBuilder::build(
            'nonexistent_surface',
            'Nonexistent Surface',
            'atlas.nonexistent.v1',
            'App\\Nonexistent\\Service',
            'preflight',
        );

        $inner = $verdict['agent_control_plane_nonexistent_surface_preflight'];

        self::assertFalse($inner['preflight_checks']['service_class_exists']);
        self::assertSame(1, $inner['blocking_count']);
        self::assertSame(['service_class_missing'], $inner['blocking_reasons']);
    }

    public function test_implementation_packet_stage_includes_allowed_files_and_criteria(): void
    {
        $verdict = ReadinessCertificationWorkbenchQuartetBuilder::build(
            'certification_evidence_query',
            'Certification Evidence Query',
            'atlas.certification_evidence_query.v1',
            'App\\Services\\Ai\\SelfConstruction\\AgentControlPlaneCertificationEvidenceQueryService',
            'implementation_packet',
        );

        $inner = $verdict['agent_control_plane_certification_evidence_query_implementation_packet'];

        self::assertSame(
            'ready_for_scoped_agent_control_plane_certification_evidence_query_implementation',
            $verdict['status'],
        );
        self::assertArrayHasKey('allowed_files', $inner);
        self::assertNotEmpty($inner['allowed_files']);
        self::assertArrayHasKey('acceptance_criteria', $inner);
        self::assertNotEmpty($inner['acceptance_criteria']);
        self::assertArrayHasKey('implementation_policy', $inner);
    }

    public function test_implementation_packet_status_differs_from_other_stages(): void
    {
        $contract = ReadinessCertificationWorkbenchQuartetBuilder::build('x', 'X', 'v1', self::class, 'contract');
        $packet = ReadinessCertificationWorkbenchQuartetBuilder::build('x', 'X', 'v1', self::class, 'implementation_packet');

        self::assertSame('agent_control_plane_x_contract_ready', $contract['status']);
        self::assertSame('ready_for_scoped_agent_control_plane_x_implementation', $packet['status']);
    }

    public function test_next_required_slice_advances_through_stages(): void
    {
        $contract = ReadinessCertificationWorkbenchQuartetBuilder::build('x', 'X', 'v1', self::class, 'contract');
        $preflight = ReadinessCertificationWorkbenchQuartetBuilder::build('x', 'X', 'v1', self::class, 'preflight');
        $packet = ReadinessCertificationWorkbenchQuartetBuilder::build('x', 'X', 'v1', self::class, 'implementation_packet');

        $c = $contract['agent_control_plane_x_contract'];
        $p = $preflight['agent_control_plane_x_preflight'];
        $i = $packet['agent_control_plane_x_implementation_packet'];

        self::assertSame('activate_agent_control_plane_x_preflight', $c['next_required_slice']);
        self::assertSame('activate_agent_control_plane_x_implementation_packet', $p['next_required_slice']);
        self::assertSame('activate_agent_control_plane_x_service', $i['next_required_slice']);
    }

    public function test_hash_is_stable_and_deterministic(): void
    {
        $a = ReadinessCertificationWorkbenchQuartetBuilder::build('x', 'X', 'v1', self::class, 'contract');
        $b = ReadinessCertificationWorkbenchQuartetBuilder::build('x', 'X', 'v1', self::class, 'contract');

        self::assertSame($a['agent_control_plane_x_contract_hash'], $b['agent_control_plane_x_contract_hash']);
    }

    public function test_invariants_declare_read_only_contract(): void
    {
        $verdict = ReadinessCertificationWorkbenchQuartetBuilder::build('x', 'X', 'v1', self::class, 'contract');
        $inner = $verdict['agent_control_plane_x_contract'];

        foreach (['is_read_only', 'does_not_advance_pointer', 'does_not_write_ledger'] as $invariant) {
            self::assertArrayHasKey("x_{$invariant}", $inner['invariants']);
            self::assertTrue($inner['invariants']["x_{$invariant}"]);
        }
    }

    public function test_non_execution_guarantees_are_emitted(): void
    {
        $verdict = ReadinessCertificationWorkbenchQuartetBuilder::build('x', 'X', 'v1', self::class, 'contract');

        self::assertNotEmpty($verdict['non_execution_guarantees']);
        self::assertContains('agent_control_plane_x_contract_does_not_start_codex', $verdict['non_execution_guarantees']);
        self::assertContains('agent_control_plane_x_contract_does_not_enable_self_programming', $verdict['non_execution_guarantees']);
    }

    public function test_human_summary_contains_label(): void
    {
        $verdict = ReadinessCertificationWorkbenchQuartetBuilder::build(
            'x', 'My Special Surface', 'v1', self::class, 'contract',
        );

        self::assertSame('Agent Control Plane My Special Surface contract is ready and read-only.', $verdict['human_summary']);
    }
}
