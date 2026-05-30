<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry\Frontier\Armor;

use App\Services\Ai\Foundry\Frontier\FrontierProposalPacketDecomposerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\FindingSlicePlannerService;

/**
 * Foundry AP-C · Frontier Adversarial Armor · Stage I7 (Decomposer).
 *
 * This is the dangerous slice's armor: generation turns on upstream, and EVERY
 * adversarial drop must record a machine-readable reason. I7 is a HARD gate that
 * can independently DROP a proposal.
 *
 * A surviving proposal must decompose into 3-12 BOUNDED packets. The gate:
 *   - DROPS with {@see self::DROP_DECOMPOSITION_OUT_OF_BOUNDS} (status blocked)
 *     when proposed_packets count is < MIN_PACKETS or > MAX_PACKETS.
 *   - DROPS with {@see self::DROP_SCAFFOLD_ONLY_PACKET} when a packet would only
 *     ever become scaffold (gate_compat_contract): no acceptance_criteria AND no
 *     tests_required AND a delivery that matches the scaffold/stub/contract/
 *     interface/type-hint heuristic. See {@see self::isScaffoldOnly()}.
 *   - DROPS with {@see self::DROP_NEEDS_OPERATOR_SPEC} when the canonical planner
 *     blocks / requires operator review for a packet.
 *   - SURVIVES (status complete) only when EVERY packet is sliced by the planner
 *     and carries criteria.
 *
 * Composition (NOT duplication): the planner is invoked through
 * {@see FrontierProposalPacketDecomposerService::planPacket()} EXACTLY ONCE per
 * packet. This gate writes NOTHING to canon/docs/code; it returns a decision.
 */
final class FrontierDecomposerGate
{
    public const STAGE = 'I7';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_BLOCKED = 'blocked';

    public const DROP_DECOMPOSITION_OUT_OF_BOUNDS = 'decomposition_out_of_bounds';

    public const DROP_SCAFFOLD_ONLY_PACKET = 'scaffold_only_packet';

    public const DROP_NEEDS_OPERATOR_SPEC = 'needs_operator_spec';

    /** A proposal must decompose into at least this many bounded packets. */
    public const MIN_PACKETS = 3;

    /** A proposal must decompose into at most this many bounded packets. */
    public const MAX_PACKETS = 12;

    /**
     * Heuristic markers in a packet `delivery` that indicate the packet would
     * only ever produce scaffold (an empty contract/interface/stub), not real
     * behavior, when paired with empty acceptance + empty tests.
     *
     * @var list<string>
     */
    private const SCAFFOLD_DELIVERY_MARKERS = [
        'scaffold',
        'stub',
        'gate_compat_contract',
        'compat contract',
        'empty contract',
        'interface only',
        'interface-only',
        'type-hint',
        'type hint',
        'typehint',
        'placeholder',
        'no-op',
        'noop',
        'skeleton only',
    ];

    private FrontierProposalPacketDecomposerService $decomposer;

    public function __construct(?FrontierProposalPacketDecomposerService $decomposer = null)
    {
        $this->decomposer = $decomposer ?? new FrontierProposalPacketDecomposerService;
    }

    /**
     * Adjudicate one proposal's decomposition.
     *
     * @param  array<string,mixed>  $proposal
     * @return array{
     *   stage:string,
     *   status:string,
     *   proposal_id:string,
     *   packets_count:int,
     *   drops:list<array{proposal_id:string,drop_stage:string,reason:string,detail:string}>,
     *   sliced_packets:int,
     *   mutates_repo:false,
     *   canonical_doc_write_allowed:false,
     *   executed:false
     * }
     */
    public function adjudicate(array $proposal): array
    {
        $proposalId = trim((string) ($proposal['proposal_id'] ?? ''));
        $packets = is_array($proposal['proposed_packets'] ?? null)
            ? array_values($proposal['proposed_packets'])
            : [];
        $count = count($packets);

        // I7.1 — bounded packet count.
        if ($count < self::MIN_PACKETS || $count > self::MAX_PACKETS) {
            return $this->drop(
                $proposalId,
                $count,
                self::DROP_DECOMPOSITION_OUT_OF_BOUNDS,
                sprintf('proposed_packets count %d not within [%d,%d]', $count, self::MIN_PACKETS, self::MAX_PACKETS),
            );
        }

        $slicedPackets = 0;
        foreach ($packets as $index => $packet) {
            $packet = is_array($packet) ? $packet : [];

            // I7.2 — a packet that would only become scaffold is dropped.
            if (self::isScaffoldOnly($packet)) {
                return $this->drop(
                    $proposalId,
                    $count,
                    self::DROP_SCAFFOLD_ONLY_PACKET,
                    sprintf('packet #%d (%s) is scaffold-only: empty acceptance + empty tests + scaffold delivery', $index + 1, trim((string) ($packet['packet_id'] ?? ($packet['label'] ?? '?')))),
                );
            }

            // I7.3 — delegate to the canonical planner EXACTLY once per packet.
            $plan = $this->decomposer->planPacket($packet);
            $plannerStatus = (string) ($plan['decomposition_status'] ?? FindingSlicePlannerService::STATUS_BLOCKED);

            if ($plannerStatus !== FindingSlicePlannerService::STATUS_SLICED) {
                return $this->drop(
                    $proposalId,
                    $count,
                    self::DROP_NEEDS_OPERATOR_SPEC,
                    sprintf(
                        'packet #%d (%s) planner status=%s blockers=%s',
                        $index + 1,
                        trim((string) ($packet['packet_id'] ?? ($packet['label'] ?? '?'))),
                        $plannerStatus,
                        implode(',', array_map('strval', (array) ($plan['blockers'] ?? []))),
                    ),
                );
            }

            $slicedPackets++;
        }

        return [
            'stage' => self::STAGE,
            'status' => self::STATUS_COMPLETE,
            'proposal_id' => $proposalId,
            'packets_count' => $count,
            'drops' => [],
            'sliced_packets' => $slicedPackets,
            'mutates_repo' => false,
            'canonical_doc_write_allowed' => false,
            'executed' => false,
        ];
    }

