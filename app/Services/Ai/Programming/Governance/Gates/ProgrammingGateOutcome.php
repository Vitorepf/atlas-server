<?php

namespace App\Services\Ai\Programming\Governance\Gates;

/**
 * Immutable result of a single gate evaluation.
 */
class ProgrammingGateOutcome
{
    /**
     * @param  array<string,mixed>  $payload
     */
    public function __construct(
        public readonly string $status,
        public readonly bool $blocking,
        public readonly ?string $reason = null,
        public readonly array $payload = [],
        public readonly ?string $waiverReason = null,
    ) {}

    public static function passed(array $payload = [], ?string $reason = null, bool $blocking = true): self
    {
        return new self('passed', $blocking, $reason, $payload);
    }

    public static function failed(?string $reason = null, array $payload = [], bool $blocking = true): self
    {
        return new self('failed', $blocking, $reason, $payload);
    }

    public static function skipped(?string $reason = null, array $payload = [], bool $blocking = false): self
    {
        return new self('skipped', $blocking, $reason, $payload);
    }

    public static function waived(string $waiverReason, array $payload = []): self
    {
        return new self('waived', false, $waiverReason, $payload, $waiverReason);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'blocking' => $this->blocking,
            'reason' => $this->reason,
            'waiver_reason' => $this->waiverReason,
            'payload' => $this->payload,
        ];
    }
}
