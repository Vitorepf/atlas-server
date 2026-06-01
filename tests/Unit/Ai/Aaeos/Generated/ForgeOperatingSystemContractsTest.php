<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\ForgeOperatingSystemContractsService;
use Tests\TestCase;

/**
 * Pins the documented Forge OS contract invariants.
 *
 * @see docs/engineering-knowledge-base/atlas-forge-operating-system-contracts.md
 */
class ForgeOperatingSystemContractsTest extends TestCase
{
    private function service(): ForgeOperatingSystemContractsService
    {
        return new ForgeOperatingSystemContractsService();
    }

    /** Contract 4 defines exactly 17 canonical packet states (the doc bullet list). */
    public function test_packet_state_machine_has_seventeen_canonical_states(): void
    {
        $this->assertCount(17, ForgeOperatingSystemContractsService::PACKET_STATES);
        $this->assertContains('queued_for_integration', ForgeOperatingSystemContractsService::PACKET_STATES);
        $this->assertContains('waiting_for_tool_permission', ForgeOperatingSystemContractsService::PACKET_STATES);
    }

    /**
     * Contract 1: "Nenhum trabalho Forge inicia sem spec-mae..." — a mother spec
     * missing a required field fails and work may not start; the missing field is
     * named.
     */
    public function test_mother_spec_missing_field_blocks_work_start(): void
    {
        $spec = array_fill_keys(ForgeOperatingSystemContractsService::MOTHER_SPEC_FIELDS, 'x');
        unset($spec['criterio_de_conclusao_global']);

        $r = $this->service()->checkMotherSpec($spec);

        $this->assertSame(ForgeOperatingSystemContractsService::STATUS_FAIL, $r['status']);
        $this->assertFalse($r['work_may_start']);
        $this->assertSame(['criterio_de_conclusao_global'], $r['missing_fields']);
    }

    /**
     * Contract 2 + Regra para IA 1: "Packet sem escopo ou evidence nao entra em
     * execucao." A packet that is otherwise complete but has an EMPTY allowed_files
     * is rejected and may not enter execution.
     */
    public function test_work_packet_without_scope_or_evidence_cannot_execute(): void
    {
        $packet = array_fill_keys(ForgeOperatingSystemContractsService::PACKET_FIELDS, 'x');
        $packet['allowed_files'] = []; // empty scope: declared but empty == forbidden

        $r = $this->service()->checkWorkPacket($packet);

        $this->assertSame(ForgeOperatingSystemContractsService::STATUS_FAIL, $r['status']);
        $this->assertFalse($r['scope_and_evidence_present']);
        $this->assertFalse($r['may_enter_execution']);
        // The key is declared, so it is NOT a structural miss; the triad rule is
        // what blocks execution ("Packet sem escopo ou evidence...").
        $this->assertNotContains('allowed_files', $r['missing_fields']);
        $this->assertContains(
            'Packet has no scope/evidence triad (allowed_files + forbidden_files + evidence); it cannot enter execution.',
            $r['blocking_reasons'],
        );
    }

    /** A fully-populated work packet passes Contract 2 and may enter execution. */
    public function test_complete_work_packet_may_enter_execution(): void
    {
        $packet = array_fill_keys(ForgeOperatingSystemContractsService::PACKET_FIELDS, 'x');
        $packet['allowed_files'] = ['app/Services/Foo/'];
        $packet['forbidden_files'] = ['routes/'];
        $packet['evidence'] = ['report.json'];

        $r = $this->service()->checkWorkPacket($packet);

        $this->assertSame(ForgeOperatingSystemContractsService::STATUS_PASS, $r['status']);
        $this->assertTrue($r['may_enter_execution']);
        $this->assertSame([], $r['missing_fields']);
    }

    /**
     * Contract 2 nuance: a structural list field declared empty (dependencies: [])
     * is a valid declaration of "no dependencies" — it does NOT block as long as
     * the scope/evidence triad is satisfied.
     */
    public function test_empty_dependencies_list_is_a_valid_declaration(): void
    {
        $packet = array_fill_keys(ForgeOperatingSystemContractsService::PACKET_FIELDS, 'x');
        $packet['allowed_files'] = ['app/Services/Foo/'];
        $packet['forbidden_files'] = ['routes/'];
        $packet['evidence'] = ['report.json'];
        $packet['dependencies'] = []; // explicitly: no dependencies

        $r = $this->service()->checkWorkPacket($packet);

        $this->assertSame(ForgeOperatingSystemContractsService::STATUS_PASS, $r['status']);
        $this->assertTrue($r['may_enter_execution']);
        $this->assertNotContains('dependencies', $r['missing_fields']);
    }

