<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Quaternity\Receipts;

use RuntimeException;

/**
 * QUATERNITY CYCLE RECEIPT COMPOSER — chains the four canonical FACTs of one Quaternity cycle (Loop, Cortex,
 * Maestro, Operator-Intent) into ONE signed envelope. The envelope is the tamper-evident proof that the cycle
 * actually saw all four sources of truth and bound them together: the chained hash links every part to every
 * other part, and the HMAC signature binds the chain to the secret so a missing or mutated part is detectable.
 *
 * FAIL-CLOSED: missing any of the four FACT slots throws (the cycle is not honestly composed); the secret being
 * unset throws (no silent unsigned envelopes ever ship). PURE: no IO, no provider call, no DB write — the
 * composer is a deterministic function over its inputs and the configured secret.
 */
final class AtlasLoopQuaternityCycleReceiptComposer
{
    /** @var list<string> the four FACT slots, in chain order */
    private const SLOTS = ['loop', 'cortex', 'maestro', 'operator_intent'];

    /**
     * Compose one signed envelope from the four FACTs of a cycle.
     *
     * @param  array<string,mixed>  $parts  associative, keyed by slot name: loop, cortex, maestro, operator_intent
     * @return array{
     *     cycle_id:string,
     *     composed_at:string,
     *     parts:array<string,array<string,mixed>>,
     *     part_hashes:array<string,string>,
     *     envelope_hash:string,
     *     signature:string
     * }
     */
    public function compose(string $cycleId, array $parts, ?string $composedAt = null): array
    {
        foreach (self::SLOTS as $slot) {
            if (! array_key_exists($slot, $parts) || ! is_array($parts[$slot]) || $parts[$slot] === []) {
                throw new RuntimeException('Quaternity envelope missing FACT slot: '.$slot);
            }
        }

        $secret = (string) (function_exists('config') ? config('atlas.quaternity.receipt_secret') : '');
        if ($secret === '') {
            throw new RuntimeException('Quaternity envelope cannot be signed: config(atlas.quaternity.receipt_secret) is unset');
        }

        $canonicalParts = [];
        $partHashes = [];
        $chainInput = '';
        foreach (self::SLOTS as $slot) {
            $canonical = self::canonicalize($parts[$slot]);
            $canonicalParts[$slot] = $canonical;
            $hash = hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $partHashes[$slot] = $hash;
            $chainInput .= $hash;
        }
        $chainInput .= $cycleId;

        $envelopeHash = hash('sha256', $chainInput);
        $signature = hash_hmac('sha256', $envelopeHash, $secret);

        return [
            'cycle_id' => $cycleId,
            'composed_at' => $composedAt ?? '',
            'parts' => $canonicalParts,
            'part_hashes' => $partHashes,
            'envelope_hash' => $envelopeHash,
            'signature' => $signature,
        ];
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $isList = array_is_list($value);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::canonicalize($v);
        }
        if (! $isList) {
            ksort($out);
        }

        return $out;
    }
}
