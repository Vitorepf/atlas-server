<?php

namespace Tests\Feature\Ai\Hermes;

use App\Models\HermesCapabilityCandidate;
use App\Services\Ai\Hermes\HermesCapabilityEnablementGate;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Feature coverage for the Atlas-sovereign capability enablement gate.
 *
 * The registry auto-detects a capability and quarantines it; this gate is the
 * explicit operator approval that flips a quarantined HermesCapabilityCandidate
 * to approved/enabled, closing the keystone loop. It is fail-closed: enablement
 * requires operator confirmation, high-risk always-quarantine classes also
 * require explicit operator authority, the row never mutates on a reject, every
 * path returns a sealed receipt, and on success the persisted
 * gate_json.enablement_receipt_hash equals the receipt's receipt_hash.
 */
class HermesCapabilityEnablementGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (require database_path('migrations/2026_06_02_000200_create_hermes_capability_candidates_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('hermes_capability_candidates');

        parent::tearDown();
    }

    public function test_low_risk_candidate_with_operator_confirmed_is_approved_and_enabled(): void
    {
        $candidate = $this->candidate();

        $receipt = $this->gate()->approve($candidate, [
            'operator_confirmed' => true,
            'approved_by' => 'vitor',
            'reason' => 'browser toolset is safe',
        ]);

        $this->assertSame('approved', $receipt['status']);
        $this->assertTrue((bool) $receipt['enablement_allowed_now']);
        $this->assertSame('toolset:browser', $receipt['capability_id']);
        $this->assertSame('toolset', $receipt['capability_class']);
        $this->assertSame('low', $receipt['risk_level']);
        $this->assertTrue((bool) $receipt['operator_confirmed']);
        $this->assertSame('quarantined_for_atlas_capability_review', $receipt['gate_status_before']);
        $this->assertSame('approved_for_atlas_capability_use', $receipt['gate_status_after']);
        $this->assertSame($candidate->id, $receipt['candidate_id']);
        $this->assertNotEmpty($receipt['receipt_hash']);

        $candidate->refresh();
        $this->assertTrue((bool) $candidate->enabled);
        $this->assertSame('approved_for_atlas_capability_use', $candidate->gate_status);
        $this->assertFalse((bool) $candidate->review_required);
        $this->assertNotNull($candidate->reviewed_at);

        // The persisted hash proves THIS enablement event.
        $this->assertSame($receipt['receipt_hash'], data_get($candidate->gate_json, 'enablement_receipt_hash'));
        $this->assertSame('vitor', data_get($candidate->gate_json, 'approved_by'));
        $this->assertNotNull(data_get($candidate->gate_json, 'approved_at'));
    }

    public function test_without_operator_confirmed_fails_closed_and_row_unchanged(): void
    {
        $candidate = $this->candidate();

        $receipt = $this->gate()->approve($candidate, []);

        $this->assertSame('rejected_enablement_not_confirmed', $receipt['status']);
        $this->assertFalse((bool) $receipt['enablement_allowed_now']);
        $this->assertFalse((bool) $receipt['operator_confirmed']);
        $this->assertSame('quarantined_for_atlas_capability_review', $receipt['gate_status_after']);
        $this->assertNotEmpty($receipt['receipt_hash']);

        $candidate->refresh();
        $this->assertFalse((bool) $candidate->enabled);
        $this->assertSame('quarantined_for_atlas_capability_review', $candidate->gate_status);
        $this->assertTrue((bool) $candidate->review_required);
        $this->assertNull($candidate->reviewed_at);
        $this->assertNull(data_get($candidate->gate_json, 'enablement_receipt_hash'));
    }

    public function test_high_risk_without_operator_authority_fails_closed_and_row_unchanged(): void
    {
        $candidate = $this->highRiskCandidate();

        $receipt = $this->gate()->approve($candidate, ['operator_confirmed' => true]);

        $this->assertSame('rejected_high_risk_requires_operator_authority', $receipt['status']);
        $this->assertFalse((bool) $receipt['enablement_allowed_now']);
        $this->assertTrue((bool) $receipt['operator_confirmed']);
        $this->assertFalse((bool) $receipt['operator_authority']);
        $this->assertSame('mcp_server:github', $receipt['capability_id']);
        $this->assertSame('quarantined_for_atlas_capability_review', $receipt['gate_status_after']);
        $this->assertNotEmpty($receipt['receipt_hash']);

        $candidate->refresh();
        $this->assertFalse((bool) $candidate->enabled);
        $this->assertSame('quarantined_for_atlas_capability_review', $candidate->gate_status);
        $this->assertTrue((bool) $candidate->review_required);
        $this->assertNull($candidate->reviewed_at);
        $this->assertNull(data_get($candidate->gate_json, 'enablement_receipt_hash'));
    }

    public function test_high_risk_with_confirmation_and_authority_is_approved(): void
    {
        $candidate = $this->highRiskCandidate();

        $receipt = $this->gate()->approve($candidate, [
            'operator_confirmed' => true,
            'operator_authority' => true,
            'approved_by' => 'vitor',
        ]);

        $this->assertSame('approved', $receipt['status']);
        $this->assertTrue((bool) $receipt['enablement_allowed_now']);
        $this->assertTrue((bool) $receipt['operator_authority']);
        $this->assertSame('approved_for_atlas_capability_use', $receipt['gate_status_after']);
        $this->assertNotEmpty($receipt['receipt_hash']);

        $candidate->refresh();
        $this->assertTrue((bool) $candidate->enabled);
        $this->assertSame('approved_for_atlas_capability_use', $candidate->gate_status);
        $this->assertFalse((bool) $candidate->review_required);
        $this->assertSame($receipt['receipt_hash'], data_get($candidate->gate_json, 'enablement_receipt_hash'));
    }

    public function test_high_risk_with_authority_but_no_probe_safety_receipt_fails_closed_and_row_unchanged(): void
    {
        $candidate = $this->highRiskCandidateWithoutProbeReceipt();

        $receipt = $this->gate()->approve($candidate, [
            'operator_confirmed' => true,
            'operator_authority' => true,
        ]);

        $this->assertSame('rejected_high_risk_requires_probe_safety_receipt', $receipt['status']);
        $this->assertFalse((bool) $receipt['enablement_allowed_now']);
        $this->assertSame('quarantined_for_atlas_capability_review', $receipt['gate_status_after']);
        $this->assertNotEmpty($receipt['receipt_hash']);

        $candidate->refresh();
        $this->assertFalse((bool) $candidate->enabled);
        $this->assertSame('quarantined_for_atlas_capability_review', $candidate->gate_status);
        $this->assertTrue((bool) $candidate->review_required);
        $this->assertNull($candidate->reviewed_at);
        $this->assertNull(data_get($candidate->gate_json, 'enablement_receipt_hash'));
    }

    public function test_high_risk_with_mismatched_probe_safety_receipt_fails_closed(): void
    {
        $candidate = $this->highRiskCandidateWithMismatchedProbeReceipt();

        $receipt = $this->gate()->approve($candidate, [
            'operator_confirmed' => true,
            'operator_authority' => true,
        ]);

        $this->assertSame('rejected_high_risk_requires_probe_safety_receipt', $receipt['status']);
        $this->assertFalse((bool) $receipt['enablement_allowed_now']);

        $candidate->refresh();
        $this->assertFalse((bool) $candidate->enabled);
    }

    public function test_already_enabled_candidate_is_rejected_not_a_candidate(): void
    {
        $candidate = $this->candidate([
            'enabled' => true,
            'gate_status' => 'approved_for_atlas_capability_use',
            'review_required' => false,
        ]);

        $receipt = $this->gate()->approve($candidate, [
            'operator_confirmed' => true,
            'operator_authority' => true,
        ]);

        $this->assertSame('rejected_not_a_candidate', $receipt['status']);
        $this->assertFalse((bool) $receipt['enablement_allowed_now']);
        $this->assertSame('approved_for_atlas_capability_use', $receipt['gate_status_before']);
        $this->assertSame('approved_for_atlas_capability_use', $receipt['gate_status_after']);
        $this->assertNotEmpty($receipt['receipt_hash']);

        $candidate->refresh();
        $this->assertTrue((bool) $candidate->enabled);
    }

    public function test_every_path_returns_a_sealed_receipt(): void
    {
        $reject = $this->gate()->approve($this->candidate(), []);
        $highRisk = $this->gate()->approve($this->highRiskCandidate(), ['operator_confirmed' => true]);
        $approved = $this->gate()->approve($this->candidate(), ['operator_confirmed' => true]);

        foreach ([$reject, $highRisk, $approved] as $receipt) {
            $this->assertArrayHasKey('receipt_hash', $receipt);
            $this->assertNotEmpty($receipt['receipt_hash']);

            $expected = $receipt;
            unset($expected['receipt_hash']);
            $this->assertSame(
                hash('sha256', json_encode($expected, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
                $receipt['receipt_hash'],
            );

            $this->assertSame('atlas.hermes.capability_enablement_receipt.v1', $receipt['schema_version']);
            $this->assertSame('hermes_capability_enablement_gate', $receipt['gate']);
            $this->assertSame('atlas', $receipt['capability_authority']);
        }
    }

    private function gate(): HermesCapabilityEnablementGate
    {
        return app(HermesCapabilityEnablementGate::class);
    }

    /**
     * Low-risk candidate (toolset:browser) as the registry would quarantine it.
     *
     * @param  array<string,mixed>  $overrides
     */
    private function candidate(array $overrides = []): HermesCapabilityCandidate
    {
        return $this->makeCandidate('toolset', 'browser', 'low', $overrides);
    }

    /**
     * High-risk always-quarantine candidate (mcp_server:github) with a
     * matching probe safety receipt already sealed onto gate_json, as the
     * registry/probe would leave it.
     *
     * @param  array<string,mixed>  $overrides
     */
    private function highRiskCandidate(array $overrides = []): HermesCapabilityCandidate
    {
        $candidate = $this->makeCandidate('mcp_server', 'github', 'high', $overrides);

        if (! array_key_exists('gate_json', $overrides)) {
            $candidate->update(['gate_json' => array_merge($candidate->gate_json, [
                'probe_safety_receipt' => [
                    'capability_id' => 'mcp_server:github',
                    'receipt_hash' => hash('sha256', 'probe:mcp_server:github'),
                ],
            ])]);
        }

        return $candidate;
    }

    /**
     * High-risk candidate with NO probe safety receipt on gate_json.
     */
    private function highRiskCandidateWithoutProbeReceipt(): HermesCapabilityCandidate
    {
        return $this->makeCandidate('mcp_server', 'github', 'high');
    }

    /**
     * High-risk candidate whose probe safety receipt belongs to a different
     * capability — must not be reusable across capabilities.
     */
    private function highRiskCandidateWithMismatchedProbeReceipt(): HermesCapabilityCandidate
    {
        $candidate = $this->makeCandidate('mcp_server', 'github', 'high');
        $candidate->update(['gate_json' => array_merge($candidate->gate_json, [
            'probe_safety_receipt' => [
                'capability_id' => 'mcp_server:slack',
                'receipt_hash' => hash('sha256', 'probe:mcp_server:slack'),
            ],
        ])]);

        return $candidate;
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function makeCandidate(string $class, string $key, string $risk, array $overrides = []): HermesCapabilityCandidate
    {
        return HermesCapabilityCandidate::query()->create(array_merge([
            'capability_hash' => hash('sha256', $class.'|'.$key.'|'.uniqid('', true)),
            'capability_class' => $class,
            'capability_key' => $key,
            'status' => 'persisted_for_review',
            'gate_status' => 'quarantined_for_atlas_capability_review',
            'risk_level' => $risk,
            'enabled' => false,
            'review_required' => true,
            'payload_json' => [
                'schema_version' => 'atlas.hermes.capability_candidate.v1',
                'capability_class' => $class,
                'capability_key' => $key,
                'enabled_now' => false,
            ],
            'evidence_refs_json' => [],
            'gate_json' => [
                'gate' => 'AtlasCapabilityGate',
                'capability_authority' => 'atlas',
                'enabled_now' => false,
                'review_required' => true,
                'risk_level' => $risk,
                'always_quarantine' => in_array($class, ['mcp_server', 'hook', 'delegation'], true),
            ],
            'reviewed_at' => null,
            'expires_at' => now()->addDays(90),
        ], $overrides));
    }
}
