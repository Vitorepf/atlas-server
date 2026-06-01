<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasMissionControlCockpitSurfaceService;
use Tests\TestCase;

/**
 * Pins the documented Mission Control gesture + Operator Decision Receipt rules.
 *
 * @see docs/engineering-knowledge-base/atlas-mission-control-cockpit-spec.md
 */
final class AtlasMissionControlCockpitSurfaceTest extends TestCase
{
    private AtlasMissionControlCockpitSurfaceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasMissionControlCockpitSurfaceService;
    }

    /**
     * Build a complete, schema-valid Operator Decision Receipt for a gesture.
     *
     * @param  array<string,mixed>  $overrides
     *
     * @return array<string,mixed>
     */
    private function receiptFor(string $gesture, array $overrides = []): array
    {
        $targetKind = [
            'approve_milestone' => 'obra',
            'veto_dept' => 'department',
            'pause_obra' => 'obra',
            'promote_ladder' => 'ladder',
            'force_replay' => 'obra',
            'force_handoff' => 'intent',
            'sign_intent_receipt' => 'intent',
        ][$gesture] ?? 'obra';

        return array_merge([
            'schema' => 'atlas.operator.decision_receipt.v1',
            'receipt_id' => 'rcpt-001',
            'operator_id' => 'vitor',
            'session_id' => 'sess-2026-06-01',
            'gesture' => $gesture,
            'target' => ['kind' => $targetKind, 'id' => 'tgt-1'],
            'context_hash' => 'sha256:view-snapshot-001',
            'evidence_hashes_seen' => ['sha256:abc', 'sha256:def'],
            'rationale' => 'documented rationale for the gesture',
            'operator_signature' => 'ed25519:sig-primary',
            'signed_at' => '2026-06-01T18:42:00Z',
        ], $overrides);
    }

    /**
     * The catalogue is the closed canonical set of exactly 7 gestures, every one
     * receipt-required (table "Gestures canonicas").
     */
    public function test_catalogue_lists_seven_gestures_all_receipt_required(): void
    {
        $cat = $this->service->gestureCatalogue();

        $this->assertSame('atlas.mission_control.view.v1', $cat['view_schema']);
        $this->assertSame('atlas.operator.decision_receipt.v1', $cat['receipt_schema']);
        $this->assertSame(7, $cat['gesture_count']);
        foreach ($cat['gestures'] as $row) {
            $this->assertTrue($row['receipt_required'], "{$row['gesture']} must require a receipt");
        }
        $ids = array_column($cat['gestures'], 'gesture');
        $this->assertContains('approve_milestone', $ids);
        $this->assertContains('promote_ladder', $ids);
        $this->assertContains('sign_intent_receipt', $ids);
    }

    /** Rule — "agente nunca emite gesture": an agent actor is blocked outright. */
    public function test_agent_actor_cannot_emit_gesture(): void
    {
        $d = $this->service->authorizeGesture(
            'pause_obra',
            $this->receiptFor('pause_obra'),
            ['actor_kind' => 'agent', 'obra_active' => true],
        );

        $this->assertSame('block', $d['verdict']);
        $this->assertFalse($d['authorized']);
        $this->assertSame('agent_cannot_emit_gesture', $d['reason']);
    }

    /** Rule — "Toda gesture exige Operator Decision Receipt assinado": no receipt blocks. */
    public function test_missing_receipt_blocks_every_gesture(): void
    {
        $d = $this->service->authorizeGesture('pause_obra', null, ['obra_active' => true]);

        $this->assertSame('block', $d['verdict']);
        $this->assertSame('receipt_required', $d['reason']);
    }

    /**
     * Failure mode — "Operador aprova milestone sem ver evidence_hashes": an
     * approve_milestone whose receipt has no evidence_hashes_seen blocks.
     */
    public function test_approve_milestone_without_evidence_hashes_blocks(): void
    {
        $receipt = $this->receiptFor('approve_milestone', ['evidence_hashes_seen' => []]);

        // evidence_hashes_seen is also a required field, so completeness catches the
        // empty list first — assert the gate blocks and names the evidence field.
        $d = $this->service->authorizeGesture('approve_milestone', $receipt, ['milestone_ready' => true]);

        $this->assertSame('block', $d['verdict']);
        $this->assertContains($d['reason'], ['receipt_incomplete', 'evidence_hashes_required']);
    }

    /** A fully-formed approve_milestone with milestone ready and evidence shown authorizes. */
    public function test_approve_milestone_with_evidence_and_ready_authorizes(): void
    {
        $d = $this->service->authorizeGesture(
            'approve_milestone',
            $this->receiptFor('approve_milestone'),
            ['milestone_ready' => true],
        );

        $this->assertSame('authorize', $d['verdict']);
        $this->assertTrue($d['authorized']);
        $this->assertSame('gesture_authorized', $d['reason']);
        $this->assertSame(2, $d['detail']['evidence_count']);
    }

    /** Pre-req — approve_milestone with milestone NOT ready blocks. */
    public function test_approve_milestone_not_ready_blocks(): void
    {
        $d = $this->service->authorizeGesture(
            'approve_milestone',
            $this->receiptFor('approve_milestone'),
            ['milestone_ready' => false],
        );

        $this->assertSame('block', $d['verdict']);
        $this->assertSame('milestone_not_ready', $d['reason']);
    }

    /**
     * Rule — "promote_ladder ... dual signature se L4+": promoting INTO L4 with a
     * single operator signature blocks; two distinct signatures authorize.
     */
    public function test_promote_ladder_into_l4_requires_dual_signature(): void
    {
        $ctx = ['autonomy_next_eligible' => 'L4', 'promote_to_level' => 'L4'];

        $single = $this->service->authorizeGesture('promote_ladder', $this->receiptFor('promote_ladder'), $ctx);
        $this->assertSame('block', $single['verdict']);
        $this->assertSame('dual_signature_required', $single['reason']);
        $this->assertSame(2, $single['detail']['required_signatures']);

        $dual = $this->service->authorizeGesture(
            'promote_ladder',
            $this->receiptFor('promote_ladder', ['co_signatures' => ['ed25519:sig-architect']]),
            $ctx,
        );
        $this->assertSame('authorize', $dual['verdict']);
    }

    /** Below L4 a single signature is enough (no dual-signature gate). */
    public function test_promote_ladder_below_l4_single_signature_authorizes(): void
    {
        $d = $this->service->authorizeGesture(
            'promote_ladder',
            $this->receiptFor('promote_ladder'),
            ['autonomy_next_eligible' => 'L3', 'promote_to_level' => 'L3'],
        );

        $this->assertSame('authorize', $d['verdict']);
    }

    /** Pre-req — promote_ladder when autonomy.next_eligible is not green blocks. */
    public function test_promote_ladder_without_eligible_next_blocks(): void
    {
        $d = $this->service->authorizeGesture(
            'promote_ladder',
            $this->receiptFor('promote_ladder'),
            ['autonomy_next_eligible' => null, 'promote_to_level' => 'L2'],
        );

        $this->assertSame('block', $d['verdict']);
        $this->assertSame('autonomy_next_not_eligible', $d['reason']);
    }

    /**
     * Surface adapters table — Mobile may NOT carry promote_ladder/force_replay/
     * force_handoff; the gate blocks them on the mobile surface.
     */
    public function test_mobile_surface_cannot_promote_ladder(): void
    {
        $d = $this->service->authorizeGesture(
            'promote_ladder',
            $this->receiptFor('promote_ladder'),
            ['surface' => 'mobile', 'autonomy_next_eligible' => 'L2', 'promote_to_level' => 'L2'],
        );

        $this->assertSame('block', $d['verdict']);
        $this->assertSame('gesture_not_available_on_surface', $d['reason']);
        $this->assertContains('approve_milestone', $d['detail']['surface_allows']);
    }

    /**
     * Rule — "Operator Decision Receipt assinado nunca e revogado; correcao gera
     * novo receipt": a gesture attempting to revoke an existing receipt blocks.
     */
    public function test_revoking_a_signed_receipt_is_forbidden(): void
    {
        $d = $this->service->authorizeGesture(
            'pause_obra',
            $this->receiptFor('pause_obra'),
            ['obra_active' => true, 'revokes_receipt_id' => 'rcpt-000'],
        );

        $this->assertSame('block', $d['verdict']);
        $this->assertSame('receipt_revocation_forbidden', $d['reason']);
    }

    /** An unknown gesture is not in the closed canonical set and blocks. */
    public function test_unknown_gesture_blocks(): void
    {
        $d = $this->service->authorizeGesture('delete_everything', $this->receiptFor('pause_obra'));

        $this->assertSame('block', $d['verdict']);
        $this->assertSame('unknown_gesture', $d['reason']);
    }

    /** A receipt whose gesture/target kind disagrees with the gesture is rejected. */
    public function test_receipt_target_kind_must_match_gesture(): void
    {
        // veto_dept must target a 'department'; here the receipt targets an 'obra'.
        $receipt = $this->receiptFor('veto_dept', ['target' => ['kind' => 'obra', 'id' => 'x']]);

        $d = $this->service->authorizeGesture('veto_dept', $receipt, []);

        $this->assertSame('block', $d['verdict']);
        $this->assertSame('wrong_target_kind', $d['reason']);
    }

    /** validateReceipt pins schema-only validation independent of any gesture pre-req. */
    public function test_validate_receipt_flags_wrong_schema(): void
    {
        $receipt = $this->receiptFor('pause_obra', ['schema' => 'atlas.decision_receipt.v2']);

        $result = $this->service->validateReceipt($receipt);

        $this->assertFalse($result['valid']);
        $this->assertSame('wrong_receipt_schema', $result['reason']);
    }
}
