<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Self-Construction Work Splitter — pure, deterministic packet splitter.
 *
 * Converts a construction backlog into up to N disjoint write-set packets that
 * multiple AI sessions can implement in parallel without colliding, while
 * withholding any unsafe or hot work and reporting why it was withheld.
 *
 * This is the COMPUTING splitter (distinct from the static cold-lane fixture in
 * AtlasSelfConstructionReadinessService::workSplitter()): it takes the real
 * documented Splitter Input (backlog, current_git_status, hot_scopes,
 * dependency_graph, available_lanes, max_packets, risk_policy) and DECIDES the
 * split. Same input always yields the same output.
 *
 * Documented rules this code enforces (from the doc):
 *
 *   Decisions:
 *     - "Parallel AI work is allowed only through disjoint write sets."
 *         => two emitted packets may never share an allowed file (the second is
 *            withheld with reason `write_set_overlap`).
 *     - "Hot external files block assignment, not validation."
 *         => an item whose write set intersects a hot scope is WITHHELD (never
 *            emitted as a packet), but hot files are always listed as
 *            `forbidden_files` on every emitted packet.
 *     - "Work Splitter must prefer fewer safe packets over many risky packets."
 *         => high-collision items are never emitted; the emitted set is capped at
 *            max_packets (default 5) after safe items are chosen.
 *
 *   Collision Detection (collision_risk = high blocks assignment) — high when:
 *     - any write file is already modified by another lane (current_git_status);
 *     - any write file is inside a hot scope;
 *     - migrations, routes, providers, daemons or runtime entrypoints involved;
 *     - packet lacks a scope validator command.
 *
 *   Assignment Policy ordering (the splitter chooses packets in this order):
 *     1. documentation contracts blocking future runtime;
 *     2. schema and example completion;
 *     3. read-only command and service surfaces;
 *     4. focused tests for read-only surfaces;
 *     5. scoped execution only after signatures and receipts.
 *
 *   Lane Model (default risk per lane) + the `hot_external` lane is `blocked`.
 *
 *   Failure Modes:
 *     - Too many packets: emit fewer, safer packets (cap at max_packets).
 *     - Shared file needed: assign that file to exactly one packet (later one
 *       withheld as write_set_overlap).
 *     - Hot scope detected: withhold the affected packet.
 *     - Missing structural contract: emit documentation packet first / withhold
 *       the runtime item that lacks a structural contract.
 *     - Unknown generated file: mark unknown and require human review.
 *
 *   Claim/Status closed set: available|claimed|blocked|completed|stale.
 *
 * Non-goals honoured (the splitter never widens authority):
 *   - It does NOT claim packets, does NOT apply patches, does NOT enable
 *     execution, does NOT create merge authority. It only plans a safe split and
 *     reports withheld work. execution_allowed is always false.
 *
 * @see docs/engineering-knowledge-base/self-construction/work-splitter-contract.md
 */
final class AtlasWorkSplitterContractService
{
    /** Stable evidence schema id this splitter emits. */
    public const SCHEMA = 'atlas.self_construction.work_splitter.v1';

    /** Hard ceiling on emitted packets regardless of requested max_packets. */
    public const MAX_PACKETS_CEILING = 5;

    /** Collision risk closed set (from Packet Output: collision_risk). */
    public const RISK_NONE = 'none';
    public const RISK_LOW = 'low';
    public const RISK_MEDIUM = 'medium';
    public const RISK_HIGH = 'high';

    /** Packet status closed set (from Claim Policy). */
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_CLAIMED = 'claimed';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_STALE = 'stale';

    /**
     * Lane Model: lane => default risk. The `hot_external` lane is `blocked`
     * (its items are always withheld, never assignable).
     *
     * @var array<string,string>
     */
    private const LANE_DEFAULT_RISK = [
        'docs' => self::RISK_LOW,
        'packet_contracts' => self::RISK_LOW,
        'command_surface' => self::RISK_LOW,
        'readiness_service' => self::RISK_MEDIUM,
        'tests' => self::RISK_LOW,
        'runtime_read_only' => self::RISK_MEDIUM,
        'runtime_scoped' => self::RISK_HIGH,
        'hot_external' => self::RISK_HIGH,
    ];

