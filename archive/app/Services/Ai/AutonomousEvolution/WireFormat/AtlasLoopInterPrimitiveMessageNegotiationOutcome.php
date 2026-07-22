<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\WireFormat;

/**
 * Immutable outcome of {@see AtlasLoopInterPrimitiveMessageVersionNegotiator::negotiate()}.
 * Captures verbatim inputs so the ReceiptLedger can replay the decision.
 */
final class AtlasLoopInterPrimitiveMessageNegotiationOutcome
{
    public const REASON_HIGHEST_COMMON = 'highest_common';
    public const REASON_NO_COMMON_VERSION = 'no_common_version';
    public const REASON_PHANTOM_VERSION = 'phantom_version';
    public const REASON_UNKNOWN_FAMILY = 'unknown_family';

    /**
     * @param  list<int>  $sourceVersions  verbatim
     * @param  list<int>  $targetVersions  verbatim
     */
    public function __construct(
        public readonly string $family,
        public readonly array $sourceVersions,
        public readonly array $targetVersions,
        public readonly ?int $chosenVersion,
        public readonly string $reason,
        public readonly string $offendingSide = '',
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'family' => $this->family,
            'source_versions' => $this->sourceVersions,
            'target_versions' => $this->targetVersions,
            'chosen_version' => $this->chosenVersion,
            'reason' => $this->reason,
            'offending_side' => $this->offendingSide,
        ];
    }
}
