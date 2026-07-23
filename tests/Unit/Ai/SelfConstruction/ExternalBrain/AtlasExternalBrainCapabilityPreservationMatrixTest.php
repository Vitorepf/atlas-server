<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCapabilityPreservationMatrix;
use Tests\TestCase;

final class AtlasExternalBrainCapabilityPreservationMatrixTest extends TestCase
{
    private function matrix(): AtlasExternalBrainCapabilityPreservationMatrix
    {
        return new AtlasExternalBrainCapabilityPreservationMatrix;
    }

    private function preservedCapability(string $id): array
    {
        return [
            'capability_id' => $id,
            'before_owner' => 'OldOwnerService',
            'after_owner' => 'NewOwnerService',
            'tests' => ["tests/Unit/{$id}Test.php"],
            'runtime_evidence' => "route:GET /{$id}",
        ];
    }

    public function test_schema_present(): void
    {
        $r = $this->matrix()->evaluate(['capabilities' => []]);
        $this->assertSame(AtlasExternalBrainCapabilityPreservationMatrix::SCHEMA, $r['schema']);
    }

    public function test_empty_capabilities_is_approved(): void
    {
        $r = $this->matrix()->evaluate(['capabilities' => []]);
        $this->assertSame(AtlasExternalBrainCapabilityPreservationMatrix::VERDICT_APPROVED, $r['verdict']);
    }

    // ── AC: maps each capability to the 5 required fields ────────────────────

    public function test_matrix_row_has_all_required_fields(): void
    {
        $r = $this->matrix()->evaluate(['capabilities' => [$this->preservedCapability('cap1')]]);
        $row = $r['matrix'][0];

        foreach (['before_owner', 'after_owner', 'tests', 'runtime_evidence', 'preservation_status'] as $field) {
            $this->assertArrayHasKey($field, $row, "Missing field: {$field}");
        }
    }

    public function test_preserved_capability_yields_approved_verdict(): void
    {
        $r = $this->matrix()->evaluate(['capabilities' => [$this->preservedCapability('cap2')]]);

        $this->assertSame(AtlasExternalBrainCapabilityPreservationMatrix::VERDICT_APPROVED, $r['verdict']);
        $this->assertSame(AtlasExternalBrainCapabilityPreservationMatrix::STATUS_PRESERVED, $r['matrix'][0]['preservation_status']);
        $this->assertSame([], $r['matrix'][0]['missing_preservation_facts']);
    }

    // ── AC: any lost or unproved capability produces hold with exact missing facts ──

    public function test_missing_after_owner_is_lost_and_holds(): void
    {
        $capability = $this->preservedCapability('cap3');
        unset($capability['after_owner']);

        $r = $this->matrix()->evaluate(['capabilities' => [$capability]]);

        $this->assertSame(AtlasExternalBrainCapabilityPreservationMatrix::VERDICT_HOLD, $r['verdict']);
        $this->assertSame(AtlasExternalBrainCapabilityPreservationMatrix::STATUS_LOST, $r['matrix'][0]['preservation_status']);
        $this->assertContains('missing_after_owner', $r['matrix'][0]['missing_preservation_facts']);
        $this->assertContains('cap3:missing_after_owner', $r['missing_preservation_facts']);
    }

    public function test_missing_tests_is_unproved_and_holds(): void
    {
        $capability = $this->preservedCapability('cap4');
        unset($capability['tests']);

        $r = $this->matrix()->evaluate(['capabilities' => [$capability]]);

        $this->assertSame(AtlasExternalBrainCapabilityPreservationMatrix::VERDICT_HOLD, $r['verdict']);
        $this->assertSame(AtlasExternalBrainCapabilityPreservationMatrix::STATUS_UNPROVED, $r['matrix'][0]['preservation_status']);
        $this->assertContains('missing_tests', $r['matrix'][0]['missing_preservation_facts']);
    }

    public function test_missing_runtime_evidence_is_unproved_and_holds(): void
    {
        $capability = $this->preservedCapability('cap5');
        unset($capability['runtime_evidence']);

        $r = $this->matrix()->evaluate(['capabilities' => [$capability]]);

        $this->assertSame(AtlasExternalBrainCapabilityPreservationMatrix::VERDICT_HOLD, $r['verdict']);
        $this->assertSame(AtlasExternalBrainCapabilityPreservationMatrix::STATUS_UNPROVED, $r['matrix'][0]['preservation_status']);
        $this->assertContains('missing_runtime_evidence', $r['matrix'][0]['missing_preservation_facts']);
    }

    public function test_lost_capability_does_not_also_report_missing_tests_or_evidence(): void
    {
        // Lost (no owner) is a distinct terminal state — it should not pile on tests/evidence noise.
        $r = $this->matrix()->evaluate(['capabilities' => [[
            'capability_id' => 'cap6',
            'before_owner' => 'OldOwner',
        ]]]);

        $this->assertSame(AtlasExternalBrainCapabilityPreservationMatrix::STATUS_LOST, $r['matrix'][0]['preservation_status']);
        $this->assertSame(['missing_after_owner'], $r['matrix'][0]['missing_preservation_facts']);
    }

    public function test_one_lost_capability_holds_whole_matrix_even_with_others_preserved(): void
    {
        $r = $this->matrix()->evaluate(['capabilities' => [
            $this->preservedCapability('good'),
            ['capability_id' => 'bad', 'before_owner' => 'X'],
        ]]);

        $this->assertSame(AtlasExternalBrainCapabilityPreservationMatrix::VERDICT_HOLD, $r['verdict']);
    }

    // ── determinism ───────────────────────────────────────────────────────────

    public function test_evaluate_is_deterministic(): void
    {
        $input = ['capabilities' => [$this->preservedCapability('a'), ['capability_id' => 'b']]];

        $this->assertSame(
            json_encode($this->matrix()->evaluate($input)),
            json_encode($this->matrix()->evaluate($input)),
        );
    }

    public function test_malformed_capabilities_are_skipped(): void
    {
        $r = $this->matrix()->evaluate(['capabilities' => [
            ['before_owner' => 'X'], // missing capability_id
            $this->preservedCapability('valid'),
        ]]);

        $this->assertCount(1, $r['matrix']);
        $this->assertSame('valid', $r['matrix'][0]['capability_id']);
    }
}