    /**
     * Contract 4: a legal edge WITH a recorded event passes; the same edge with
     * NO event fails ("Estado muda apenas por evento registrado.").
     */
    public function test_state_transition_requires_recorded_event(): void
    {
        $svc = $this->service();

        $ok = $svc->checkStateTransition('verified', 'queued_for_integration', 'EVT-1');
        $this->assertSame(ForgeOperatingSystemContractsService::STATUS_PASS, $ok['status']);
        $this->assertTrue($ok['transition_permitted']);

        $noEvent = $svc->checkStateTransition('verified', 'queued_for_integration', null);
        $this->assertSame(ForgeOperatingSystemContractsService::STATUS_FAIL, $noEvent['status']);
        $this->assertFalse($noEvent['transition_permitted']);
    }

    /** Contract 4: an edge not in the documented machine is rejected even with an event. */
    public function test_illegal_state_transition_is_rejected(): void
    {
        $r = $this->service()->checkStateTransition('draft', 'released', 'EVT-9');

        $this->assertSame(ForgeOperatingSystemContractsService::STATUS_FAIL, $r['status']);
        $this->assertFalse($r['edge_allowed']);
        $this->assertFalse($r['transition_permitted']);
    }

    /**
     * Contract 11 + Regra para IA 4: "Release sem evidence/provenance e
     * impossivel." All 12 requirements met but provenance absent => fail.
     */
    public function test_release_without_provenance_is_blocked(): void
    {
        $release = array_fill_keys(ForgeOperatingSystemContractsService::RELEASE_REQUIREMENTS, true);
        $release['artifacts_present'] = true;
        $release['provenance_present'] = false;

        $r = $this->service()->checkReleaseGate($release);

        $this->assertSame(ForgeOperatingSystemContractsService::STATUS_FAIL, $r['status']);
        $this->assertFalse($r['release_permitted']);
        $this->assertSame([], $r['unmet_requirements']); // requirements OK, provenance is the blocker
    }

    /** Contract 11: a release with all 12 requirements + artifacts + provenance passes. */
    public function test_fully_satisfied_release_passes(): void
    {
        $release = array_fill_keys(ForgeOperatingSystemContractsService::RELEASE_REQUIREMENTS, true);
        $release['artifacts_present'] = true;
        $release['provenance_present'] = true;

        $r = $this->service()->checkReleaseGate($release);

        $this->assertSame(ForgeOperatingSystemContractsService::STATUS_PASS, $r['status']);
        $this->assertTrue($r['release_permitted']);
    }

    /**
     * Regras para IA: granting a provider policy authority is a hard violation
     * ("Nao dar autoridade de politica a provider...").
     */
    public function test_provider_policy_authority_is_a_violation(): void
    {
        $r = $this->service()->checkAiRules([
            'packet' => [
                'allowed_files' => ['app/'],
                'forbidden_files' => ['routes/'],
                'evidence' => ['e.json'],
            ],
            'provider_has_policy_authority' => true,
            'permission_gate_skipped' => false,
            'release' => ['artifacts_present' => true, 'provenance_present' => true],
            'treats_future_as_implemented' => false,
        ]);

        $this->assertSame(ForgeOperatingSystemContractsService::STATUS_FAIL, $r['status']);
        $this->assertFalse($r['compliant']);
        $this->assertContains('provider_granted_policy_authority', $r['violations']);
    }

    /**
     * checkBundle aggregates: a green mother spec + bad transition => the bundle
     * fails and names the failed surface (no surface is quietly skipped).
     */
    public function test_bundle_fails_when_any_surface_fails(): void
    {
        $r = $this->service()->checkBundle([
            'mother_spec' => array_fill_keys(ForgeOperatingSystemContractsService::MOTHER_SPEC_FIELDS, 'x'),
            'state_transition' => ['from' => 'draft', 'to' => 'released', 'event_id' => 'EVT-1'],
        ]);

        $this->assertSame(ForgeOperatingSystemContractsService::STATUS_FAIL, $r['status']);
        $this->assertFalse($r['forge_ready']);
        $this->assertContains('packet_state_machine', $r['failed_surfaces']);
        $this->assertSame(['mother_spec', 'packet_state_machine'], $r['surfaces_checked']);
    }
}