    /** Lanes that are never assignable (the `blocked` lane in the Lane Model). */
    private const BLOCKED_LANES = ['hot_external'];

    /**
     * Assignment Policy ordering: lane => priority rank (lower wins). Mirrors the
     * documented order — docs/contracts first, then schema/service surfaces, then
     * tests, then scoped execution last.
     *
     * @var array<string,int>
     */
    private const LANE_PRIORITY = [
        'docs' => 1,
        'packet_contracts' => 1,
        'command_surface' => 3,
        'readiness_service' => 3,
        'runtime_read_only' => 3,
        'tests' => 4,
        'runtime_scoped' => 5,
        'hot_external' => 9,
    ];

    /**
     * Collision Detection: path substrings that force collision_risk=high
     * ("migrations, routes, providers, daemons or runtime entrypoints"), mapped
     * to the documented reason. Matched case-insensitively against write files.
     *
     * @var array<string,string>
     */
    private const HIGH_RISK_NEEDLES = [
        '/migrations/' => 'migration',
        'routes/' => 'route_file',
        'serviceprovider' => 'service_provider',
        'app/providers/' => 'service_provider',
        'daemon' => 'daemon_entrypoint',
        'console/kernel' => 'runtime_entrypoint',
        'http/kernel' => 'runtime_entrypoint',
        'bootstrap/app' => 'runtime_entrypoint',
        'public/index.php' => 'runtime_entrypoint',
    ];

