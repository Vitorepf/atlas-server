<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\AtlasAaeosStringListNormalizer;
use App\Services\Ai\Aaeos\Support\AtlasAaeosValueNormalizer;

/**
 * Atlas Self-Construction Packet Queue — pure, deterministic queue decider.
 *
 * The queue is the read-only work board a new AI session inspects before
 * choosing or receiving a packet. It consumes a split (candidate packets) plus
 * the durable reservation projection (active + completed reservations) and
 * decides, for every packet, a single queue_state, a rank for assignable work,
 * the recommended packet, and the hard non-execution guarantees. Same input
 * always yields the same output (no clock reads, no DB, no I/O).
 *
 * This is the COMPUTING queue (distinct from the static cold-lane fixture in
 * AtlasSelfConstructionReadinessService::packetQueue(), which is wired into the
 * live reservation projection and ranks by source index): here ranking is the
 * real documented multi-factor score, and the decider can be unit-pinned in
 * isolation.
 *
 * Documented rules this code enforces (from the doc):
 *
 *   Queue Entry Schema — queue_state closed set:
 *     available | claimed | completed | blocked_by_dependency | withheld.
 *     Precedence (a packet is exactly one state):
 *       1. withheld          (hot external work — never assignable);
 *       2. completed         (a completed reservation exists);
 *       3. claimed           (an active reservation exists);
 *       4. blocked_by_dependency (a depends_on packet is not completed);
 *       5. available         (otherwise).
 *
 *   Ranking Rules — "Packets rank higher when":
 *     - dependencies are empty;
 *     - collision risk is low;
 *     - allowed files are disjoint (no two assignable packets share a file —
 *       the file owner keeps priority, the later sharer is penalised);
 *     - packet can be validated with existing gates (has a scope validator);
 *     - packet does not touch hot Voice/Kernel scope.
 *     Only `available` packets receive a rank (1 = best); every other state
 *     carries rank=null. The recommended packet is the rank-1 available packet.
 *
 *   Required Guarantees — the preview MUST emit:
 *       execution_allowed=false
 *       claim_persisted=false
 *       ledger_write_allowed=false
 *       queue_write_allowed=false
 *     claim_persisted=false means the queue command itself did not claim; it may
 *     still REPORT packets claimed elsewhere (state=claimed) and completed
 *     elsewhere (state=completed).
 *
 *   Non Goals (honoured — the queue never widens authority):
 *     - does not dispatch work automatically;
 *     - does not execute work;
 *     - does not mark packet completion;
 *     - does not hide blocked or withheld work (every input packet appears once).
 *
 * @see docs/engineering-knowledge-base/self-construction/packet-queue-contract.md
 */
final class AtlasPacketQueueContractService
{
    /** Stable evidence schema id this queue emits. */
    public const SCHEMA = 'atlas.self_construction_packet_queue.v1';

    /** queue_state closed set (Queue Entry Schema). */
    public const STATE_AVAILABLE = 'available';
    public const STATE_CLAIMED = 'claimed';
    public const STATE_COMPLETED = 'completed';
    public const STATE_BLOCKED = 'blocked_by_dependency';
    public const STATE_WITHHELD = 'withheld';

    /** Overall queue status. */
    public const STATUS_READY = 'packet_queue_ready';

    /**
     * Hot scope substrings the Ranking Rules call out ("does not touch hot
     * Voice/Kernel scope"). Matched case-insensitively against allowed files.
     *
     * @var list<string>
     */
    private const HOT_SCOPE_NEEDLES = [
        'runtimes/python/voice_realtime/',
        'voice_realtime',
        'console/kernel',
        'http/kernel',
        'app/services/kernel/',
    ];

