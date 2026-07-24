<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aaeos;

use App\Services\Ai\EngineeringKernel\EngineeringOutcome;
use App\Services\Ai\EngineeringKernel\EngineeringRoleRoster;
use App\Services\Ai\EngineeringKernel\KernelEvidenceAuthority;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * P2a.2: EngineeringOutcome v3 expand + dual-read of historical v2.
 * v2 rows stay read-only (never inferred/backfilled to v3).
 * Adverse v3 requires precise failure_reason_code + failure_reason.
 */
final class AaeosEngineeringOutcomeSchemaRolloutTest extends TestCase
{
    public function test_dual_read_accepts_historical_v2_without_backfill_to_v3(): void
    {
        $v2 = $this->validOutcome(EngineeringOutcome::SCHEMA_V2, 'completed_read_only');
        $outcome = EngineeringOutcome::fromArray($v2);

        $this->assertSame(EngineeringOutcome::SCHEMA_V2, $outcome->schemaVersion);
        $this->assertNull($outcome->failureReasonCode);
        $this->assertNull($outcome->failureReason);
        $array = $outcome->toArray();
        $this->assertSame(EngineeringOutcome::SCHEMA_V2, $array['schema_version']);
        $this->assertArrayNotHasKey('failure_reason_code', $array);
        $this->assertArrayNotHasKey('failure_reason', $array);
        // Round-trip stays v2 — never silently promoted.
        $again = EngineeringOutcome::fromArray($array);
        $this->assertSame(EngineeringOutcome::SCHEMA_V2, $again->schemaVersion);
        $this->assertSame($outcome->outcomeHash, $again->outcomeHash);
    }

    public function test_v2_rejects_v3_failure_fields_as_unknown_no_backfill(): void
    {
        $data = $this->validOutcome(EngineeringOutcome::SCHEMA_V2, 'blocked');
        $data['failure_reason_code'] = 'provider_timeout';
        $data['failure_reason'] = 'provider timed out after budget';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('engineering_outcome_unknown_fields');
        EngineeringOutcome::fromArray($data);
    }

    public function test_v3_adverse_requires_precise_failure_fields(): void
    {
        $missingCode = $this->validOutcome(EngineeringOutcome::SCHEMA_V3, 'blocked', withFailure: false);
        try {
            EngineeringOutcome::fromArray($missingCode);
            $this->fail('adverse v3 without failure_reason_code must fail closed');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('failure_reason_code_required_for_adverse_outcome', $e->getMessage());
        }

        $blankReason = $this->validOutcome(EngineeringOutcome::SCHEMA_V3, 'refused', withFailure: true);
        $blankReason['failure_reason'] = '   ';
        try {
            EngineeringOutcome::fromArray($blankReason);
            $this->fail('blank failure_reason must fail closed');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('failure_reason_required_for_adverse_outcome', $e->getMessage());
        }
    }

    public function test_v3_adverse_with_precise_fields_round_trips(): void
    {
        $data = $this->validOutcome(EngineeringOutcome::SCHEMA_V3, 'blocked', withFailure: true);
        $outcome = EngineeringOutcome::fromArray($data);

        $this->assertSame(EngineeringOutcome::SCHEMA_V3, $outcome->schemaVersion);
        $this->assertSame('blocked', $outcome->status);
        $this->assertSame('path_core_gate_blocked', $outcome->failureReasonCode);
        $this->assertSame('Court refused land: precise verification gate failed on order hash.', $outcome->failureReason);

        $array = $outcome->toArray();
        $this->assertSame('path_core_gate_blocked', $array['failure_reason_code']);
        $this->assertSame('Court refused land: precise verification gate failed on order hash.', $array['failure_reason']);
        $this->assertSame($outcome->outcomeHash, EngineeringOutcome::fromArray($array)->outcomeHash);
    }

    public function test_v3_non_adverse_does_not_require_failure_fields(): void
    {
        $data = $this->validOutcome(EngineeringOutcome::SCHEMA_V3, 'completed_read_only', withFailure: false);
        $outcome = EngineeringOutcome::fromArray($data);

        $this->assertSame(EngineeringOutcome::SCHEMA_V3, $outcome->schemaVersion);
        $this->assertNull($outcome->failureReasonCode);
        $this->assertNull($outcome->failureReason);
        $this->assertArrayHasKey('failure_reason_code', $outcome->toArray());
        $this->assertNull($outcome->toArray()['failure_reason_code']);
    }

