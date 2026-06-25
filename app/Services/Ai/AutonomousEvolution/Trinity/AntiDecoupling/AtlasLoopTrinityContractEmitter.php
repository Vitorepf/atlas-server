<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Trinity\AntiDecoupling;

use RuntimeException;

/**
 * Thrown when a primitive's emitted contract fails the anti-decoupling invariant — its `consumes` list does
 * not reference at least one symbol from BOTH of the other two primitives. Co-located with the emitter.
 */
final class TrinityContractMalformedException extends RuntimeException
{
}

/**
 * TRINITY ANTI-DECOUPLING CONTRACT EMITTER. The single canonical source of the Trinity coupling contract:
 * for each of the three primitives (Loop / Cortex / Maestro) it emits a frozen, deterministic SHA-256
 * contract descriptor declaring (a) the exact emit shape it PRODUCES and (b) the exact consume shape it
 * REQUIRES from the other two.
 *
 * RECURSIVE COUPLING INVARIANT: Loop's `consumes` MUST reference at least one Cortex emit AND at least one
 * Maestro emit (and likewise for Cortex / Maestro). A primitive that does not reference the other two via
 * this emitter is REJECTED at emit-time with {@see TrinityContractMalformedException} — so touching any one
 * primitive forces re-emission across all three.
 *
 * DETERMINISTIC: given the same input descriptors, the SHA-256 fingerprints are byte-identical across runs.
 * The descriptor canonical form is recursive ksort + JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE.
 */
final class AtlasLoopTrinityContractEmitter
{
    public const PRIMITIVE_LOOP = 'loop';

    public const PRIMITIVE_CORTEX = 'cortex';

    public const PRIMITIVE_MAESTRO = 'maestro';

    public const ALL_PRIMITIVES = [self::PRIMITIVE_LOOP, self::PRIMITIVE_CORTEX, self::PRIMITIVE_MAESTRO];

    /**
     * Emit the full Trinity contract — three primitive descriptors, each with its sha256 fingerprint,
     * verified against the recursive-coupling invariant. Returns the deterministic registry payload.
     *
     * @param  array<string,array{emits:list<array<string,string>>,consumes:list<array<string,string>>}>  $descriptors
     *         keyed by primitive id (loop|cortex|maestro). Each emit/consume entry is {primitive, symbol}.
     * @return array{
     *     schema_version:string,
     *     primitives:array<string,array{emits:list<array<string,string>>, consumes:list<array<string,string>>, fingerprint:string}>,
     *     registry_fingerprint:string
     * }
     */
    public function emit(array $descriptors): array
    {
        $this->assertAllPrimitivesPresent($descriptors);
        foreach ($descriptors as $primitive => $descriptor) {
            $this->assertRecursiveCoupling((string) $primitive, $descriptor);
        }

        $out = ['schema_version' => 'atlas.trinity.anti_decoupling.contract.v1', 'primitives' => [], 'registry_fingerprint' => ''];
        foreach (self::ALL_PRIMITIVES as $primitive) {
            $descriptor = $descriptors[$primitive];
            $canonical = $this->canonicalize([
                'primitive' => $primitive,
                'emits' => $descriptor['emits'],
                'consumes' => $descriptor['consumes'],
            ]);
            $out['primitives'][$primitive] = [
                'emits' => $descriptor['emits'],
                'consumes' => $descriptor['consumes'],
                'fingerprint' => hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            ];
        }

        // Registry fingerprint = sha256 over the three per-primitive fingerprints in canonical order.
        $perPrim = [];
        foreach (self::ALL_PRIMITIVES as $p) {
            $perPrim[$p] = $out['primitives'][$p]['fingerprint'];
        }
        $out['registry_fingerprint'] = hash('sha256', (string) json_encode($perPrim, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $out;
    }

    /**
     * @param  array<string,mixed>  $descriptors
     */
    private function assertAllPrimitivesPresent(array $descriptors): void
    {
        foreach (self::ALL_PRIMITIVES as $primitive) {
            if (! array_key_exists($primitive, $descriptors)) {
                throw new TrinityContractMalformedException('Trinity contract missing descriptor for primitive: '.$primitive);
            }
            $d = $descriptors[$primitive];
            if (! is_array($d) || ! isset($d['emits']) || ! is_array($d['emits']) || ! isset($d['consumes']) || ! is_array($d['consumes'])) {
                throw new TrinityContractMalformedException('Trinity contract descriptor for '.$primitive.' must include emits + consumes arrays');
            }
        }
    }

    /**
     * Anti-decoupling invariant: a primitive's `consumes` must reference ≥1 symbol from EACH of the other
     * two primitives.
     *
     * @param  array{emits:list<array<string,string>>,consumes:list<array<string,string>>}  $descriptor
     */
    private function assertRecursiveCoupling(string $primitive, array $descriptor): void
    {
        $others = array_values(array_filter(self::ALL_PRIMITIVES, static fn (string $p): bool => $p !== $primitive));
        $seen = [];
        foreach ($descriptor['consumes'] as $consume) {
            $from = (string) ($consume['primitive'] ?? '');
            if ($from !== '' && in_array($from, $others, true)) {
                $seen[$from] = true;
            }
        }
        foreach ($others as $other) {
            if (! isset($seen[$other])) {
                throw new TrinityContractMalformedException(sprintf(
                    'Trinity recursive-coupling violated: primitive "%s" consumes list does not reference any "%s" symbol — touching one primitive must force re-emission across all three',
                    $primitive,
                    $other,
                ));
            }
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn ($v) => $this->canonicalize($v), $value);
        }
        ksort($value);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = $this->canonicalize($v);
        }

        return $out;
    }
}
