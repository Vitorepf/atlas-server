<?php

declare(strict_types=1);

namespace App\Services\Ai\Kernel\Architecture;

/**
 * Safe Next Block #4 — Provider Release ingestion runtime (proposal-only).
 *
 * The ingestion pass that converts an external release signal (from a
 * registered source) into a proposal envelope for the Curator queue.
 * NEVER mutates Decide/Policy directly. NEVER auto-applies. Every
 * ingested signal becomes a proposal that the operator/Curator review
 * gate decides on, per the DoD:
 *
 *   "Criar ingestion como proposal-only, sem mudar Decide/Policy ate
 *    AP-99/Rivals, review humano, policy patch e novo receipt."
 *
 * This service is the seam — actual crawler/fetch runtime lives in a
 * sibling AP (out of scope here per the boundary canon). This shipping
 * unit closes the gap from "no canonical ingestion proposal envelope"
 * to "ingestion has a typed, gated, anti-mutation contract".
 */
final class ProviderReleaseIngestionProposal
{
    public const SCHEMA_VERSION = 'atlas.provider_release.ingestion_proposal.v1';

    public const STATUS_PROPOSED = 'proposed_for_review';

    public const STATUS_DUPLICATE = 'duplicate_of_existing';

    public const STATUS_UNTRUSTED_SOURCE = 'rejected_untrusted_source';

    public const STATUS_INVALID_PAYLOAD = 'rejected_invalid_payload';

    public const ANTI_WRAPPER_INVARIANTS = [
        'never_mutates_decide',
        'never_mutates_policy',
        'never_auto_applies',
        'never_bypasses_review',
        'never_emits_provider_call',
    ];

    public function __construct(
        private readonly AtlasProviderReleaseSourceRegistry $registry,
    ) {}

    /**
     * @param  array{
     *   source_id?: string,
     *   raw_signal?: array<string,mixed>,
     *   fingerprint?: string,
     * }  $input
     * @return array{
     *   schema_version: string,
     *   status: string,
     *   proposed_at: string,
     *   source_id: ?string,
     *   source_trusted: bool,
     *   fingerprint: ?string,
     *   anti_wrapper_invariants: list<string>,
     *   forwards_to: ?string,
     *   detail: string,
     *   policy_mutation_allowed: bool
     * }
     */
    public function ingest(array $input): array
    {
        $sourceId = isset($input['source_id']) && is_string($input['source_id']) ? $input['source_id'] : null;
        $fingerprint = isset($input['fingerprint']) && is_string($input['fingerprint']) ? $input['fingerprint'] : null;
        $rawSignal = is_array($input['raw_signal'] ?? null) ? $input['raw_signal'] : [];

        if ($sourceId === null || $sourceId === '') {
            return $this->reject(self::STATUS_INVALID_PAYLOAD, $sourceId, $fingerprint,
                'source_id missing — every ingestion must declare its source');
        }

        if ($rawSignal === []) {
            return $this->reject(self::STATUS_INVALID_PAYLOAD, $sourceId, $fingerprint,
                'raw_signal empty — nothing to ingest');
        }

        $trusted = $this->sourceTrusted($sourceId);
        if (! $trusted) {
            return $this->reject(self::STATUS_UNTRUSTED_SOURCE, $sourceId, $fingerprint,
                sprintf('source_id "%s" not registered in trusted source registry; ingestion refused', $sourceId));
        }

        // Use payload hash as canonical fingerprint when not supplied.
        $fingerprint ??= hash('sha256', json_encode($rawSignal, JSON_UNESCAPED_SLASHES) ?: '');

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => self::STATUS_PROPOSED,
            'proposed_at' => now()->toAtomString(),
            'source_id' => $sourceId,
            'source_trusted' => true,
            'fingerprint' => $fingerprint,
            'anti_wrapper_invariants' => self::ANTI_WRAPPER_INVARIANTS,
            'forwards_to' => 'CuratorProposalQueue',
            'detail' => 'Proposal queued for human/Curator review. NEVER auto-applied. Decide/Policy untouched.',
            'policy_mutation_allowed' => false,
        ];
    }

    private function sourceTrusted(string $sourceId): bool
    {
        try {
            $source = $this->registry->findById($sourceId);

            return $source !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    private function reject(string $status, ?string $sourceId, ?string $fingerprint, string $detail): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'proposed_at' => now()->toAtomString(),
            'source_id' => $sourceId,
            'source_trusted' => false,
            'fingerprint' => $fingerprint,
            'anti_wrapper_invariants' => self::ANTI_WRAPPER_INVARIANTS,
            'forwards_to' => null,
            'detail' => $detail,
            'policy_mutation_allowed' => false,
        ];
    }
}