    /**
     * Emit a safe, disjoint split from the documented Splitter Input.
     *
     * @param array<string,mixed> $input
     *        backlog            : list<array{id?:string, objective?:string,
     *                             lane?:string, write_files?:list<string>,
     *                             depends_on?:list<string>,
     *                             requires_structural_contract?:bool,
     *                             has_structural_contract?:bool,
     *                             scope_validator_command?:string|null,
     *                             generated_files?:list<string>}>
     *        current_git_status : list<string>  files already modified (any lane)
     *        hot_scopes         : list<string>  paths owned by another active front
     *        dependency_graph   : list<array{from?:string,to?:string}>  (advisory)
     *        available_lanes    : list<string>  lanes the operator allows now
     *        max_packets        : int           requested ceiling (<= 5)
     *        risk_policy        : string         conservative|balanced
     *
     * @return array<string,mixed>
     */
    public function split(array $input): array
    {
        $backlog = $this->normalizeBacklog($input['backlog'] ?? []);
        $gitStatus = $this->normalizePaths($input['current_git_status'] ?? []);
        $hotScopes = $this->normalizePaths($input['hot_scopes'] ?? []);
        $availableLanes = $this->normalizePaths($input['available_lanes'] ?? []);
        $riskPolicy = $this->normalizeRiskPolicy($input['risk_policy'] ?? 'conservative');

        $maxPackets = $this->normalizeMaxPackets($input['max_packets'] ?? self::MAX_PACKETS_CEILING);

        $forbiddenForAll = $hotScopes; // hot files are forbidden on every packet
        $splitId = $this->splitId($input);

        $packets = [];
        $withheld = [];
        $blockingReasons = [];
        $claimedFiles = []; // disjoint write-set ledger across already-emitted packets
        $seq = 0;

        // Stable, policy-driven ordering BEFORE assignment so the split is
        // deterministic and respects the Assignment Policy.
        $ordered = $this->orderByAssignmentPolicy($backlog);

        foreach ($ordered as $item) {
            // 1. Lane gate — the `hot_external` lane is never assignable.
            if (in_array($item['lane'], self::BLOCKED_LANES, true)) {
                $withheld[] = $this->withhold($item, 'blocked_lane',
                    'Lane '.$item['lane'].' is blocked: owned by another active front.');
                $blockingReasons[] = 'blocked_lane:'.$item['id'];

                continue;
            }

            // 2. Operator lane gate — if available_lanes is set, lane must be in it.
            if ($availableLanes !== [] && ! in_array($item['lane'], $availableLanes, true)) {
                $withheld[] = $this->withhold($item, 'lane_not_available',
                    'Lane '.$item['lane'].' is not in available_lanes for this session.');

                continue;
            }

            // 3. Missing structural contract (Failure Mode) — withhold the runtime
            //    item; a documentation packet must come first.
            if ($item['requires_structural_contract'] && ! $item['has_structural_contract']) {
                $withheld[] = $this->withhold($item, 'missing_structural_contract',
                    'Item requires a structural contract that does not exist yet; emit a documentation packet first.');
                $blockingReasons[] = 'missing_structural_contract:'.$item['id'];

                continue;
            }

            // 4. Hot scope intersection (decision: hot files block assignment) —
            //    withhold, but still surface forbidden scope.
            $hotHit = $this->intersectScopes($item['write_files'], $hotScopes);
            if ($hotHit !== []) {
                $withheld[] = $this->withhold($item, 'hot_scope',
                    'Write set intersects hot scope owned by another front.', $hotHit);
                $blockingReasons[] = 'hot_scope:'.$item['id'];

                continue;
            }

            // 5. Collision risk — `high` blocks assignment.
            $assessment = $this->assessCollision($item, $gitStatus, $hotScopes);
            if ($assessment['risk'] === self::RISK_HIGH) {
                $withheld[] = $this->withhold($item, 'collision_high',
                    'Collision risk is high: '.implode(', ', $assessment['reasons']).'.');
                $blockingReasons[] = 'collision_high:'.$item['id'];

                continue;
            }

            // 6. Disjoint write-set rule — two packets may not write the same file.
            $overlap = $this->intersectScopes($item['write_files'], $claimedFiles);
            if ($overlap !== []) {
                $withheld[] = $this->withhold($item, 'write_set_overlap',
                    'Write set overlaps a file already assigned to an earlier packet; assign that file to exactly one packet.', $overlap);

                continue;
            }

            // 7. Capacity (Failure Mode: too many packets → emit fewer, safer ones).
            if (count($packets) >= $maxPackets) {
                $withheld[] = $this->withhold($item, 'over_capacity',
                    'Split already holds max_packets safe packets; preferring fewer safe packets over many.');

                continue;
            }

            // ADMIT — emit a disjoint, scope-validated packet.
            $seq++;
            $packets[] = $this->buildPacket($item, $seq, $forbiddenForAll, $assessment, $splitId);
            foreach ($item['write_files'] as $f) {
                $claimedFiles[] = $f;
            }
        }

        $unknownReview = $this->collectUnknownGenerated($backlog);

        return [
            'schema_version' => self::SCHEMA,
            'split_id' => $splitId,
            'status' => $packets === [] ? 'no_safe_packets' : 'split_ready',
            'mode' => 'read_only_work_splitter',
            'execution_allowed' => false,
            'risk_policy' => $riskPolicy,
            'max_packets' => $maxPackets,
            'packet_count' => count($packets),
            'withheld_count' => count($withheld),
            'packets' => $packets,
            'withheld_work' => $withheld,
            'blocking_reasons' => array_values(array_unique($blockingReasons)),
            'human_review_required' => $unknownReview,
            'non_execution_guarantees' => [
                'work_splitter_does_not_claim_packets',
                'work_splitter_does_not_apply_patch',
                'work_splitter_does_not_edit_hot_files',
                'work_splitter_does_not_enable_execution',
            ],
            'split_hash' => $this->stableHash([
                'id' => $splitId,
                'packets' => $packets,
                'withheld' => $withheld,
            ]),
        ];
    }

