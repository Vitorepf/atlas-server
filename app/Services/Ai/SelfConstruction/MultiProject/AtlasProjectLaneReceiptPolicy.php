<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\MultiProject;

use RuntimeException;

/**
 * Builds deterministic receipt envelopes for project-lane stewardship events. Pure: NEVER touches
 * storage — the caller is responsible for persisting the returned envelope through an append-only
 * ledger. The policy supplies the canonical shape + envelope_hash so two callers building the same
 * event produce byte-identical receipts.
 *
 * EVENT TYPES (allowlist):
 *   - lane_admitted        — a lane manifest passed AtlasProjectLaneAdmissionPolicy
 *   - task_verified        — a candidate task cleared AtlasProjectLaneVerificationPolicy
 *   - release_decided      — the lane shipped (or chose to hold)
 *   - learning_recorded    — a per-attempt learning fact was written
 *
 * INVARIANTS:
 *   - Refuses unknown event types AND empty evidence_hash WITHOUT mutating storage (pure).
 *   - envelope_hash = sha256 over canonical {project_id, lane_namespace, event_type, evidence_hash,
 *     source_hashes (sorted), created_at} — deterministic.
 */
final class AtlasProjectLaneReceiptPolicy
{
    public const SCHEMA = 'atlas.multiproject.lane_receipt.v1';

    public const EVENT_LANE_ADMITTED = 'lane_admitted';

    public const EVENT_TASK_VERIFIED = 'task_verified';

    public const EVENT_RELEASE_DECIDED = 'release_decided';

    public const EVENT_LEARNING_RECORDED = 'learning_recorded';

    public const ALLOWED_EVENTS = [
        self::EVENT_LANE_ADMITTED,
        self::EVENT_TASK_VERIFIED,
        self::EVENT_RELEASE_DECIDED,
        self::EVENT_LEARNING_RECORDED,
    ];

    /** Source ref prefixes that expose provider internals — must never appear in provider-safe envelopes. */
    private const UNSAFE_PREFIXES = ['prompt:', 'provider:', 'private:', 'trace:'];

    /**
     * @param  array{
     *     project_id:string,
     *     lane_namespace:string,
     *     event_type:string,
     *     evidence_hash:string,
     *     provider_safe_source_refs?:list<string>,
     *     source_hashes?:list<string>,
     *     previous_envelope_hash?:string,
     *     lane_epoch?:int,
     *     event_sequence?:int,
     *     created_at:string
     * }  $facts
     * @return array<string,mixed>
     */
    public function build(array $facts): array
    {
        $projectId = trim((string) ($facts['project_id'] ?? ''));
        $laneNs = trim((string) ($facts['lane_namespace'] ?? ''));
        $eventType = trim((string) ($facts['event_type'] ?? ''));
        $evidenceHash = trim((string) ($facts['evidence_hash'] ?? ''));
        $previousEnvelopeHash = trim((string) ($facts['previous_envelope_hash'] ?? 'genesis'));
        $laneEpoch = (int) ($facts['lane_epoch'] ?? 0);
        $eventSequence = (int) ($facts['event_sequence'] ?? 0);
        $createdAt = trim((string) ($facts['created_at'] ?? ''));

        // Accept provider_safe_source_refs (preferred) or legacy source_hashes.
        $rawRefs = is_array($facts['provider_safe_source_refs'] ?? null)
            ? $facts['provider_safe_source_refs']
            : (is_array($facts['source_hashes'] ?? null) ? $facts['source_hashes'] : []);
        $sourceRefs = array_values(array_unique(array_filter(array_map('strval', $rawRefs), static fn (string $s): bool => $s !== '')));

        if ($projectId === '') {
            throw new RuntimeException('lane receipt: empty project_id');
        }
        if ($laneNs === '') {
            throw new RuntimeException('lane receipt: empty lane_namespace');
        }
        if (! in_array($eventType, self::ALLOWED_EVENTS, true)) {
            throw new RuntimeException('lane receipt: unknown event_type: '.($eventType === '' ? 'missing' : $eventType));
        }
        if ($evidenceHash === '') {
            throw new RuntimeException('lane receipt: empty evidence_hash');
        }
        if ($createdAt === '') {
            throw new RuntimeException('lane receipt: empty created_at');
        }

        // Reject raw prompt/provider/private traces.
        foreach ($sourceRefs as $ref) {
            foreach (self::UNSAFE_PREFIXES as $prefix) {
                if (str_starts_with($ref, $prefix)) {
                    throw new RuntimeException('lane receipt: provider-unsafe source_ref rejected: '.$ref);
                }
            }
        }
        sort($sourceRefs, SORT_STRING);

        $envelope = [
            'schema' => self::SCHEMA,
            'project_id' => $projectId,
            'lane_namespace' => $laneNs,
            'event_type' => $eventType,
            'evidence_hash' => $evidenceHash,
            'provider_safe_source_refs' => $sourceRefs,
            'previous_envelope_hash' => $previousEnvelopeHash,
            'lane_epoch' => $laneEpoch,
            'event_sequence' => $eventSequence,
            'created_at' => $createdAt,
        ];
        $canonical = $envelope;
        ksort($canonical);
        $envelope['envelope_hash'] = hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $envelope;
    }
}