    public function test_unknown_schema_is_refused(): void
    {
        $data = $this->validOutcome(EngineeringOutcome::SCHEMA_V2, 'blocked');
        $data['schema_version'] = 'atlas.engineering_outcome.v9';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('schema_version_invalid');
        EngineeringOutcome::fromArray($data);
    }

    public function test_shadow_dual_read_v2_and_v3_share_status_surface_without_mutation(): void
    {
        $v2 = EngineeringOutcome::fromArray($this->validOutcome(EngineeringOutcome::SCHEMA_V2, 'held'));
        $v3 = EngineeringOutcome::fromArray($this->validOutcome(EngineeringOutcome::SCHEMA_V3, 'held', withFailure: true));

        $this->assertSame('held', $v2->status);
        $this->assertSame('held', $v3->status);
        $this->assertTrue(EngineeringOutcome::isAdverseStatus('held'));
        // Historical v2 never gains failure fields via toArray/fromArray cycle.
        $this->assertArrayNotHasKey('failure_reason_code', $v2->toArray());
        $this->assertNotNull($v3->failureReasonCode);
    }

    /**
     * @return array<string,mixed>
     */
    private function validOutcome(string $schema, string $status, bool $withFailure = false): array
    {
        $dispositions = [];
        foreach (EngineeringRoleRoster::OFFICIAL_ROLES as $role) {
            $entry = [
                'status' => $status === 'completed_read_only' ? 'pass' : 'block',
                'evidence_hash' => hash('sha256', $role),
                'signature' => hash('sha256', 'sign-'.$role),
            ];
            if ($status === 'completed_read_only') {
                $entry['receipt_ref'] = 'role-event-'.$role;
                $entry['receipt_event_hash'] = hash('sha256', 'event-'.$role);
            }
            $dispositions[$role] = $entry;
        }

        $hashes = [
            'order' => hash('sha256', 'order'),
            'intent' => hash('sha256', 'intent'),
            'spec' => hash('sha256', 'spec'),
            'world' => hash('sha256', 'world'),
            'baseline' => hash('sha256', 'baseline'),
            'diff' => hash('sha256', 'diff'),
            'evidence' => hash('sha256', 'evidence'),
            'release' => hash('sha256', 'release'),
        ];

        if ($status === 'completed_read_only') {
            $evidence = [
                'hash' => $hashes['evidence'],
                'status' => 'accepted',
                'gate_verdict' => ['status' => 'promote'],
                'acceptance_authority_ref' => 'acceptance-event',
                'acceptance_authority_event_hash' => hash('sha256', 'acceptance-event'),
            ];
            $uncertainties = [];
        } else {
            $evidence = [
                'hash' => $hashes['evidence'],
                'status' => 'refused',
                'reason' => 'gate_blocked',
            ];
            $uncertainties = ['held_or_blocked'];
        }

        $data = [
            'schema_version' => $schema,
            'run_id' => 'run-p2a2',
            'delivery_id' => 'delivery-p2a2',
            'status' => $status,
            'correlated_hashes' => $hashes,
            'role_dispositions' => $dispositions,
            'evidence_bundle' => $evidence,
            'provider_receipt' => ['status' => 'not_applicable_read_only'],
            'sandbox_receipt' => ['status' => 'not_applicable_read_only'],
            'release_receipt' => ['status' => 'not_applicable_read_only', 'hash' => $hashes['release']],
            'canary_rollback_receipt' => ['status' => 'not_applicable_read_only'],
            'operator_effort' => ['active_seconds' => 0],
            'cost' => ['amount' => 0, 'currency' => 'USD'],
            'tokens' => ['input' => 0, 'output' => 0],
            'elapsed_ms' => 1,
            'uncertainties' => $uncertainties,
            'observation_schedule' => array_fill_keys(EngineeringOutcome::WINDOWS, 'pending'),
            'claim_eligible' => false,
        ];

        if ($schema === EngineeringOutcome::SCHEMA_V3) {
            if ($withFailure) {
                $data['failure_reason_code'] = 'path_core_gate_blocked';
                $data['failure_reason'] = 'Court refused land: precise verification gate failed on order hash.';
            } else {
                $data['failure_reason_code'] = null;
                $data['failure_reason'] = null;
            }
        }

        if ($status === 'completed_read_only') {
            $data['evidence_bundle']['authority'] = app(KernelEvidenceAuthority::class)->sealOutcome($data);
        }

        return $data;
    }
}