    /**
     * Assess collision risk for one item per the Collision Detection rules.
     *
     * @param array<string,mixed> $item
     * @param list<string> $gitStatus
     * @param list<string> $hotScopes
     * @return array{risk:string,reasons:list<string>}
     */
    public function assessCollision(array $item, array $gitStatus, array $hotScopes): array
    {
        $reasons = [];
        $writeFiles = $this->normalizePaths($item['write_files'] ?? []);

        // Rule: any write file already modified by another lane.
        $alreadyModified = $this->intersectScopes($writeFiles, $this->normalizePaths($gitStatus));
        if ($alreadyModified !== []) {
            $reasons[] = 'files_already_modified_by_another_lane';
        }

        // Rule: any write file inside a hot scope.
        if ($this->intersectScopes($writeFiles, $this->normalizePaths($hotScopes)) !== []) {
            $reasons[] = 'write_inside_hot_scope';
        }

        // Rule: migrations/routes/providers/daemons/runtime entrypoints involved.
        foreach ($writeFiles as $file) {
            $needle = $this->matchHighRiskNeedle($file);
            if ($needle !== null) {
                $reasons[] = $needle;
            }
        }

        // Rule: packet lacks a scope validator command.
        $validator = $item['scope_validator_command'] ?? null;
        if (! is_string($validator) || trim($validator) === '') {
            $reasons[] = 'missing_scope_validator_command';
        }

        $reasons = array_values(array_unique($reasons));

        if ($reasons !== []) {
            return ['risk' => self::RISK_HIGH, 'reasons' => $reasons];
        }

        // No high-risk triggers: risk derives from the lane's default risk.
        $laneRisk = self::LANE_DEFAULT_RISK[$item['lane']] ?? self::RISK_MEDIUM;
        // A clean, dependency-free, low-lane item with a validator is `none`.
        if ($laneRisk === self::RISK_LOW && ($item['depends_on'] ?? []) === []) {
            return ['risk' => self::RISK_NONE, 'reasons' => []];
        }

        return ['risk' => $laneRisk, 'reasons' => []];
    }

    /**
     * The Lane Model table as a typed map (lane => default risk).
     *
     * @return array<string,string>
     */
    public function laneModel(): array
    {
        return self::LANE_DEFAULT_RISK;
    }

    /**
     * Build one emitted packet matching the documented Packet Output schema.
     *
     * @param array<string,mixed> $item
     * @param list<string> $forbiddenForAll
     * @param array{risk:string,reasons:list<string>} $assessment
     * @return array<string,mixed>
     */
    private function buildPacket(array $item, int $seq, array $forbiddenForAll, array $assessment, string $splitId): array
    {
        $packetId = sprintf('AIP-%s-%04d', $this->dateStamp($splitId), $seq);

        return [
            'packet_id' => $packetId,
            'lane' => $item['lane'],
            'objective' => $item['objective'],
            'allowed_files' => $item['write_files'],
            'forbidden_files' => $forbiddenForAll,
            'depends_on' => $item['depends_on'],
            'collision_risk' => $assessment['risk'],
            'claim_policy' => 'single_owner',
            'status' => self::STATUS_AVAILABLE,
            'scope_validator_command' => $item['scope_validator_command'],
        ];
    }

    /**
     * @param array<string,mixed> $item
     * @param list<string> $intersection
     * @return array<string,mixed>
     */
    private function withhold(array $item, string $reasonCode, string $message, array $intersection = []): array
    {
        return [
            'id' => $item['id'],
            'lane' => $item['lane'],
            'reason_code' => $reasonCode,
            'reason' => $message,
            'forbidden_scope' => $intersection,
            'status' => self::STATUS_BLOCKED,
        ];
    }

    /**
     * Order backlog by Assignment Policy: lane priority first, then a stable tie
     * break on id so the split is deterministic.
     *
     * @param list<array<string,mixed>> $backlog
     * @return list<array<string,mixed>>
     */
    private function orderByAssignmentPolicy(array $backlog): array
    {
        usort($backlog, function (array $a, array $b): int {
            $pa = self::LANE_PRIORITY[$a['lane']] ?? 7;
            $pb = self::LANE_PRIORITY[$b['lane']] ?? 7;
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }

            return strcmp($a['id'], $b['id']);
        });

