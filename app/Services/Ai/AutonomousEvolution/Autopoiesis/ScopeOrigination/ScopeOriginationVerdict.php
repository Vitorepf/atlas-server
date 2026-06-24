<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Autopoiesis\ScopeOrigination;

use JsonSerializable;

final class ScopeOriginationVerdict implements JsonSerializable
{
    public const PENDING_OPERATOR = 'pending_operator';
    public const AUTO_APPROVED = 'auto_approved';
    public const REJECTED = 'rejected';

    public function __construct(
        public readonly string $verdict,
        public readonly string $actor,
        public readonly ?string $timestamp,
        public readonly string $reason,
        public readonly ?float $coherence,
    ) {
    }

    public static function pending(string $reason = 'operator_required', ?float $coherence = null): self
    {
        return new self(self::PENDING_OPERATOR, 'auditor', null, $reason, $coherence);
    }

    public static function rejected(string $actor, string $reason, ?string $timestamp = null, ?float $coherence = null): self
    {
        return new self(self::REJECTED, $actor, $timestamp, $reason, $coherence);
    }

    public static function autoApproved(float $coherence): self
    {
        return new self(self::AUTO_APPROVED, 'autopoiesis_floor', null, 'coherence_floor_met', $coherence);
    }

    public static function operatorApproved(string $reason, string $timestamp, ?float $coherence = null): self
    {
        return new self(self::AUTO_APPROVED, 'operator', $timestamp, $reason, $coherence);
    }

    public static function operatorRejected(string $reason, string $timestamp, ?float $coherence = null): self
    {
        return new self(self::REJECTED, 'operator', $timestamp, $reason, $coherence);
    }

    /**
     * @return array{verdict:string,actor:string,timestamp:?string,reason:string,coherence:?float}
     */
    public function toArray(): array
    {
        return [
            'verdict' => $this->verdict,
            'actor' => $this->actor,
            'timestamp' => $this->timestamp,
            'reason' => $this->reason,
            'coherence' => $this->coherence,
        ];
    }

    /**
     * @return array{verdict:string,actor:string,timestamp:?string,reason:string,coherence:?float}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