    /**
     * Build the deterministic queue preview from a split + reservation
     * projection. Read-only: dispatch and completion stay disabled.
     *
     * @param  array<string,mixed>  $input
     *         packets               : list<array{packet_id?:string, lane?:string,
     *                                  objective?:string, depends_on?:list<string>,
     *                                  allowed_files?:list<string>,
     *                                  forbidden_files?:list<string>,
     *                                  collision_risk?:string,
     *                                  claim_policy?:string,
     *                                  provider_profile?:string,
     *                                  scope_validator_command?:string|null}>
     *                                 candidate (assignable-by-default) packets.
     *         withheld              : list<array{id?:string|packet_id?:string,
     *                                  lane?:string, reason?:string,
     *                                  forbidden_scope?:list<string>}>
     *                                 hot external work — never assignable.
     *         active_reservations   : map<packet_id, array{reservation_id?:string,
     *                                  actor?:string, session?:string,
     *                                  lease_expires_at?:string}>
     *         completed_reservations: map<packet_id, array{reservation_id?:string,
     *                                  actor?:string, completed_at?:string}>
     * @return array<string,mixed>
     */
    public function preview(array $input): array
    {
        $packets = $this->normalizePackets($input['packets'] ?? []);
        $withheld = $this->normalizeWithheld($input['withheld'] ?? []);
        $active = $this->normalizeReservationMap($input['active_reservations'] ?? []);
        $completed = $this->normalizeReservationMap($input['completed_reservations'] ?? []);

        $completedIds = array_keys($completed);

        // Pass 1: classify state + capture per-packet ranking facts. Disjoint
        // ownership is awarded in input order so the rank score is stable.
        $rows = [];
        $ownedFiles = []; // file => packet_id that first claimed it (the owner)
        foreach ($packets as $packet) {
            $state = $this->classifyState($packet, $active, $completed, $completedIds);

            $sharedFiles = [];
            if ($state === self::STATE_AVAILABLE) {
                foreach ($packet['allowed_files'] as $file) {
                    if (isset($ownedFiles[$file])) {
                        $sharedFiles[] = $file;
                    } else {
                        $ownedFiles[$file] = $packet['packet_id'];
                    }
                }
            }

            $rows[] = [
                'packet' => $packet,
                'state' => $state,
                'shared_files' => array_values(array_unique($sharedFiles)),
            ];
        }

        // Pass 2: rank only the available packets via the documented score.
        $rankable = [];
        foreach ($rows as $i => $row) {
            if ($row['state'] === self::STATE_AVAILABLE) {
                $rankable[] = [
                    'index' => $i,
                    'packet_id' => $row['packet']['packet_id'],
                    'score' => $this->rankScore($row['packet'], $row['shared_files']),
                ];
            }
        }
        // Higher score first; stable tie-break on packet_id for determinism.
        usort($rankable, static function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            return strcmp($a['packet_id'], $b['packet_id']);
        });

        $rankByIndex = [];
        foreach ($rankable as $position => $r) {
            $rankByIndex[$r['index']] = $position + 1; // 1 = best
        }
        $recommendedPacketId = $rankable === [] ? null : $rankable[0]['packet_id'];

        // Pass 3: materialise entries in the Queue Entry Schema shape.
        $entries = [];
        foreach ($rows as $i => $row) {
            $entries[] = $this->buildEntry(
                $row['packet'],
                $row['state'],
                $rankByIndex[$i] ?? null,
                $recommendedPacketId,
                $active[$row['packet']['packet_id']] ?? null,
                $completed[$row['packet']['packet_id']] ?? null,
                $row['shared_files'],
            );
        }
        foreach ($withheld as $order => $item) {
            $entries[] = $this->buildWithheldEntry($item, $order + 1);
        }

        $counts = $this->countStates($entries);

        $queue = [
            'queue_id' => $this->queueId($input),
            'entry_count' => count($entries),
            'available_count' => $counts[self::STATE_AVAILABLE],
            'claimed_count' => $counts[self::STATE_CLAIMED],
            'completed_count' => $counts[self::STATE_COMPLETED],
            'blocked_count' => $counts[self::STATE_BLOCKED],
            'withheld_count' => $counts[self::STATE_WITHHELD],
            'recommended_packet_id' => $recommendedPacketId,
            'entries' => $entries,
            // Required Guarantees (also surfaced on the queue object).
            'execution_allowed' => false,
            'claim_persisted' => false,
            'ledger_write_allowed' => false,
            'queue_write_allowed' => false,
        ];

