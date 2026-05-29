<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * Minimal data contract for correlating Area Focus cycle records with AAEOS
 * deferred phase dispatch outcomes (P5–P9 JSONL queue).
 *
 * Step 1 of wiring deferred dispatch into {@see AreaFocusCycleRecorderService}:
 * shape only — no recorder wiring in this class.
 */
final class DeferredPhaseDispatchOutcomeContract
{
    public const SCHEMA = 'atlas.software_company_stewardship.deferred_phase_dispatch_outcome.v1';

    private function __construct(
        public readonly int $deferredDispatchCount,
        public readonly ?string $nextClaimedEnvelopeHash,
    ) {}

    public static function defaults(): self
    {
        return new self(
            deferredDispatchCount: 0,
            nextClaimedEnvelopeHash: null,
        );
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $hash = $input['next_claimed_envelope_hash'] ?? null;
        $normalizedHash = is_string($hash) && $hash !== '' ? $hash : null;

        return new self(
            deferredDispatchCount: max(0, (int) ($input['deferred_dispatch_count'] ?? 0)),
            nextClaimedEnvelopeHash: $normalizedHash,
        );
    }

    /**
     * @param  array<string,mixed>|null  $nextClaimedEnvelope
     */
    public static function fromDispatchSnapshot(int $pendingCount, ?array $nextClaimedEnvelope = null): self
    {
        return new self(
            deferredDispatchCount: max(0, $pendingCount),
            nextClaimedEnvelopeHash: $nextClaimedEnvelope === null
                ? null
                : 'sha256:'.MissionCanonicalHash::sha256($nextClaimedEnvelope),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'deferred_dispatch_count' => $this->deferredDispatchCount,
            'next_claimed_envelope_hash' => $this->nextClaimedEnvelopeHash,
        ];
    }
}
