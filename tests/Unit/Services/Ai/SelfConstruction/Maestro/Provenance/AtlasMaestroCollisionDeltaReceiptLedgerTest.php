<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Maestro\Provenance;

use App\Services\Ai\SelfConstruction\Maestro\Provenance\AtlasMaestroCollisionDeltaReceiptLedger;
use Tests\TestCase;

final class AtlasMaestroCollisionDeltaReceiptLedgerTest extends TestCase
{
    private function ledger(): AtlasMaestroCollisionDeltaReceiptLedger
    {
        return new AtlasMaestroCollisionDeltaReceiptLedger;
    }

    // ── AC: receipts store baseline count, post count, emitted targets and new collisions only ──

    public function test_receipt_stores_baseline_and_post_counts(): void
    {
        $result = $this->ledger()->record([
            'round_id' => 'r1',
            'originator_id' => 'orig-1',
            'baseline_collisions' => ['a', 'b'],
            'post_collisions' => ['a', 'b', 'c'],
            'emitted_targets' => ['impl', 'test'],
        ]);

        $this->assertSame(2, $result['baseline_count']);
        $this->assertSame(3, $result['post_count']);
        $this->assertSame(1, $result['delta']);
    }

    public function test_receipt_stores_emitted_targets(): void
    {
        $result = $this->ledger()->record([
            'round_id' => 'r2',
            'emitted_targets' => ['implementation', 'testing'],
        ]);

        $this->assertContains('implementation', $result['emitted_targets']);
        $this->assertContains('testing', $result['emitted_targets']);
    }

    public function test_receipt_stores_only_new_collisions(): void
    {
        $result = $this->ledger()->record([
            'round_id' => 'r3',
            'baseline_collisions' => ['existing'],
            'post_collisions' => ['existing', 'new1', 'new2'],
        ]);

        $this->assertSame(['new1', 'new2'], $result['new_collisions']);
        $this->assertSame(2, $result['new_collision_count']);
        $this->assertTrue($result['introduced_new_collisions']);
    }

    public function test_no_new_collisions_when_baseline_equals_post(): void
    {
        $result = $this->ledger()->record([
            'round_id' => 'r4',
            'baseline_collisions' => ['a', 'b'],
            'post_collisions' => ['a', 'b'],
        ]);

        $this->assertSame([], $result['new_collisions']);
        $this->assertFalse($result['introduced_new_collisions']);
    }

    // ── provider-safe ──

    public function test_receipt_is_provider_safe(): void
    {
        $result = $this->ledger()->record([
            'round_id' => 'r5',
            'emitted_targets' => ['a'],
        ]);

        $this->assertTrue($result['provider_safe']);
        $json = (string) json_encode($result);
        $this->assertStringNotContainsString('raw_prompt', $json);
        $this->assertStringNotContainsString('provider_trace', $json);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->ledger()->record([]);

        $this->assertSame(AtlasMaestroCollisionDeltaReceiptLedger::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('baseline_count', $result);
        $this->assertArrayHasKey('post_count', $result);
        $this->assertArrayHasKey('new_collisions', $result);
        $this->assertArrayHasKey('emitted_targets', $result);
        $this->assertArrayHasKey('receipt_hash', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $input = [
            'round_id' => 'r-det',
            'baseline_collisions' => ['a'],
            'post_collisions' => ['a', 'b'],
            'emitted_targets' => ['x'],
        ];

        $a = $this->ledger()->record($input);
        $b = $this->ledger()->record($input);

        $this->assertSame($a['receipt_hash'], $b['receipt_hash']);
    }
}