        return array_values($backlog);
    }

    /**
     * Unknown generated files (Failure Mode: unknown generated file → human
     * review). An item may declare generated_files that are NOT inside its write
     * set; those are flagged for review rather than silently assigned.
     *
     * @param list<array<string,mixed>> $backlog
     * @return list<array{item:string,unknown_generated:list<string>}>
     */
    private function collectUnknownGenerated(array $backlog): array
    {
        $out = [];
        foreach ($this->normalizeBacklog($backlog) as $item) {
            $generated = $this->normalizePaths($item['generated_files'] ?? []);
            if ($generated === []) {
                continue;
            }
            $unknown = array_values(array_diff($generated, $item['write_files']));
            if ($unknown !== []) {
                $out[] = ['item' => $item['id'], 'unknown_generated' => $unknown];
            }
        }

        return $out;
    }

    /**
     * @return string|null the documented reason if a high-risk needle matches
     */
    private function matchHighRiskNeedle(string $file): ?string
    {
        $hay = strtolower($file);
        foreach (self::HIGH_RISK_NEEDLES as $needle => $reason) {
            if (str_contains($hay, $needle)) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * Prefix-aware intersection: a candidate path collides if it equals a scope
     * or sits under a scope prefix (and vice-versa), matching how hot scopes and
     * write sets are expressed (files or directory prefixes).
     *
     * @param list<string> $candidates
     * @param list<string> $scopes
     * @return list<string> the colliding candidate paths
     */
    private function intersectScopes(array $candidates, array $scopes): array
    {
        $hits = [];
        foreach ($this->normalizePaths($candidates) as $c) {
            foreach ($this->normalizePaths($scopes) as $s) {
                if ($c === $s
                    || str_starts_with($c, rtrim($s, '/').'/')
                    || str_starts_with($s, rtrim($c, '/').'/')) {
                    $hits[] = $c;
                    break;
                }
            }
        }

        return array_values(array_unique($hits));
    }

    /**
     * @param mixed $backlog
     * @return list<array<string,mixed>>
     */
    private function normalizeBacklog($backlog): array
    {
        if (! is_array($backlog)) {
            return [];
        }

        $out = [];
        $i = 0;
        foreach ($backlog as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $i++;
            $id = isset($raw['id']) && is_string($raw['id']) && trim($raw['id']) !== ''
                ? trim($raw['id'])
                : 'ITEM-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT);

            $out[] = [
                'id' => $id,
                'lane' => isset($raw['lane']) && is_string($raw['lane']) && trim($raw['lane']) !== ''
                    ? trim($raw['lane'])
                    : 'runtime_read_only',
                'objective' => isset($raw['objective']) && is_string($raw['objective'])
                    ? $raw['objective']
                    : 'Unspecified objective.',
                'write_files' => $this->normalizePaths($raw['write_files'] ?? $raw['allowed_files'] ?? []),
                'depends_on' => $this->normalizePaths($raw['depends_on'] ?? []),
                'requires_structural_contract' => (bool) ($raw['requires_structural_contract'] ?? false),
                'has_structural_contract' => (bool) ($raw['has_structural_contract'] ?? true),
                'scope_validator_command' => isset($raw['scope_validator_command']) && is_string($raw['scope_validator_command'])
                    ? $raw['scope_validator_command']
                    : null,
                'generated_files' => $this->normalizePaths($raw['generated_files'] ?? []),
            ];
        }

        return $out;
    }

    /**
     * @param mixed $paths
     * @return list<string>
     */
    private function normalizePaths($paths): array
    {
        if (! is_array($paths)) {
            return [];
        }

        $out = [];
        foreach ($paths as $p) {
            if (is_string($p) && trim($p) !== '') {
                $out[] = trim($p);
            }
        }

        return array_values(array_unique($out));
    }

    private function normalizeRiskPolicy(mixed $policy): string
    {
        $p = is_string($policy) ? strtolower(trim($policy)) : '';

        return in_array($p, ['conservative', 'balanced'], true) ? $p : 'conservative';
    }

    private function normalizeMaxPackets(mixed $max): int
    {
        $n = is_numeric($max) ? (int) $max : self::MAX_PACKETS_CEILING;
        if ($n < 1) {
            return 1;
        }

        return min($n, self::MAX_PACKETS_CEILING);
    }

    /**
     * @param array<string,mixed> $input
     */
    private function splitId(array $input): string
    {
        $given = $input['split_id'] ?? null;
        if (is_string($given) && trim($given) !== '') {
            return trim($given);
        }

        return 'SPLIT-'.$this->dateStamp(null).'-0001';
    }

    /**
     * Derive a stable YYYYMMDD stamp from a split id if it carries one, else a
     * fixed deterministic stamp (the splitter must be pure: no clock reads).
     */
    private function dateStamp(?string $splitId): string
    {
        if (is_string($splitId) && preg_match('/(\d{8})/', $splitId, $m) === 1) {
            return $m[1];
        }

        return 'YYYYMMDD';
    }

    /**
     * Deterministic content hash of any array payload.
     *
     * @param array<string,mixed> $payload
     */
    private function stableHash(array $payload): string
    {
        return substr(hash('sha256', (string) json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR,
        )), 0, 16);
    }
}
