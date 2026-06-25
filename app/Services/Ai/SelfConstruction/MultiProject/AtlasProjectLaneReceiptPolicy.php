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

    /**
     * @param  array{
     *     project_id:string,
     *     lane_namespace:string,
     *     event_type:string,
     *     evidence_hash:string,
     *     source_hashes?:list<string>,
     *     created_at:string
     * }  $facts
     * @return array{schema:string, project_id:string, lane_namespace:string, event_type:string, evidence_hash:string, source_hashes:list<string>, created_at:string, envelope_hash:string}
     */
    public function build(array $facts): array
    {
        $projectId = trim((string) ($facts['project_id'] ?? ''));
        $laneNs = trim((string) ($facts['lane_namespace'] ?? ''));
        $eventType = trim((string) ($facts['event_type'] ?? ''));
        $evidenceHash = trim((string) ($facts['evidence_hash'] ?? ''));
        $sourceHashes = is_array($facts['source_hashes'] ?? null)
            ? array_values(array_unique(array_filter(array_map('strval', $facts['source_hashes']), static fn (string $s): bool => $s !== '')))
            : [];
        sort($sourceHashes, SORT_STRING);
        $createdAt = trim((string) ($facts['created_at'] ?? ''));

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

        $envelope = [
            'schema' => self::SCHEMA,
            'project_id' => $projectId,
            'lane_namespace' => $laneNs,
            'event_type' => $eventType,
            'evidence_hash' => $evidenceHash,
            'source_hashes' => $sourceHashes,
            'created_at' => $createdAt,
        ];
        $canonical = $envelope;
        ksort($canonical);
        $envelope['envelope_hash'] = hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $envelope;
    }
}
