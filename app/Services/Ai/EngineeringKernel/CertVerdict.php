<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

/**
 * Engineering Kernel value: the sovereign acceptance decision — fail-closed.
 *
 * Owns: carrying the terminal verdict (promote|hold|refuse|review), the blockers that produced it,
 * the per-invariant breakdown for audit, and the witness-set that applied.
 * Must never own: acting on the verdict (landing/reverting is MergeActuator) or re-deciding it.
 */
final readonly class CertVerdict
{
    public const PROMOTE = 'promote';

    public const HOLD = 'hold';

    public const REFUSE = 'refuse';

    public const REVIEW = 'review';

    /**
     * @param  string  $status              one of PROMOTE|HOLD|REFUSE|REVIEW
     * @param  list<string>  $blockers       invariant ids that did not pass (empty iff PROMOTE)
     * @param  array<string,array{status:string,detail:string}>  $invariants  per-invariant audit trail
     * @param  string  $witnessSet          the witness-set descriptor for the trust level
     * @param  string|null  $receiptRef      pointer to the sealed receipt, once written
     */
    public function __construct(
        public string $status,
        public array $blockers,
        public array $invariants,
        public string $witnessSet,
        public ?string $receiptRef = null,
    ) {}

    /**
     * @param  array<string,array{status:string,detail:string}>  $invariants
     */
    public static function promote(array $invariants, string $witnessSet): self
    {
        return new self(self::PROMOTE, [], $invariants, $witnessSet);
    }

    /**
     * @param  list<string>  $blockers
     * @param  array<string,array{status:string,detail:string}>  $invariants
     */
    public static function refuse(array $blockers, array $invariants, string $witnessSet): self
    {
        return new self(self::REFUSE, array_values(array_unique($blockers)), $invariants, $witnessSet);
    }

    public function withReceiptRef(string $receiptRef): self
    {
        return new self($this->status, $this->blockers, $this->invariants, $this->witnessSet, $receiptRef);
    }

    public function promoted(): bool
    {
        return $this->status === self::PROMOTE;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'blockers' => $this->blockers,
            'invariants' => $this->invariants,
            'witness_set' => $this->witnessSet,
            'receipt_ref' => $this->receiptRef,
        ];
    }
}
