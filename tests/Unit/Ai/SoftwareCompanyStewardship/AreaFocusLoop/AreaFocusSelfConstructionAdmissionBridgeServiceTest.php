<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusSelfConstructionAdmissionBridgeService;
use Tests\TestCase;

/**
 * AP-806 · factory_max -> Self-Construction admission bridge (V1 dry-run proof).
 *
 * Pure, provider-free: proves a HIGH-VALUE finding rejected for an authority
 * reason becomes 2-5 small governed packets with a first selectable packet, and
 * that routine/non-authority rejections are NEVER admitted (no filler, no
 * recovery). All reused services (FindingSlicePlannerService, packet builder)
 * are deterministic and never call a provider/git.
 */
final class AreaFocusSelfConstructionAdmissionBridgeServiceTest extends TestCase
{
    private function bridge(): AreaFocusSelfConstructionAdmissionBridgeService
    {
        return app(AreaFocusSelfConstructionAdmissionBridgeService::class);
    }

    /**
     * @return array<string,mixed>
     */
    private function crossSystemHighValueFinding(): array
    {
        return [
            'finding_id' => 'afdf_cross_system_high_value',
            'finding_hash' => 'sha256:afdf_cross_system_high_value',
            'title' => 'Implement cross-system Dev-Forge evidence unification',
            'detail' => 'Unify Dev and Forge evidence references into one governed runtime path.',
            'kind' => 'feature',
            'origin_type' => 'structural',
            'severity' => 'high',
            'owner_candidate' => 'atlas_dev',
            'affected_files' => ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusEvidencePackService.php'],
            'evidence_refs' => ['expected_test:AreaFocusEvidencePackServiceTest.php'],
            'spec_seed' => ['candidate_id' => 'afdf_cross_system_high_value'],
            // A high-value finding worth packetizing is autonomously-executable but
            // authority-gated; without this it is rejected earlier by the
            // auto-execution gate, never reaching the authority gate the bridge serves.
            'auto_execution_allowed' => true,
            'operator_review_required' => false,
        ];
    }

    public function test_cross_system_high_value_finding_becomes_two_to_five_packets(): void
    {
        $result = $this->bridge()->admit(
            $this->crossSystemHighValueFinding(),
            'factory_max_rejects_high_risk_deep_finding_without_forge_authority',
            'agentic_engineering_os',
            'dev_forge',
        );

        $this->assertTrue($result['admissible'], 'a decomposable high-value finding must be admitted');
        $this->assertGreaterThanOrEqual(2, $result['packet_count']);
        $this->assertLessThanOrEqual(5, $result['packet_count']);
        $this->assertGreaterThanOrEqual(1, $result['safe_packet_count']);
        $this->assertIsArray($result['first_packet_finding']);
    }

    public function test_first_packet_is_small_bounded_and_carries_the_packet_contract(): void
    {
        $result = $this->bridge()->admit(
            $this->crossSystemHighValueFinding(),
            'factory_max_rejects_non_factory_scope_without_automerge_authority',
            'agentic_engineering_os',
            'dev_forge',
        );

        $packet = $result['first_packet'];
        $this->assertSame('planned', $packet['status']);
        $this->assertNotEmpty($packet['packet_id']);
        $this->assertSame('afdf_cross_system_high_value', $packet['parent_finding_id']);
        $this->assertGreaterThanOrEqual(1, $packet['slice_sequence']);
        $this->assertLessThanOrEqual(4, count($packet['allowed_files']), 'a packet must be bounded (<=4 files)');
        $this->assertNotEmpty($packet['allowed_files']);
        $this->assertTrue((bool) ($packet['claim']['claim_required'] ?? false));
        $this->assertTrue((bool) ($packet['lease']['lease_required'] ?? false));
        $this->assertSame(
            ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusEvidencePackService.php'],
            $packet['parent_affected_files'],
        );
        $this->assertNotEmpty($packet['stop_conditions']);

        // The first-packet finding is loop-selectable and ties into slice-progression.
        $finding = $result['first_packet_finding'];
        $this->assertSame($packet['packet_id'], $finding['active_slice_id'], 'active_slice_id must equal the packet/slice id for progression');
        $this->assertSame($packet['allowed_files'], $finding['affected_files']);
        $this->assertSame('afdf_cross_system_high_value', $finding['parent_finding_id']);
    }