    /**
     * A packet is scaffold-only when its delivery matches a scaffold/stub/
     * contract/interface/type-hint heuristic AND it declares no NON-TRIVIAL
     * acceptance_criteria and no NON-TRIVIAL tests_required. Such a packet can
     * only ever produce an empty contract, never falsifiable behavior — so it
     * must be dropped, never canonized.
     *
     * The marker scan runs UNCONDITIONALLY: an acceptance/test entry that is
     * itself a scaffold marker (e.g. "it compiles", a placeholder/stub test) does
     * NOT rescue the packet, because it asserts no real behavior. A packet whose
     * delivery is scaffold passes I7.2 only when at least one acceptance OR test
     * line is genuine (non-marker). Gating is on marker-matching, not on length,
     * so genuinely-bounded packets remain unaffected.
     *
     * @param  array<string,mixed>  $packet
     */
    public static function isScaffoldOnly(array $packet): bool
    {
        $delivery = strtolower(trim((string) ($packet['delivery'] ?? '')));

        // An empty delivery is the canonical empty-contract case.
        if ($delivery === '') {
            return self::stringList($packet['acceptance_criteria'] ?? []) === []
                && self::stringList($packet['tests_required'] ?? []) === [];
        }

        if (! self::matchesScaffoldMarker($delivery)) {
            // Non-scaffold delivery is never scaffold-only, regardless of count.
            return false;
        }

        // Scaffold delivery: require at least one GENUINE (non-marker) acceptance
        // or test line. Marker-matching lines (e.g. "it compiles") are trivial and
        // do NOT rescue the packet — they bypass the no-scaffold gate otherwise.
        $acceptance = self::stringList($packet['acceptance_criteria'] ?? []);
        $tests = self::stringList($packet['tests_required'] ?? []);

        foreach ([...$acceptance, ...$tests] as $line) {
            if (! self::matchesScaffoldMarker(strtolower($line))) {
                return false;
            }
        }

        // Delivery is scaffold AND every acceptance/test line is empty-or-marker.
        return true;
    }

    /**
     * True when the given (lowercased) string contains any scaffold marker.
     */
    private static function matchesScaffoldMarker(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        foreach (self::SCAFFOLD_DELIVERY_MARKERS as $marker) {
            if (str_contains($value, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{
     *   stage:string,status:string,proposal_id:string,packets_count:int,
     *   drops:list<array{proposal_id:string,drop_stage:string,reason:string,detail:string}>,
     *   sliced_packets:int,mutates_repo:false,canonical_doc_write_allowed:false,executed:false
     * }
     */
    private function drop(string $proposalId, int $count, string $reason, string $detail): array
    {
        return [
            'stage' => self::STAGE,
            'status' => self::STATUS_BLOCKED,
            'proposal_id' => $proposalId,
            'packets_count' => $count,
            'drops' => [[
                'proposal_id' => $proposalId,
                'drop_stage' => self::STAGE,
                'reason' => $reason,
                'detail' => $detail,
            ]],
            'sliced_packets' => 0,
            'mutates_repo' => false,
            'canonical_doc_write_allowed' => false,
            'executed' => false,
        ];
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => is_string($item) ? trim($item) : '',
            $value,
        ), static fn (string $item): bool => $item !== ''));
    }
}
