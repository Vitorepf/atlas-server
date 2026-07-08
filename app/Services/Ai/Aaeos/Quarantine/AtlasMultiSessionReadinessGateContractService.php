<?php

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Runtime for the Atlas Self-Construction Multi-Session Readiness Gate Contract.
 *
 * The doc defines a DETERMINISTIC, READ-ONLY gate that answers whether the
 * current packet system is ready for multiple AI sessions across one or more
 * providers. It is explicitly a NON-actor: it must not start sessions, dispatch
 * packets, persist claims, write reservation-ledger rows or promote completion.
 *
 * Load-bearing rules enforced here, straight from the doc:
 *
 *   - Decision values (only these five may ever be returned):
 *       blocked_for_multi_session
 *       preview_only_single_session
 *       parallel_preview_ready_but_not_durable
 *       ready_for_multi_session_preview
 *       ready_for_durable_dispatch          (future value)
 *
 *   - Provider-Neutral Readiness: every selected packet MUST carry a universal
 *     implementation contract. Provider-specific instructions are projections,
 *     never source of truth. No provider gets broader scope because of long
 *     context or a strong model. No provider may approve/merge/dispatch or mark
 *     another provider complete.
 *
 *   - Hot Work Policy: hot external Voice/Kernel work stays withheld and visible.
 *     It is a NON-BLOCKING warning for cold-lane parallel preview when the
 *     cold-lane packets are disjoint, and a HARD blocker only if a selected
 *     packet attempts to own or edit the hot scope.
 *
 *   - "ready_for_multi_session_preview" is the expected state after the local
 *     reservation ledger exists AND five cold-lane packets are available, each
 *     consuming the universal packet contract, with manual start only and
 *     automated dispatch off.
 *
 *   - "ready_for_durable_dispatch" requires durable claims AND a reservation
 *     ledger that actually persists rows. The contract states durable parallel
 *     execution remains blocked, so this gate never auto-grants it: it requires
 *     an explicit durable-claims + persistent-ledger signal that the contract
 *     says does not exist yet.
 *
 * Everything here is PURE and deterministic. No DB, no I/O, no clock.
 *
 * @see docs/engineering-knowledge-base/self-construction/multi-session-readiness-gate-contract.md
 */
