<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Spec;

/**
 * Engineering Kernel value: the sovereign spec decision — fail-closed.
 *
 * Owns: carrying the terminal spec verdict (freeze|revise|refuse|hold), the gaps that produced it,
 * the per-invariant breakdown for audit, and the provenance sealed into the frozen_hash.
 * Must never own: freezing (the caller acts on 'freeze') or re-deciding.
 *
 * HOLD is a first-class terminal state, distinct from REFUSE: it means "cannot auto-freeze without
 * an independent witness that is not currently available" — never a silent pass, never a permanent block.
 */
final readonly class SpecVerdict
{
    public const FREEZE = 'freeze';

    public const REVISE = 'revise';

    public const REFUSE = 'refuse';

    public const HOLD = 'hold';

    /**
     * @param  string  $status                        one of FREEZE|REVISE|REFUSE|HOLD
     * @param  list<string>  $gaps                     invariant ids / findings that blocked freeze (empty iff FREEZE)
     * @param  array<string,array{status:string,detail:string}>  $invariants  per-floor audit trail
     */
    public function __construct(
        public string $status,
        public array $gaps,
        public array $invariants,
        public SpecProvenance $provenance,
    ) {}

    /**
     * @param  array<string,array{status:string,detail:string}>  $invariants
     */
    public static function freeze(array $invariants, SpecProvenance $provenance): self
    {
        return new self(self::FREEZE, [], $invariants, $provenance);
    }

    /**
     * @param  list<string>  $gaps
     * @param  array<string,array{status:string,detail:string}>  $invariants
     */
    public static function refuse(array $gaps, array $invariants, SpecProvenance $provenance): self
    {
        return new self(self::REFUSE, array_values(array_unique($gaps)), $invariants, $provenance);
    }

    /**
     * @param  list<string>  $gaps
     * @param  array<string,array{status:string,detail:string}>  $invariants
     */
    public static function hold(array $gaps, array $invariants, SpecProvenance $provenance): self
    {
        return new self(self::HOLD, array_values(array_unique($gaps)), $invariants, $provenance);
    }

    /**
     * @param  list<string>  $gaps
     * @param  array<string,array{status:string,detail:string}>  $invariants
     */
    public static function revise(array $gaps, array $invariants, SpecProvenance $provenance): self
    {
        return new self(self::REVISE, array_values(array_unique($gaps)), $invariants, $provenance);
    }

    public function frozen(): bool
    {
        return $this->status === self::FREEZE;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'gaps' => $this->gaps,
            'invariants' => $this->invariants,
            'provenance' => $this->provenance->toArray(),
        ];
    }
}
