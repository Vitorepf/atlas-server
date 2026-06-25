<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Trinity\AntiDecoupling\AtlasLoopTrinityContractEmitter;
use App\Services\Ai\AutonomousEvolution\Trinity\AntiDecoupling\TrinityContractMalformedException;
use Tests\TestCase;

/**
 * Proves the Trinity anti-decoupling contract emitter: emitting the contract twice on identical inputs yields
 * byte-identical fingerprints across all three primitives + registry; a Loop descriptor whose `consumes` lacks
 * a Cortex (or Maestro) reference is REJECTED with TrinityContractMalformedException; the emitter is bound as
 * a singleton in the container.
 */
final class AtlasLoopTrinityContractEmitterTest extends TestCase
{
    private function emitter(): AtlasLoopTrinityContractEmitter
    {
        return new AtlasLoopTrinityContractEmitter;
    }

    /** @return array<string,array{emits:list<array<string,string>>,consumes:list<array<string,string>>}> */
    private function valid(): array
    {
        return [
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
        ];
    }

    public function test_emitting_twice_yields_byte_identical_fingerprints_across_three_primitives(): void
    {
        $emitter = $this->emitter();
        $a = $emitter->emit($this->valid());
        $b = $emitter->emit($this->valid());

        foreach (AtlasLoopTrinityContractEmitter::ALL_PRIMITIVES as $primitive) {
            $this->assertSame($a['primitives'][$primitive]['fingerprint'], $b['primitives'][$primitive]['fingerprint'], "{$primitive} fingerprint must be deterministic");
            $this->assertSame(64, strlen($a['primitives'][$primitive]['fingerprint']), 'fingerprint is sha256 hex');
        }
        $this->assertSame($a['registry_fingerprint'], $b['registry_fingerprint']);
    }

    public function test_loop_consumes_lacking_cortex_reference_is_rejected(): void
    {
        $descriptors = $this->valid();
        $descriptors['loop']['consumes'] = [
            ['primitive' => 'maestro', 'symbol' => 'maestro_packet_resolved'], // zero cortex consumes
        ];

        $this->expectException(TrinityContractMalformedException::class);
        $this->expectExceptionMessageMatches('/loop.*cortex/i');
        $this->emitter()->emit($descriptors);
    }

    public function test_cortex_consumes_lacking_maestro_reference_is_rejected(): void
    {
        $descriptors = $this->valid();
        $descriptors['cortex']['consumes'] = [
            ['primitive' => 'loop', 'symbol' => 'origination_proposed'],
        ];

        $this->expectException(TrinityContractMalformedException::class);
        $this->expectExceptionMessageMatches('/cortex.*maestro/i');
        $this->emitter()->emit($descriptors);
    }

    public function test_missing_primitive_descriptor_is_rejected(): void
    {
        $descriptors = $this->valid();
        unset($descriptors['maestro']);

        $this->expectException(TrinityContractMalformedException::class);
        $this->emitter()->emit($descriptors);
    }

    public function test_emitter_is_bound_as_a_singleton_in_the_container(): void
    {
        $a = $this->app->make(AtlasLoopTrinityContractEmitter::class);
        $b = $this->app->make(AtlasLoopTrinityContractEmitter::class);
        $this->assertSame($a, $b, 'container binds the emitter as a singleton');
    }

    public function test_output_shape_carries_schema_version_and_per_primitive_fingerprints(): void
    {
        $out = $this->emitter()->emit($this->valid());
        $this->assertSame('atlas.trinity.anti_decoupling.contract.v1', $out['schema_version']);
        foreach (AtlasLoopTrinityContractEmitter::ALL_PRIMITIVES as $primitive) {
            $this->assertArrayHasKey('fingerprint', $out['primitives'][$primitive]);
            $this->assertArrayHasKey('emits', $out['primitives'][$primitive]);
            $this->assertArrayHasKey('consumes', $out['primitives'][$primitive]);
        }
        $this->assertSame(64, strlen($out['registry_fingerprint']));
    }
}