final class AtlasMultiSessionReadinessGateContractService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.multi_session_readiness_gate_contract.v1';

    /** The gate is read-only and never an actor (Non Goals section). */
    public const MODE = 'deterministic_read_only_gate';

    /** Decision values, in ascending readiness order. The doc lists exactly these. */
    public const DECISION_BLOCKED = 'blocked_for_multi_session';

    public const DECISION_PREVIEW_ONLY_SINGLE = 'preview_only_single_session';

    public const DECISION_PARALLEL_PREVIEW_NOT_DURABLE = 'parallel_preview_ready_but_not_durable';

    public const DECISION_READY_MULTI_PREVIEW = 'ready_for_multi_session_preview';

    public const DECISION_READY_DURABLE_DISPATCH = 'ready_for_durable_dispatch';

    public const DECISION_VALUES = [
        self::DECISION_BLOCKED,
        self::DECISION_PREVIEW_ONLY_SINGLE,
        self::DECISION_PARALLEL_PREVIEW_NOT_DURABLE,
        self::DECISION_READY_MULTI_PREVIEW,
        self::DECISION_READY_DURABLE_DISPATCH,
    ];

    /**
     * The number of disjoint cold-lane packets the doc names as the threshold for
     * the expected `ready_for_multi_session_preview` state ("five cold-lane
     * packets are available", "five provider sessions can be manually started").
     */
    public const REQUIRED_COLD_LANE_PACKETS_FOR_MULTI_PREVIEW = 5;

    /**
     * Things this gate is forbidden to do (Non Goals). Always echoed in the
     * output so a caller can never read the gate as an action grant.
     */
    public const NON_GOALS = [
        'start_sessions',
        'dispatch_packets',
        'persist_claims',
        'write_reservation_ledger_rows',
        'promote_completion',
    ];

    /**
     * Evaluate the readiness gate for a set of candidate packets.
     *
     * @param  array<int, array<string,mixed>>  $packets  each: {
     *     id?:string,
     *     lane?:string ('cold'|'hot'),
     *     has_universal_contract?:bool,
     *     scope_paths?:array<int,string>,
     *     owns_hot_scope?:bool,
     *     provider?:string
     *   }
     * @param  array{
     *     reservation_ledger_exists?:bool,
     *     durable_claims_enabled?:bool,
     *     reservation_ledger_persists_rows?:bool,
     *     hot_work_pending?:bool,
     *     hot_scope_paths?:array<int,string>
     *   }  $environment
     * @return array<string,mixed>
     */
    public function evaluate(array $packets, array $environment = []): array
    {
        $reservationLedgerExists = (bool) ($environment['reservation_ledger_exists'] ?? false);
        $durableClaimsEnabled = (bool) ($environment['durable_claims_enabled'] ?? false);
        $ledgerPersistsRows = (bool) ($environment['reservation_ledger_persists_rows'] ?? false);
        $hotWorkPending = (bool) ($environment['hot_work_pending'] ?? false);
        $hotScopePaths = $this->normalizePaths($environment['hot_scope_paths'] ?? []);

        $normalized = $this->normalizePackets($packets);

        $blockers = [];
        $warnings = [];

        // --- Provider-Neutral Readiness: every selected packet needs a universal
        // implementation contract. Missing it is a hard blocker. ---
        $missingContract = array_values(array_filter(
            $normalized,
            static fn (array $p): bool => $p['has_universal_contract'] !== true,
        ));
        foreach ($missingContract as $p) {
            $blockers[] = [
                'reason' => 'packet_missing_universal_contract',
                'packet_id' => $p['id'],
            ];
        }

        // --- Collision check: cold-lane preview requires disjoint packet scopes.
        // Any overlap between two selected packets is a hard blocker. ---
        $collisions = $this->detectScopeCollisions($normalized);
        foreach ($collisions as $pair) {
            $blockers[] = [
                'reason' => 'packet_scope_collision',
                'packet_ids' => $pair,
            ];
        }

        // --- Hot Work Policy. A selected packet that OWNS/EDITS hot scope is a
        // hard blocker. Pending hot work that nobody owns is only a warning when
        // the cold lane is otherwise disjoint. ---
        foreach ($normalized as $p) {
            $touchesHot = $p['owns_hot_scope'] === true
                || $this->intersects($p['scope_paths'], $hotScopePaths);
            if ($touchesHot) {
                $blockers[] = [
                    'reason' => 'packet_owns_or_edits_hot_scope',
                    'packet_id' => $p['id'],
                ];
            }
        }
        if ($hotWorkPending) {
            $warnings[] = [
                'reason' => 'hot_voice_kernel_work_withheld_and_visible',
                'severity' => 'non_blocking',
            ];
        }

        // Cold-lane packets that are individually safe (have a contract).
        $coldLanePackets = array_values(array_filter(
            $normalized,
            static fn (array $p): bool => $p['lane'] === 'cold' && $p['has_universal_contract'] === true,
        ));

        $hardBlocked = $blockers !== [];

        $decision = $this->decide(
            hardBlocked: $hardBlocked,
            coldLaneCount: count($coldLanePackets),
            reservationLedgerExists: $reservationLedgerExists,
            durableClaimsEnabled: $durableClaimsEnabled,
            ledgerPersistsRows: $ledgerPersistsRows,
        );

        // Durable dispatch is NEVER auto-granted by this gate; the contract keeps
        // durable parallel execution blocked. Even when the operator signals
        // durable claims + a persistent ledger, the gate reports the value but
        // keeps execution off (read-only contract).
        $durableDispatchEnabled = false;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'decision' => $decision,
            'durable_dispatch_enabled' => $durableDispatchEnabled,
            'starts_sessions' => false,
            'non_goals' => self::NON_GOALS,
            'inputs' => [
                'packet_count' => count($normalized),
                'cold_lane_ready_packet_count' => count($coldLanePackets),
                'reservation_ledger_exists' => $reservationLedgerExists,
                'durable_claims_enabled' => $durableClaimsEnabled,
                'reservation_ledger_persists_rows' => $ledgerPersistsRows,
                'hot_work_pending' => $hotWorkPending,
            ],
            'blockers' => $blockers,
            'warnings' => $warnings,
            'safe_next_instruction' => $this->safeNextInstruction($decision, count($coldLanePackets), $reservationLedgerExists),
        ];
    }

    /**
     * Pure decision function mapping the consolidated signals to one of the five
     * documented decision values.
     */
    private function decide(
        bool $hardBlocked,
        int $coldLaneCount,
        bool $reservationLedgerExists,
        bool $durableClaimsEnabled,
        bool $ledgerPersistsRows,
    ): string {
        if ($hardBlocked) {
            return self::DECISION_BLOCKED;
        }

        // Future value: requires BOTH durable claims AND a reservation ledger that
        // actually persists rows. The contract says these do not exist yet, so in
        // practice this branch only fires if a caller explicitly asserts both.
        if ($durableClaimsEnabled && $ledgerPersistsRows) {
            return self::DECISION_READY_DURABLE_DISPATCH;
        }

        // Zero or one ready cold-lane packet -> at most a single scoped preview.
        if ($coldLaneCount <= 1) {
            return self::DECISION_PREVIEW_ONLY_SINGLE;
        }

        // Two+ disjoint, contract-bearing cold-lane packets exist. If the local
        // reservation ledger exists AND we have the five-packet threshold, this is
        // the expected `ready_for_multi_session_preview`. Otherwise parallel
        // preview is ready but not durable.
        if ($reservationLedgerExists && $coldLaneCount >= self::REQUIRED_COLD_LANE_PACKETS_FOR_MULTI_PREVIEW) {
            return self::DECISION_READY_MULTI_PREVIEW;
        }

        return self::DECISION_PARALLEL_PREVIEW_NOT_DURABLE;
    }

    private function safeNextInstruction(string $decision, int $coldLaneCount, bool $reservationLedgerExists): string
    {
        return match ($decision) {
            self::DECISION_BLOCKED => 'Resolve blockers before any multi-session preview; keep single read-only session if needed.',
            self::DECISION_PREVIEW_ONLY_SINGLE => 'Continue one session in read-only/scoped mode; do not open a second slot.',
            self::DECISION_PARALLEL_PREVIEW_NOT_DURABLE => $reservationLedgerExists
                ? 'Manually start disjoint packet-scoped preview sessions; reach five cold-lane packets for full multi-session preview.'
                : 'Stand up the local reservation ledger; until then keep parallel preview manual and non-durable.',
            self::DECISION_READY_MULTI_PREVIEW => 'Manually start up to five packet-scoped provider sessions with durable claims; automated dispatch stays off.',
            self::DECISION_READY_DURABLE_DISPATCH => 'Durable claims and reservation ledger asserted; still no auto-dispatch from this gate.',
            default => 'No safe instruction.',
        };
    }

    /**
     * Detect pairs of packets whose scope_paths overlap. Cold-lane parallel
     * preview is only safe when the selected packets are disjoint.
     *
     * @param  array<int, array<string,mixed>>  $packets
     * @return array<int, array{0:string,1:string}>
     */
    private function detectScopeCollisions(array $packets): array
    {
        $pairs = [];
        $count = count($packets);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if ($this->intersects($packets[$i]['scope_paths'], $packets[$j]['scope_paths'])) {
                    $pairs[] = [$packets[$i]['id'], $packets[$j]['id']];
                }
            }
        }

        return $pairs;
    }

    /**
     * @param  array<int,string>  $a
     * @param  array<int,string>  $b
     */
    private function intersects(array $a, array $b): bool
    {
        if ($a === [] || $b === []) {
            return false;
        }

        return array_intersect($a, $b) !== [];
    }

    /**
     * @param  array<int, array<string,mixed>>  $packets
     * @return array<int, array{id:string, lane:string, has_universal_contract:bool, scope_paths:array<int,string>, owns_hot_scope:bool, provider:string}>
     */
    private function normalizePackets(array $packets): array
    {
        $out = [];
        $index = 0;
        foreach ($packets as $packet) {
            if (! is_array($packet)) {
                continue;
            }
            $lane = strtolower((string) ($packet['lane'] ?? 'cold'));
            $out[] = [
                'id' => (string) ($packet['id'] ?? ('packet_'.$index)),
                'lane' => $lane === 'hot' ? 'hot' : 'cold',
                'has_universal_contract' => ($packet['has_universal_contract'] ?? false) === true,
                'scope_paths' => $this->normalizePaths($packet['scope_paths'] ?? []),
                'owns_hot_scope' => ($packet['owns_hot_scope'] ?? false) === true,
                'provider' => (string) ($packet['provider'] ?? 'unspecified'),
            ];
            $index++;
        }

        return $out;
    }

    /**
     * @param  mixed  $paths
     * @return array<int,string>
     */
    private function normalizePaths($paths): array
    {
        if (! is_array($paths)) {
            return [];
        }

        $clean = [];
        foreach ($paths as $path) {
            $value = trim((string) $path);
            if ($value !== '') {
                $clean[$value] = true;
            }
        }

        return array_keys($clean);
    }
}