        return [
            'schema_version' => self::SCHEMA,
            'status' => self::STATUS_READY,
            'mode' => 'read_only_packet_queue',
            // Required Guarantees — top-level, exactly as documented.
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'ledger_write_allowed' => false,
            'queue_write_allowed' => false,
            'queue' => $queue,
            'queue_hash' => $this->stableHash($queue),
            'non_execution_guarantees' => [
                'packet_queue_does_not_dispatch_work',
                'packet_queue_does_not_execute_work',
                'packet_queue_does_not_mark_completion',
                'packet_queue_does_not_persist_claim',
                'packet_queue_does_not_write_ledger',
                'packet_queue_does_not_hide_blocked_or_withheld_work',
            ],
            'human_summary' => 'Packet queue is ready: available, claimed, completed, blocked and withheld packets are visible and ranked without claims, dispatch, ledger writes or execution.',
        ];
    }

    /**
     * Decide the single queue_state for a packet (precedence: withheld handled
     * separately upstream; here completed > claimed > blocked > available).
     *
     * @param  array<string,mixed>  $packet
     * @param  array<string,array<string,mixed>>  $active
     * @param  array<string,array<string,mixed>>  $completed
     * @param  list<string>  $completedIds
     */
    public function classifyState(array $packet, array $active, array $completed, array $completedIds): string
    {
        $id = $packet['packet_id'];

        if (isset($completed[$id])) {
            return self::STATE_COMPLETED;
        }
        if (isset($active[$id])) {
            return self::STATE_CLAIMED;
        }
        if (! $this->dependenciesComplete($packet['depends_on'], $completedIds)) {
            return self::STATE_BLOCKED;
        }

        return self::STATE_AVAILABLE;
    }

    /**
     * A packet is dependency-clear only when every depends_on id is completed.
     *
     * @param  list<string>  $dependsOn
     * @param  list<string>  $completedIds
     */
    public function dependenciesComplete(array $dependsOn, array $completedIds): bool
    {
        if ($dependsOn === []) {
            return true;
        }

        return array_diff($dependsOn, $completedIds) === [];
    }

    /**
     * Ranking Rules as an additive score (higher = ranks higher). Each
     * documented "ranks higher when" condition contributes one weighted point,
     * and a shared (non-disjoint) allowed file costs the disjoint point.
     *
     * @param  array<string,mixed>  $packet
     * @param  list<string>  $sharedFiles  allowed files already owned by an earlier packet
     */
    public function rankScore(array $packet, array $sharedFiles): int
    {
        $score = 0;

        // dependencies are empty
        if ($packet['depends_on'] === []) {
            $score += 8;
        }

        // collision risk is low (none counts as low-or-better)
        $risk = $packet['collision_risk'];
        if ($risk === 'none') {
            $score += 4;
        } elseif ($risk === 'low') {
            $score += 3;
        } elseif ($risk === 'medium') {
            $score += 1;
        }
        // high contributes 0.

        // allowed files are disjoint
        if ($sharedFiles === []) {
            $score += 4;
        }

        // packet can be validated with existing gates
        if ($packet['scope_validator_command'] !== null) {
            $score += 2;
        }

        // packet does not touch hot Voice/Kernel scope
        if (! $this->touchesHotScope($packet['allowed_files'])) {
            $score += 2;
        }

        return $score;
    }

    /**
     * @param  list<string>  $allowedFiles
     */
    public function touchesHotScope(array $allowedFiles): bool
    {
        foreach ($allowedFiles as $file) {
            $hay = strtolower($file);
            foreach (self::HOT_SCOPE_NEEDLES as $needle) {
                if (str_contains($hay, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $packet
     * @param  array<string,mixed>|null  $activeReservation
     * @param  array<string,mixed>|null  $completedReservation
     * @param  list<string>  $sharedFiles
     * @return array<string,mixed>
     */
    private function buildEntry(
        array $packet,
        string $state,
        ?int $rank,
        ?string $recommendedPacketId,
        ?array $activeReservation,
        ?array $completedReservation,
        array $sharedFiles,
    ): array {
        return [
            'packet_id' => $packet['packet_id'],
            'lane' => $packet['lane'],
            'objective' => $packet['objective'],
            'queue_state' => $state,
            'rank' => $rank,
            'active_reservation_id' => $activeReservation['reservation_id'] ?? null,
            'active_reservation_actor' => $activeReservation['actor'] ?? null,
            'active_reservation_session' => $activeReservation['session'] ?? null,
            'lease_expires_at' => $activeReservation['lease_expires_at'] ?? null,
            'completed_reservation_id' => $completedReservation['reservation_id'] ?? null,
            'completed_at' => $completedReservation['completed_at'] ?? null,
            'completion_actor' => $completedReservation['actor'] ?? null,
            'provider_profile' => $packet['provider_profile'],
            'claim_policy' => $packet['claim_policy'],
            'collision_risk' => $packet['collision_risk'],
            'allowed_files' => $packet['allowed_files'],
            'forbidden_files' => $packet['forbidden_files'],
            'depends_on' => $packet['depends_on'],
            'shared_allowed_files' => $sharedFiles,
            'recommended' => $recommendedPacketId !== null
                && $packet['packet_id'] === $recommendedPacketId
                && $state === self::STATE_AVAILABLE,
        ];
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    private function buildWithheldEntry(array $item, int $order): array
    {
        return [
            'packet_id' => $item['packet_id'],
            'lane' => $item['lane'],
            'objective' => $item['reason'],
            'queue_state' => self::STATE_WITHHELD,
            'rank' => null,
            'active_reservation_id' => null,
            'active_reservation_actor' => null,
            'active_reservation_session' => null,
            'lease_expires_at' => null,
            'completed_reservation_id' => null,
            'completed_at' => null,
            'completion_actor' => null,
            'provider_profile' => 'generic',
            'claim_policy' => 'not_assignable',
            'collision_risk' => 'blocked',
            'allowed_files' => [],
            'forbidden_files' => $item['forbidden_scope'],
            'depends_on' => [],
            'shared_allowed_files' => [],
            'recommended' => false,
            'withheld_order' => $order,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @return array<string,int>
     */
    private function countStates(array $entries): array
    {
        $counts = [
            self::STATE_AVAILABLE => 0,
            self::STATE_CLAIMED => 0,
            self::STATE_COMPLETED => 0,
            self::STATE_BLOCKED => 0,
            self::STATE_WITHHELD => 0,
        ];
        foreach ($entries as $entry) {
            $state = $entry['queue_state'];
            if (isset($counts[$state])) {
                $counts[$state]++;
            }
        }

        return $counts;
    }

    /**
     * @param  mixed  $packets
     * @return list<array<string,mixed>>
     */
    private function normalizePackets($packets): array
    {
        if (! is_array($packets)) {
            return [];
        }

        $out = [];
        $i = 0;
        foreach ($packets as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $i++;
            $out[] = [
                'packet_id' => $this->str($raw['packet_id'] ?? null, 'AIP-SPLIT-LOCAL-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT)),
                'lane' => $this->str($raw['lane'] ?? null, 'runtime_read_only'),
                'objective' => $this->str($raw['objective'] ?? null, 'Unspecified objective.'),
                'depends_on' => $this->strList($raw['depends_on'] ?? []),
                'allowed_files' => $this->strList($raw['allowed_files'] ?? []),
                'forbidden_files' => $this->strList($raw['forbidden_files'] ?? []),
                'collision_risk' => $this->normalizeRisk($raw['collision_risk'] ?? null),
                'claim_policy' => $this->str($raw['claim_policy'] ?? null, 'single_owner'),
                'provider_profile' => $this->normalizeProvider($raw['provider_profile'] ?? null),
                'scope_validator_command' => isset($raw['scope_validator_command'])
                    && is_string($raw['scope_validator_command'])
                    && trim($raw['scope_validator_command']) !== ''
                        ? trim($raw['scope_validator_command'])
                        : null,
            ];
        }

        return $out;
    }

    /**
     * @param  mixed  $withheld
     * @return list<array<string,mixed>>
     */
    private function normalizeWithheld($withheld): array
    {
        if (! is_array($withheld)) {
            return [];
        }

        $out = [];
        $i = 0;
        foreach ($withheld as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $i++;
            $id = $raw['packet_id'] ?? $raw['id'] ?? null;
            $out[] = [
                'packet_id' => $this->str($id, 'WITHHELD-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT)),
                'lane' => $this->str($raw['lane'] ?? null, 'withheld_hot_external'),
                'reason' => $this->str($raw['reason'] ?? null, 'Withheld hot external work.'),
                'forbidden_scope' => $this->strList($raw['forbidden_scope'] ?? []),
            ];
        }

        return $out;
    }

    /**
     * @param  mixed  $map
     * @return array<string,array<string,mixed>>
     */
    private function normalizeReservationMap($map): array
    {
        if (! is_array($map)) {
            return [];
        }

        $out = [];
        foreach ($map as $packetId => $raw) {
            if (! is_string($packetId) || trim($packetId) === '' || ! is_array($raw)) {
                continue;
            }
            $out[trim($packetId)] = $raw;
        }

        return $out;
    }

    private function normalizeRisk(mixed $risk): string
    {
        return AtlasAaeosValueNormalizer::lowercaseAllowed($risk, ['none', 'low', 'medium', 'high'], 'medium');
    }

    private function normalizeProvider(mixed $provider): string
    {
        return AtlasAaeosValueNormalizer::lowercaseAllowed($provider, ['codex', 'claude', 'gemini', 'local_agent', 'generic'], 'generic');
    }

    private function str(mixed $value, string $default): string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    /**
     * @param  mixed  $list
     * @return list<string>
     */
    private function strList($list): array
    {
        return AtlasAaeosStringListNormalizer::uniqueTrimmedStrings($list);
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function queueId(array $input): string
    {
        $given = $input['queue_id'] ?? null;

        return is_string($given) && trim($given) !== ''
            ? trim($given)
            : 'PACKET-QUEUE-SELF-CONSTRUCTION-READ-ONLY-0001';
    }

    /**
     * Deterministic content hash of the queue payload (queue_hash).
     *
     * @param  array<string,mixed>  $payload
     */
    private function stableHash(array $payload): string
    {
        return substr(hash('sha256', (string) json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR,
        )), 0, 16);
    }
}
