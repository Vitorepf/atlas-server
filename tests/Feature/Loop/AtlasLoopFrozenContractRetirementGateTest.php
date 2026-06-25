<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\FrozenContracts\AtlasLoopFrozenContractRetirementGate;
use Tests\TestCase;

/**
 * Proves the frozen-contract retirement gate: default-OFF is a byte-identical pass-through; armed without a
 * matching receipt denies the retirement with reason=missing_operator_receipt and writes nothing; armed with
 * a valid receipt in the store allows it; armed with an unreadable store fail-closes.
 */
final class AtlasLoopFrozenContractRetirementGateTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/atlas_retirement_gate_'.bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0775, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tmpDir);
        }
        parent::tearDown();
    }

    private function gate(bool $armed): AtlasLoopFrozenContractRetirementGate
    {
        $gate = new AtlasLoopFrozenContractRetirementGate($this->tmpDir, static fn (): bool => $armed);

        return $gate;
    }

    private function snapshotDir(): array
    {
        $files = glob($this->tmpDir.'/*') ?: [];

        return array_map(static fn (string $f): array => ['path' => $f, 'sha' => is_file($f) ? hash_file('sha256', $f) : null], $files);
    }

    public function test_disabled_gate_is_pass_through_with_byte_identical_state(): void
    {
        $before = $this->snapshotDir();
        $verdict = $this->gate(armed: false)->decide('App\\Demo\\FrozenSample');
        $after = $this->snapshotDir();

        $this->assertTrue($verdict['allowed']);
        $this->assertSame(AtlasLoopFrozenContractRetirementGate::REASON_GATE_DISABLED, $verdict['reason']);
        $this->assertSame($before, $after, 'disabled gate produces byte-identical state — no disk writes');
    }

    public function test_armed_without_receipt_denies_with_missing_operator_receipt(): void
    {
        // No receipts file written ⇒ store unreadable. But we test the no-token branch first by writing an
        // empty store so the unreadable path isn't conflated.
        file_put_contents($this->tmpDir.'/retirement-receipts.json', '[]');
        $before = $this->snapshotDir();

        $verdict = $this->gate(armed: true)->decide('App\\Demo\\FrozenSample');

        $this->assertFalse($verdict['allowed']);
        $this->assertSame(AtlasLoopFrozenContractRetirementGate::REASON_MISSING_OPERATOR_RECEIPT, $verdict['reason']);
        $this->assertSame($before, $this->snapshotDir(), 'denied verdict writes nothing');
    }

    public function test_armed_with_valid_receipt_in_store_allows(): void
    {
        $token = 'op-token-001';
        $tokenHash = hash('sha256', $token);
        file_put_contents($this->tmpDir.'/retirement-receipts.json', (string) json_encode([
            ['class' => 'App\\Demo\\FrozenSample', 'token_hash' => $tokenHash, 'reason' => 'planned retirement', 'recorded_at' => 1700000000],
        ]));

        $verdict = $this->gate(armed: true)->decide('App\\Demo\\FrozenSample', $token);

        $this->assertTrue($verdict['allowed']);
        $this->assertSame(AtlasLoopFrozenContractRetirementGate::REASON_ALLOWED, $verdict['reason']);
        $this->assertSame($tokenHash, $verdict['token_hash']);
    }

    public function test_armed_with_unreadable_store_fails_closed(): void
    {
        // Receipt file does NOT exist ⇒ loadReceiptStore returns null ⇒ fail-closed.
        $verdict = $this->gate(armed: true)->decide('App\\Demo\\FrozenSample', 'some-token');

        $this->assertFalse($verdict['allowed']);
        $this->assertSame(AtlasLoopFrozenContractRetirementGate::REASON_RECEIPT_STORE_UNREADABLE, $verdict['reason']);
    }

    public function test_armed_with_receipt_for_different_class_denies(): void
    {
        $token = 'op-token-x';
        $tokenHash = hash('sha256', $token);
        file_put_contents($this->tmpDir.'/retirement-receipts.json', (string) json_encode([
            ['class' => 'App\\Demo\\OtherClass', 'token_hash' => $tokenHash, 'reason' => 'wrong class', 'recorded_at' => 1700000000],
        ]));

        $verdict = $this->gate(armed: true)->decide('App\\Demo\\FrozenSample', $token);

        $this->assertFalse($verdict['allowed']);
        $this->assertSame(AtlasLoopFrozenContractRetirementGate::REASON_MISSING_OPERATOR_RECEIPT, $verdict['reason']);
    }
}