    public function test_rejected_parent_becomes_an_admissible_packet_selectable_by_the_loop(): void
    {
        // The definitive V1 proof: the WHOLE high-value finding is rejected by
        // factory_max (authority gate), but the bridge turns it into a bounded
        // governed packet that CLEARS the same gate — so the loop selects the
        // packet instead of falling to starvation-recovery.
        $finding = $this->crossSystemHighValueFinding();
        $session = app(\App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService::class);
        $reproof = new \ReflectionMethod($session, 'candidateRejectionReason');
        $allowedFiles = new \ReflectionMethod($session, 'allowedFiles');

        $parentReason = (string) $reproof->invoke($session, $finding, $allowedFiles->invoke($session, $finding), [], 'factory_max', 'agentic_engineering_os', 'dev_forge', [], false, [], null);
        $this->assertContains(
            $parentReason,
            AreaFocusSelfConstructionAdmissionBridgeService::ADMISSIBLE_REJECTION_REASONS,
            'fixture must be an authority-gated high-value reject; got: '.$parentReason,
        );

        $result = $this->bridge()->admit($finding, $parentReason, 'agentic_engineering_os', 'dev_forge');
        $this->assertTrue($result['admissible']);
        $packetFinding = $result['first_packet_finding'];

        $packetReason = (string) $reproof->invoke($session, $packetFinding, $allowedFiles->invoke($session, $packetFinding), [], 'factory_max', 'agentic_engineering_os', 'dev_forge', [], false, [], null);
        $this->assertSame('', $packetReason, 'the bounded governed packet must be loop-selectable (clears the gate the parent failed); got: '.$packetReason);
    }

    public function test_canonical_runtime_gap_packet_does_not_authorize_parallel_contract_scaffold(): void
    {
        $result = $this->bridge()->admit(
            [
                'finding_id' => 'canonical_aaeos_aaeos_dept_maturity_debug_automated_root_cause',
                'finding_hash' => 'sha256:canonical-root-cause',
                'title' => 'Introduce automated root-cause contract in loop quality drift detector',
                'detail' => 'Map each detected drift to a canonical root-cause kind.',
                'kind' => 'runtime',
                'origin_type' => 'runtime_gap',
                'severity' => 'high',
                'owner_candidate' => 'atlas_dev',
                'affected_files' => [
                    'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/LoopQualityDriftDetectorService.php',
                ],
                'evidence_refs' => ['expected_test:LoopQualityDriftDetectorServiceTest.php'],
                'spec_seed' => [
                    'candidate_id' => 'canonical_aaeos_aaeos_dept_maturity_debug_automated_root_cause',
                    'tests_required' => [
                        'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/LoopQualityDriftDetectorServiceTest.php',
                    ],
                ],
                'auto_execution_allowed' => true,
                'operator_review_required' => false,
            ],
            'factory_max_rejects_high_risk_deep_finding_without_forge_authority',
            'agentic_engineering_os',
            'dev_forge',
        );

        $this->assertTrue($result['admissible']);
        $packet = $result['first_packet'];
        $this->assertSame([
            'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/LoopQualityDriftDetectorService.php',
            'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/LoopQualityDriftDetectorServiceTest.php',
        ], $packet['allowed_files']);
        $this->assertStringContainsString('Do not create new PHP files', $packet['objective']);
        $this->assertEmpty(array_filter(
            $packet['allowed_files'],
            static fn (string $file): bool => str_contains($file, 'AutomatedRootCauseContract')
                || str_ends_with($file, 'Contract.php'),
        ));
    }

    public function test_routine_rejection_is_never_admitted_no_filler(): void
    {
        // Routine / missing-test rejections are NOT high-value; the bridge must
        // refuse them (no packetization of filler) so they never become work.
        $result = $this->bridge()->admit(
            $this->crossSystemHighValueFinding(),
            'factory_max_rejects_routine_missing_test_work',
            'agentic_engineering_os',
            'dev_forge',
        );

        $this->assertFalse($result['admissible']);
        $this->assertSame('rejection_not_authority_gated', $result['admission_blocked_reason']);
        $this->assertNull($result['first_packet_finding']);
    }

    public function test_non_decomposable_finding_returns_admission_blocked_not_recovery(): void
    {
        // A generic objective with no concrete code target cannot be safely
        // packetized -> admission_blocked (NEVER starvation-recovery/filler).
        $result = $this->bridge()->admit(
            [
                'finding_id' => 'afdf_generic',
                'finding_hash' => 'sha256:afdf_generic',
                'title' => 'Make Atlas better',
                'detail' => 'Improve everything.',
                'kind' => 'feature',
                'severity' => 'high',
                'owner_candidate' => 'atlas_dev',
                'affected_files' => [],
            ],
            'factory_max_rejects_high_risk_deep_finding_without_forge_authority',
            'agentic_engineering_os',
            'dev_forge',
        );

        $this->assertFalse($result['admissible']);
        $this->assertNotSame('', $result['admission_blocked_reason']);
        $this->assertNull($result['first_packet_finding']);
        $this->assertStringNotContainsStringIgnoringCase('recovery', $result['admission_blocked_reason']);
    }
}
