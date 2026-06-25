<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Trinity\AntiDecoupling\AtlasLoopTrinityContractAuditor;
use App\Services\Ai\AutonomousEvolution\Trinity\AntiDecoupling\AtlasLoopTrinityContractEmitter;
use App\Services\Ai\AutonomousEvolution\Trinity\AntiDecoupling\TrinityContractBreachException;
use PHPUnit\Framework\TestCase;

/**
 * Proves the Trinity contract auditor: a clean tree (provider returns the exact same fingerprints the
 * frozen contract computes) yields CLEAN; a Loop edit (provider returns a divergent emit-side fingerprint
 * for primitive=loop) is REJECTED with primitive=loop, side=emit, counterpart=cortex.
 */
final class AtlasLoopTrinityContractAuditorTest extends TestCase
{
    private function frozenContract(): array
    {
        return (new AtlasLoopTrinityContractEmitter)->emit([
            'loop' => [
                'emits' => [['primitive' => 'loop', 'symbol' => 'origination_proposed']],
                'consumes' => [
                    ['primitive' => 'cortex', 'symbol' => 'cortex_role_assigned'],
                    ['primitive' => 'maestro', 'symbol' => 'maestro_packet_resolved'],
                ],
            ],
            'cortex' => [
                'emits' => [['primitive' => 'cortex', 'symbol' => 'cortex_role_assigned']],
                'consumes' => [
                    ['primitive' => 'loop', 'symbol' => 'origination_proposed'],
                    ['primitive' => 'maestro', 'symbol' => 'maestro_packet_resolved'],
                ],
            ],
            'maestro' => [
                'emits' => [['primitive' => 'maestro', 'symbol' => 'maestro_packet_resolved']],
                'consumes' => [
                    ['primitive' => 'loop', 'symbol' => 'origination_proposed'],
                    ['primitive' => 'cortex', 'symbol' => 'cortex_role_assigned'],
                ],
            ],
        ]);
    }

    /**
     * The "honest" provider — returns the fingerprint computed exactly the same way the auditor recomputes
     * it from the frozen contract's entries. With this provider every side matches and the audit is CLEAN.
     */
    private function honestProvider(array $frozen): callable
    {
        return static function (string $primitive, string $side) use ($frozen): string {
            $descriptor = $frozen['primitives'][$primitive];
            $entries = $side === AtlasLoopTrinityContractAuditor::SIDE_EMIT
                ? (array) ($descriptor['emits'] ?? [])
                : (array) ($descriptor['consumes'] ?? []);
            // Use the same canonicalisation as the auditor (and emitter).
            $canonical = self::canonicalize(['primitive' => $primitive, 'side' => $side, 'entries' => $entries]);

            return hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        };
    }

    public function test_clean_when_actual_matches_frozen(): void
    {
        $frozen = $this->frozenContract();
        $auditor = new AtlasLoopTrinityContractAuditor($this->honestProvider($frozen));

        $this->assertSame('CLEAN', $auditor->audit($frozen));
    }

    public function test_loop_emit_drift_is_rejected_with_primitive_loop_side_emit_counterpart_cortex(): void
    {
        $frozen = $this->frozenContract();
        $base = $this->honestProvider($frozen);
        // Mutated provider — Loop's emit-side fingerprint is divergent (simulates a rename of the emit method).
        $provider = static function (string $primitive, string $side) use ($base): string {
            if ($primitive === 'loop' && $side === AtlasLoopTrinityContractAuditor::SIDE_EMIT) {
                return str_repeat('f', 64);
            }

            return $base($primitive, $side);
        };
        $auditor = new AtlasLoopTrinityContractAuditor($provider);

        try {
            $auditor->audit($frozen);
            $this->fail('expected TrinityContractBreachException for loop emit drift');
        } catch (TrinityContractBreachException $e) {
            $this->assertSame('loop', $e->primitive);
            $this->assertSame(AtlasLoopTrinityContractAuditor::SIDE_EMIT, $e->side);
            $this->assertSame('cortex', $e->counterpart, 'first counterpart in loop.consumes is cortex');
        }
    }

    public function test_maestro_consume_drift_is_rejected_with_side_consume(): void
    {
        $frozen = $this->frozenContract();
        $base = $this->honestProvider($frozen);
        $provider = static function (string $primitive, string $side) use ($base): string {
            if ($primitive === 'maestro' && $side === AtlasLoopTrinityContractAuditor::SIDE_CONSUME) {
                return str_repeat('a', 64);
            }

            return $base($primitive, $side);
        };

        try {
            (new AtlasLoopTrinityContractAuditor($provider))->audit($frozen);
            $this->fail('expected breach');
        } catch (TrinityContractBreachException $e) {
            $this->assertSame('maestro', $e->primitive);
            $this->assertSame(AtlasLoopTrinityContractAuditor::SIDE_CONSUME, $e->side);
            $this->assertSame('loop', $e->counterpart);
        }
    }

    public function test_missing_primitive_in_frozen_contract_is_rejected(): void
    {
        $frozen = $this->frozenContract();
        unset($frozen['primitives']['cortex']);

        $this->expectException(TrinityContractBreachException::class);
        (new AtlasLoopTrinityContractAuditor($this->honestProvider($frozen)))->audit($frozen);
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(static fn ($v) => self::canonicalize($v), $value);
        }
        ksort($value);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::canonicalize($v);
        }

        return $out;
    }
}
