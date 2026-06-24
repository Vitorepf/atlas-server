<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Quaternity\Receipts\AtlasLoopQuaternityCycleReceiptComposer;
use RuntimeException;
use Tests\TestCase;

/**
 * Proves the Quaternity cycle receipt composer: determinism over byte-equal inputs, fail-closed on missing FACT
 * slots, chain sensitivity to a single mutated byte in any part, and fail-closed on unset signing secret.
 */
final class AtlasLoopQuaternityCycleReceiptComposerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['atlas.quaternity.receipt_secret' => 'k_secret_test']);
    }

    /** @return array<string,array<string,mixed>> */
    private function parts(): array
    {
        return [
            'loop' => ['factId' => 'L1', 'payload' => ['z' => 9, 'a' => 1]],
            'cortex' => ['factId' => 'C1', 'payload' => ['parent' => 'L1']],
            'maestro' => ['factId' => 'M1', 'payload' => ['parent' => 'C1']],
            'operator_intent' => ['intent' => 'evolve trinity', 'tokens' => ['evolve', 'trinity']],
        ];
    }

    public function test_byte_equal_inputs_produce_byte_equal_envelope_hash_and_signature(): void
    {
        $composer = new AtlasLoopQuaternityCycleReceiptComposer;
        $a = $composer->compose('cycle-1', $this->parts());
        $b = $composer->compose('cycle-1', $this->parts());

        $this->assertSame($a['envelope_hash'], $b['envelope_hash']);
        $this->assertSame($a['signature'], $b['signature']);
    }

    public function test_omitting_a_slot_throws_naming_the_missing_slot(): void
    {
        $composer = new AtlasLoopQuaternityCycleReceiptComposer;

        foreach (['loop', 'cortex', 'maestro', 'operator_intent'] as $slot) {
            $parts = $this->parts();
            unset($parts[$slot]);
            try {
                $composer->compose('cycle-1', $parts);
                $this->fail('expected RuntimeException for missing slot: '.$slot);
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($slot, $e->getMessage(), 'exception names the missing slot');
            }
        }
    }

    public function test_mutating_any_single_part_byte_changes_envelope_hash_and_signature(): void
    {
        $composer = new AtlasLoopQuaternityCycleReceiptComposer;
        $base = $composer->compose('cycle-1', $this->parts());

        foreach (['loop', 'cortex', 'maestro', 'operator_intent'] as $slot) {
            $mutated = $this->parts();
            $mutated[$slot]['_marker'] = 'X';
            $envelope = $composer->compose('cycle-1', $mutated);
            $this->assertNotSame($base['envelope_hash'], $envelope['envelope_hash'], "envelope_hash must shift when $slot mutates");
            $this->assertNotSame($base['signature'], $envelope['signature'], "signature must shift when $slot mutates");
        }
    }

    public function test_unset_signing_secret_throws_never_emits_unsigned_envelope(): void
    {
        config(['atlas.quaternity.receipt_secret' => '']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('atlas.quaternity.receipt_secret');

        (new AtlasLoopQuaternityCycleReceiptComposer)->compose('cycle-1', $this->parts());
    }
}
