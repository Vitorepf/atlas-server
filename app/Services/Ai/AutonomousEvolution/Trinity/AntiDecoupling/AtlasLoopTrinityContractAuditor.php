<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Trinity\AntiDecoupling;

use RuntimeException;

/**
 * Thrown by {@see AtlasLoopTrinityContractAuditor::audit()} when a primitive's actual source-derived
 * fingerprint diverges from the frozen contract emitted by {@see AtlasLoopTrinityContractEmitter}. Carries
 * primitive + side (emit | consume) + counterpart so the operator sees WHICH side drifted and WHICH
 * counterpart can no longer be satisfied. Co-located with the auditor.
 */
final class TrinityContractBreachException extends RuntimeException
{
    public function __construct(
        public readonly string $primitive,
        public readonly string $side,
        public readonly ?string $counterpart,
        ?string $message = null,
    ) {
        parent::__construct($message ?? sprintf(
            'Trinity contract breach: primitive=%s side=%s counterpart=%s — actual source fingerprint diverges from the frozen contract',
            $primitive,
            $side,
            $counterpart ?? '(none)',
        ));
    }
}

/**
 * BUILD / PRE-COMMIT GATE that REFUSES any primitive edit which breaks its emit/consume promise registered
 * by {@see AtlasLoopTrinityContractEmitter}. The auditor reads the current source fingerprint of each
 * primitive via an injected provider (production: reflection + AST), recomputes the canonical fingerprint
 * the same way the emitter did, and compares against the frozen contract. Divergence ⇒ fail-closed throw.
 *
 * The provider is duck-typed for testability: tests inject a closure returning the mutated source's
 * fingerprint; production wires a closure that walks the file with PhpParser + the emitter's canonicalizer.
 */
final class AtlasLoopTrinityContractAuditor
{
    public const SIDE_EMIT = 'emit';

    public const SIDE_CONSUME = 'consume';

    /** @var callable(string $primitive, string $side):string returns the sha256 of the CURRENT source-side fingerprint */
    private $currentFingerprintProvider;

    /**
     * @param  callable(string,string):string  $currentFingerprintProvider  primitive_id, side ⇒ sha256
     */
    public function __construct(callable $currentFingerprintProvider)
    {
        $this->currentFingerprintProvider = $currentFingerprintProvider;
    }

    /**
     * Audit the frozen contract against current source. Returns 'CLEAN' or throws on the first breach.
     *
     * @param  array{schema_version:string, primitives:array<string,array{emits:list<array<string,string>>,consumes:list<array<string,string>>,fingerprint:string}>, registry_fingerprint:string}  $frozenContract
     */
    public function audit(array $frozenContract): string
    {
        $primitives = is_array($frozenContract['primitives'] ?? null) ? $frozenContract['primitives'] : [];
        foreach (AtlasLoopTrinityContractEmitter::ALL_PRIMITIVES as $primitive) {
            if (! isset($primitives[$primitive])) {
                throw new TrinityContractBreachException($primitive, self::SIDE_EMIT, null, "Frozen contract missing primitive descriptor for {$primitive}");
            }
            $descriptor = $primitives[$primitive];
            $frozenEmit = $this->sideFingerprint($primitive, self::SIDE_EMIT, (array) ($descriptor['emits'] ?? []));
            $actualEmit = ($this->currentFingerprintProvider)($primitive, self::SIDE_EMIT);
            if (! hash_equals($frozenEmit, $actualEmit)) {
                throw new TrinityContractBreachException(
                    $primitive,
                    self::SIDE_EMIT,
                    $this->firstCounterpart($primitive, (array) ($descriptor['consumes'] ?? [])),
                );
            }

            $frozenConsume = $this->sideFingerprint($primitive, self::SIDE_CONSUME, (array) ($descriptor['consumes'] ?? []));
            $actualConsume = ($this->currentFingerprintProvider)($primitive, self::SIDE_CONSUME);
            if (! hash_equals($frozenConsume, $actualConsume)) {
                throw new TrinityContractBreachException(
                    $primitive,
                    self::SIDE_CONSUME,
                    $this->firstCounterpart($primitive, (array) ($descriptor['consumes'] ?? [])),
                );
            }
        }

        return 'CLEAN';
    }

    /**
     * Recompute the canonical fingerprint of one side of one primitive. SAME canonicalisation the emitter
     * uses (recursive ksort + JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) so the comparison is byte-true.
     *
     * @param  list<array<string,string>>  $entries
     */
    private function sideFingerprint(string $primitive, string $side, array $entries): string
    {
        return hash('sha256', (string) json_encode($this->canonicalize([
            'primitive' => $primitive,
            'side' => $side,
            'entries' => $entries,
        ]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * The first OTHER-primitive symbol the consumes list points at — used in the breach message to name
     * which counterpart can no longer be satisfied.
     *
     * @param  list<array<string,string>>  $consumes
     */
    private function firstCounterpart(string $primitive, array $consumes): ?string
    {
        foreach ($consumes as $c) {
            $other = (string) ($c['primitive'] ?? '');
            if ($other !== '' && $other !== $primitive) {
                return $other;
            }
        }

        return null;
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
