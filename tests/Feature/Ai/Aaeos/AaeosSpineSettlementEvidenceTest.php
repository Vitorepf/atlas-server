<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aaeos;

use App\Services\Ai\Aaeos\Spine\AaeosEngineeringSpine;
use App\Services\Ai\Aaeos\Spine\AaeosSpineGate;
use Tests\TestCase;

/**
 * P2d / R66: N11 applicable only with settlement-emitted effect receipt via ledger readback.
 */
final class AaeosSpineSettlementEvidenceTest extends TestCase
{
    public function test_empty_settlement_refs_fail_when_n11_applicable(): void
    {
        $spine = new AaeosEngineeringSpine;
        $result = $spine->assertShared('dev', [
            'n11_applicable' => true,
            'settlement_effect_refs' => [],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertContains('n11_settlement_effect_refs_required', $result['violations']);
    }

    public function test_caller_declared_only_refs_fail_closed(): void
    {
        $spine = new AaeosEngineeringSpine;
        $result = $spine->assertSettlementEffectEvidence([
            'requires_settlement_effect' => true,
            'settlement_effect_refs' => [[
                'caller_declared_only' => true,
                'observer_identity' => 'fake',
                'changed_files_hash' => str_repeat('a', 64),
                'landed_sha' => str_repeat('b', 40),
                'ledger_event_id' => 'evt-1',
                'ledger_readback_verified' => true,
            ]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertContains('n11_empty_or_declared_only_ref_forbidden', $result['violations']);
    }

    public function test_missing_ledger_readback_fails(): void
    {
        $spine = new AaeosEngineeringSpine;
        $result = $spine->assertSettlementEffectEvidence([
            'n11_applicable' => true,
            'settlement_effect_refs' => [[
                'observer_identity' => 'observer-1',
                'changed_files_hash' => str_repeat('c', 64),
                'landed_sha' => str_repeat('d', 40),
                'ledger_event_id' => 'evt-settlement-1',
                'ledger_readback_verified' => false,
            ]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertContains('n11_settlement_ledger_readback_required', $result['violations']);
    }

    public function test_valid_settlement_effect_receipt_passes(): void
    {
        $spine = new AaeosEngineeringSpine;
        $ref = [
            'observer_identity' => 'kernel.observer.canary',
            'changed_files_hash' => str_repeat('e', 64),
            'landed_sha' => str_repeat('f', 40),
            'ledger_event_id' => 'evt-settlement-green',
            'ledger_readback_verified' => true,
            'payload' => ['status' => 'settled'],
        ];
        $result = $spine->assertShared('autonomos', [
            'n11_applicable' => true,
            'settlement_effect_refs' => [$ref],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['violations']);
        $this->assertTrue($result['contract']['settlement_evidence']['ok']);
        $this->assertSame(1, $result['contract']['settlement_evidence']['refs_checked']);
    }

    public function test_spine_gate_blocks_empty_n11_self_green(): void
    {
        $gate = new AaeosSpineGate;
        $eval = $gate->evaluate('forge', [
            'n11_applicable' => true,
            'effect_receipt_refs' => [[
                'empty' => true,
                'payload' => [],
            ]],
        ]);

        $this->assertFalse($eval['ok']);
        $this->assertSame('blocked', $eval['status']);
        $this->assertNotEmpty($eval['violations']);

        $stamped = $gate->stamp(['ok' => true], 'forge', [
            'n11_applicable' => true,
            'settlement_effect_refs' => [],
        ]);
        $this->assertTrue($stamped['spine_blocked'] ?? false);
        $this->assertSame('aaeos_spine_violation', $stamped['block_reason'] ?? null);
    }

    public function test_n11_not_applicable_skips_settlement_requirement(): void
    {
        $spine = new AaeosEngineeringSpine;
        $result = $spine->assertShared('dev', []);

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['contract']['settlement_evidence']['applicable']);
    }
}
