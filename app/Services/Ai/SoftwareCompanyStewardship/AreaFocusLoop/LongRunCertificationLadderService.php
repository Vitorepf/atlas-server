<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * AP-810 / LHL-19 — Certification Ladder Automation (owner AP-808/809).
 *
 * The single read-only judge that says HOW FAR up the long-run promotion ladder
 * the loop is actually allowed to climb. It answers one question per rung:
 *
 *   > Has the bundle/condition this rung requires been PROVEN, or is the loop
 *   > stuck here with a precise next blocker?
 *
 * Promotion is strictly sequential. The ladder climbs only while each lower rung
 * is `passed`; the moment a rung is `blocked` (its gate failed) or `pending`
 * (its gate not yet proven), every rung above it is `pending` and CANNOT pass.
 * Failure at a rung records `next_blocker` and stops promotion — nothing above a
 * stuck rung is ever dressed up as passed.
 *
 * This service is read-only / deterministic / input-seam driven. It NEVER runs
 * the loop, NEVER runs a simulation, NEVER invokes a provider, NEVER merges,
 * NEVER deletes a branch/worktree, NEVER mutates code. Every external fact (the
 * simulation report, chaos report, invariant report, AP-805 readiness, AP-806
 * autonomy, backlog depth, isolation status, real-cycle proofs, soak proofs) is
 * supplied via `$input[...]` seams; the diagnostic default analyzes an empty
 * state and never crashes.
 *
 * Honesty rules (operator does not accept false claims):
 *   - a simulated cycle is NEVER counted as a real cycle;
 *   - blocked is NEVER dressed as `ok`;
 *   - a rung above the first non-passed rung is ALWAYS `pending`, never passed;
 *   - skipping a rung is allowed ONLY with an explicit operator decision receipt
 *     (`skip_receipts`); an un-receipted skip is a hard block, never a free pass;
 *   - `--horizon` truncates the ladder to the requested horizon; truncation never
 *     fabricates a pass for a rung beyond the horizon.
 *
 * Also powers `loop-months-readiness` via `--horizon` (the ladder evaluated and
 * truncated to that horizon).
 *
 * Contract: AP-810 Promotion Gates table; AP-808/809 contracts; build contract
 * slice LHL-19.
 */
final class LongRunCertificationLadderService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.loop_certification_ladder.v1';

    public const STATUS_OK = 'ok';

    public const STATUS_BLOCKED = 'blocked';

    /** Per-rung status taxonomy. A rung is exactly one of these — never `success`/`ok`. */
    public const RUNG_PASSED = 'passed';

    public const RUNG_PENDING = 'pending';

    public const RUNG_BLOCKED = 'blocked';

    /** The 13 rungs, in EXACT promotion order (lowest first). */
    public const RUNG_TEN_SIMULATED = 'ten_simulated';

    public const RUNG_THOUSAND_SIMULATED = 'thousand_simulated';

    public const RUNG_CHAOS = 'chaos';

    public const RUNG_ONE_REAL = 'one_real';

    public const RUNG_THREE_PACKETS = 'three_packets';

    public const RUNG_TEN_REAL = 'ten_real';

    public const RUNG_TWO_HOURS = 'two_hours';

    public const RUNG_EIGHT_HOURS = 'eight_hours';

    public const RUNG_TWENTY_FOUR_HOURS = 'twenty_four_hours';

    public const RUNG_THREE_DAYS = 'three_days';

    public const RUNG_SEVEN_DAYS = 'seven_days';

    public const RUNG_FOURTEEN_DAYS = 'fourteen_days';

    public const RUNG_THIRTY_DAYS = 'thirty_days';

    /** Ordered ladder. Index = promotion height. */
    private const LADDER = [
        self::RUNG_TEN_SIMULATED,
        self::RUNG_THOUSAND_SIMULATED,
        self::RUNG_CHAOS,
        self::RUNG_ONE_REAL,
        self::RUNG_THREE_PACKETS,
        self::RUNG_TEN_REAL,
        self::RUNG_TWO_HOURS,
        self::RUNG_EIGHT_HOURS,
        self::RUNG_TWENTY_FOUR_HOURS,
        self::RUNG_THREE_DAYS,
        self::RUNG_SEVEN_DAYS,
        self::RUNG_FOURTEEN_DAYS,
        self::RUNG_THIRTY_DAYS,
    ];

    /**
     * Map of `--horizon` aliases to the topmost rung that horizon includes.
     * A horizon truncates the ladder at (and including) the mapped rung.
     */
    private const HORIZON_TOP_RUNG = [
        'two_hours' => self::RUNG_TWO_HOURS,
        '2h' => self::RUNG_TWO_HOURS,
        'eight_hours' => self::RUNG_EIGHT_HOURS,
        '8h' => self::RUNG_EIGHT_HOURS,
        'twenty_four_hours' => self::RUNG_TWENTY_FOUR_HOURS,
        '24h' => self::RUNG_TWENTY_FOUR_HOURS,
        '1d' => self::RUNG_TWENTY_FOUR_HOURS,
        'one_day' => self::RUNG_TWENTY_FOUR_HOURS,
        'three_days' => self::RUNG_THREE_DAYS,
        '3d' => self::RUNG_THREE_DAYS,
        'seven_days' => self::RUNG_SEVEN_DAYS,
        '7d' => self::RUNG_SEVEN_DAYS,
        'one_week' => self::RUNG_SEVEN_DAYS,
        'fourteen_days' => self::RUNG_FOURTEEN_DAYS,
        '14d' => self::RUNG_FOURTEEN_DAYS,
        'two_weeks' => self::RUNG_FOURTEEN_DAYS,
        'thirty_days' => self::RUNG_THIRTY_DAYS,
        '30d' => self::RUNG_THIRTY_DAYS,
        'one_month' => self::RUNG_THIRTY_DAYS,
        'months' => self::RUNG_THIRTY_DAYS,
    ];

    /**
     * Single entrypoint. Every key is optional; the diagnostic default analyzes a
     * clean/empty state (nothing proven => first rung blocked) and never crashes.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function evaluate(array $input = []): array
    {
        // A wiring-phase `fixture` (from --fixture-file) may carry the whole
        // composed bundle; fold it under the explicit input so direct keys win.
        $input = $this->mergeFixture($input);

        $area = trim((string) ($input['area'] ?? 'agentic_engineering_os')) ?: 'agentic_engineering_os';
        $focus = trim((string) ($input['focus'] ?? 'dev_forge')) ?: 'dev_forge';

        $horizonRaw = trim((string) ($input['horizon'] ?? ''));
        $horizonTopRung = $this->resolveHorizonTopRung($horizonRaw);

        // Pre-compute each rung's GATE truth from the input seams. `null` => not
        // yet proven (pending), `true` => gate satisfied, false => gate failed
        // (blocked). Sequential promotion is applied afterwards.
        $gateTruth = $this->gateTruth($input);
        $gateLabels = $this->gateLabels();

        /** @var array<string,array<string,mixed>> $skipReceipts */
        $skipReceipts = $this->indexSkipReceipts($input['skip_receipts'] ?? []);

        // The visible ladder is the full ladder truncated to the horizon (if any).
        $ladder = $this->ladderForHorizon($horizonTopRung);

        $rungs = [];
        $highestPassed = null;
        $firstNotPassed = null;
        $promotionOpen = true; // becomes false once a rung does not pass
        $globalNextBlocker = null;
        /** @var list<string> $skippedRungs rungs promoted past via operator receipt */
        $skippedRungs = [];

        foreach ($ladder as $rung) {
            $gate = $gateLabels[$rung];
            $reportRef = $this->reportRef($input, $rung);
            $truth = $gateTruth[$rung];

            $rungBlocker = null;
            $status = self::RUNG_PENDING;

            if (! $promotionOpen) {
                // Anything above the first non-passed rung is pending and CANNOT
                // pass — even if its own seam happens to be present. A higher rung
                // is only meaningful once everything below it is proven.
                $status = self::RUNG_PENDING;
                $rungBlocker = 'awaiting_lower_rung:'.($firstNotPassed ?? 'unknown');
            } elseif ($truth === true) {
                $status = self::RUNG_PASSED;
                $highestPassed = $rung;
            } else {
                // This rung did not pass on its own merits. A receipted operator
                // skip promotes past it; an un-receipted skip request is a HARD
                // block (never a free pass).
                $skip = $this->skipDecision($skipReceipts, $rung);
                if ($skip === 'skipped') {
                    $status = self::RUNG_PASSED;
                    $highestPassed = $rung;
                    $skippedRungs[] = $rung;
                    // Promotion stays open: the operator explicitly accepted the gap.
                } elseif ($skip === 'unreceipted_skip_requested') {
                    $status = self::RUNG_BLOCKED;
                    $rungBlocker = 'rung_skip_requested_without_operator_receipt';
                    $promotionOpen = false;
                    $firstNotPassed ??= $rung;
                    $globalNextBlocker ??= $rungBlocker;
                } elseif ($truth === false) {
                    $status = self::RUNG_BLOCKED;
                    $rungBlocker = $this->blockerFor($rung, $input);
                    $promotionOpen = false;
                    $firstNotPassed ??= $rung;
                    $globalNextBlocker ??= $rungBlocker;
                } else { // $truth === null — not yet proven
                    $status = self::RUNG_PENDING;
                    $rungBlocker = $this->pendingBlockerFor($rung);
                    $promotionOpen = false;
                    $firstNotPassed ??= $rung;
                    $globalNextBlocker ??= $rungBlocker;
                }
            }

            $rungs[] = [
                'rung' => $rung,
                'report_ref' => $reportRef,
                'gate' => $gate,
                'status' => $status,
                'next_blocker' => $rungBlocker,
            ];
        }

        // current_rung = the rung the loop is currently working to clear (the
        // first non-passed rung). If everything visible passed, the ladder is at
        // its top rung.
        $currentRung = $firstNotPassed ?? ($ladder !== [] ? $ladder[array_key_last($ladder)] : null);

        $allPassed = $firstNotPassed === null && $ladder !== [];
        $status = $allPassed ? self::STATUS_OK : self::STATUS_BLOCKED;

        $blocks = [
            self::RUNG_TEN_REAL => $this->rungReached($rungs, self::RUNG_TEN_REAL),
            '24h' => $this->rungReached($rungs, self::RUNG_TWENTY_FOUR_HOURS),
            '7d' => $this->rungReached($rungs, self::RUNG_SEVEN_DAYS),
            '30d' => $this->rungReached($rungs, self::RUNG_THIRTY_DAYS),
        ];

        $blockers = [];
        $warnings = [];
        if ($globalNextBlocker !== null) {
            $blockers[] = $globalNextBlocker;
        }
        if ($horizonRaw !== '' && $horizonTopRung === null) {
            $warnings[] = 'unrecognized_horizon_falling_back_to_full_ladder:'.$horizonRaw;
        }
        foreach ($skippedRungs as $skippedRung) {
            $warnings[] = 'rung_passed_by_operator_skip_receipt:'.$skippedRung;
        }

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-810',
            'slice_id' => 'LHL-19',
            'status' => $status,
            'ladder_id' => 'lcl_'.substr(MissionCanonicalHash::sha256([
                $area,
                $focus,
                $horizonTopRung ?? 'full',
                $this->ladderSignature($rungs),
            ]), 0, 16),
            'area' => $area,
            'focus' => $focus,
            'horizon' => $horizonRaw !== '' ? $horizonRaw : null,
            'horizon_top_rung' => $horizonTopRung,
            'checked_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'rungs' => $rungs,
            'current_rung' => $currentRung,
            'highest_passed' => $highestPassed,
            'next_blocker' => $globalNextBlocker,
            'blocks' => $blocks,
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
            'next_action' => $status === self::STATUS_OK ? 'continue' : 'stop_'.($currentRung ?? 'ladder'),
            'claim_policy' => [
                'read_only' => true,
                'runs_provider' => false,
                'runs_loop' => false,
                'runs_simulation' => false,
                'runs_merge' => false,
                'deletes_branches' => false,
                'simulated_never_counted_as_real' => true,
                'blocked_never_dressed_as_ready' => true,
            ],
        ];

        $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->withoutVolatile($payload));

        return $payload;
    }

    // ------------------------------------------------------------ gate truth

    /**
     * Resolve the GATE truth of every rung from the input seams.
     *   true  => gate proven satisfied
     *   false => gate explicitly failed (blocked)
     *   null  => gate not yet proven (pending)
     *
     * @param  array<string,mixed>  $input
     * @return array<string,bool|null>
     */
    private function gateTruth(array $input): array
    {
        $sim = is_array($input['simulation_report'] ?? null) ? $input['simulation_report'] : [];
        $chaos = is_array($input['chaos_report'] ?? null) ? $input['chaos_report'] : [];
        $invariant = is_array($input['invariant_report'] ?? null) ? $input['invariant_report'] : [];
        $ap805 = is_array($input['ap805_readiness'] ?? null) ? $input['ap805_readiness'] : [];
        $ap806 = is_array($input['ap806_autonomy'] ?? null) ? $input['ap806_autonomy'] : [];
        $isolation = is_array($input['isolation_status'] ?? null) ? $input['isolation_status'] : [];
        $real = is_array($input['real_cycles'] ?? null) ? $input['real_cycles'] : [];
        $soak = is_array($input['soak'] ?? null) ? $input['soak'] : [];
        $backlogDepth = $this->intOrNull($input['backlog_depth'] ?? ($real['backlog_depth'] ?? null));

        // --- Simulation rungs (NEVER real). ---
        $simCount = $this->intOrNull($sim['simulated_cycles'] ?? ($sim['count'] ?? null));
        $simHonest = $this->boolGate($sim, ['ok', 'honest', 'passed'], true);
        $tenSimulated = $this->countGate($simCount, 10, $simHonest);
        $thousandSimulated = $this->countGate($simCount, 1000, $simHonest);

        // --- Chaos rung: chaos suite passed AND invariants held under chaos. ---
        $chaosTruth = $this->boolGate($chaos, ['ok', 'passed', 'survived'], false);
        $invariantTruth = $this->boolGate($invariant, ['ok', 'passed', 'all_held', 'held'], false);
        if ($chaosTruth === null && $invariantTruth === null) {
            $chaos = null; // both unproven
        }
        $chaosRung = $this->andGate($chaosTruth, $invariantTruth);

        // --- AP-805 readiness + AP-806 autonomy gate the first REAL cycle. ---
        $readiness = $this->statusGate($ap805, ['ready'], ['blocked', 'partial']);
        $autonomy = $this->boolGate($ap806, ['armed', 'authorized', 'ok', 'ready'], false);
        $isolationOk = $this->boolGate($isolation, ['ok', 'isolated', 'clean'], false);

        // --- Real-cycle rungs (proven merges of bounded packets, never simulated). ---
        $realMerged = $this->intOrNull($real['real_merges'] ?? ($real['merged'] ?? ($real['proven_real_cycles'] ?? null)));
        $distinctPackets = $this->intOrNull($real['distinct_packets_merged'] ?? ($real['distinct_packets'] ?? null));
        // The first real cycle additionally requires readiness+autonomy+isolation+chaos proven.
        $realFoundation = $this->allTrue([$chaosRung, $readiness, $autonomy, $isolationOk]);
        $oneReal = $this->gatedCount($realMerged, 1, $realFoundation);
        $threePackets = $this->gatedCount($distinctPackets ?? $realMerged, 3, $realFoundation);
        $tenReal = $this->gatedCount($realMerged, 10, $realFoundation);

        // --- Soak rungs (continuous wall-clock survival). ---
        // Hours and days are two views of one continuous-soak fact; derive either
        // from the other so a fixture may state just one of them.
        $soakHours = $this->floatOrNull($soak['hours'] ?? ($soak['continuous_hours'] ?? null));
        $soakDays = $this->floatOrNull($soak['days'] ?? ($soak['continuous_days'] ?? null));
        if ($soakDays === null && $soakHours !== null) {
            $soakDays = $soakHours / 24.0;
        }
        if ($soakHours === null && $soakDays !== null) {
            $soakHours = $soakDays * 24.0;
        }
        $soakHonest = $this->boolGate($soak, ['ok', 'honest', 'no_filler', 'productive'], true);

        $twoHours = $this->durationGate($soakHours, 2.0, $soakHonest);
        $eightHours = $this->durationGate($soakHours, 8.0, $soakHonest);
        $twentyFour = $this->durationGate($soakHours, 24.0, $soakHonest);
        $threeDays = $this->durationGate($soakDays, 3.0, $soakHonest);
        $sevenDays = $this->durationGate($soakDays, 7.0, $soakHonest);
        $fourteenDays = $this->durationGate($soakDays, 14.0, $soakHonest);
        $thirtyDays = $this->durationGate($soakDays, 30.0, $soakHonest);

        // Backlog depth: a long soak rung cannot pass on filler/recovery once the
        // backlog is exhausted. If backlog_depth is provided and zero, soak rungs
        // beyond the proven point are not real productivity.
        if ($backlogDepth !== null && $backlogDepth <= 0) {
            $twoHours = $this->demoteIfNotProven($twoHours);
            $eightHours = $this->demoteIfNotProven($eightHours);
            $twentyFour = $this->demoteIfNotProven($twentyFour);
            $threeDays = $this->demoteIfNotProven($threeDays);
            $sevenDays = $this->demoteIfNotProven($sevenDays);
            $fourteenDays = $this->demoteIfNotProven($fourteenDays);
            $thirtyDays = $this->demoteIfNotProven($thirtyDays);
        }

        return [
            self::RUNG_TEN_SIMULATED => $tenSimulated,
            self::RUNG_THOUSAND_SIMULATED => $thousandSimulated,
            self::RUNG_CHAOS => $chaosRung,
            self::RUNG_ONE_REAL => $oneReal,
            self::RUNG_THREE_PACKETS => $threePackets,
            self::RUNG_TEN_REAL => $tenReal,
            self::RUNG_TWO_HOURS => $twoHours,
            self::RUNG_EIGHT_HOURS => $eightHours,
            self::RUNG_TWENTY_FOUR_HOURS => $twentyFour,
            self::RUNG_THREE_DAYS => $threeDays,
            self::RUNG_SEVEN_DAYS => $sevenDays,
            self::RUNG_FOURTEEN_DAYS => $fourteenDays,
            self::RUNG_THIRTY_DAYS => $thirtyDays,
        ];
    }

    /**
     * Human-readable gate descriptions per rung (the bundle/condition required).
     *
     * @return array<string,string>
     */
    private function gateLabels(): array
    {
        return [
            self::RUNG_TEN_SIMULATED => '>=10 honest simulated cycles',
            self::RUNG_THOUSAND_SIMULATED => '>=1000 honest simulated cycles',
            self::RUNG_CHAOS => 'chaos suite survived AND invariants held under chaos',
            self::RUNG_ONE_REAL => '1 real merged cycle (AP-805 ready + AP-806 autonomy + isolation + chaos proven)',
            self::RUNG_THREE_PACKETS => '3 distinct bounded packets merged for real',
            self::RUNG_TEN_REAL => '10 real merged cycles',
            self::RUNG_TWO_HOURS => '2h continuous productive soak',
            self::RUNG_EIGHT_HOURS => '8h continuous productive soak',
            self::RUNG_TWENTY_FOUR_HOURS => '24h continuous productive soak',
            self::RUNG_THREE_DAYS => '3d continuous productive soak',
            self::RUNG_SEVEN_DAYS => '7d continuous productive soak',
            self::RUNG_FOURTEEN_DAYS => '14d continuous productive soak',
            self::RUNG_THIRTY_DAYS => '30d continuous productive soak',
        ];
    }

    // ------------------------------------------------------------ blockers

    /**
     * Precise next-blocker string for a rung whose gate explicitly FAILED.
     *
     * @param  array<string,mixed>  $input
     */
    private function blockerFor(string $rung, array $input): string
    {
        return match ($rung) {
            self::RUNG_TEN_SIMULATED => 'need_10_honest_simulated_cycles',
            self::RUNG_THOUSAND_SIMULATED => 'need_1000_honest_simulated_cycles',
            self::RUNG_CHAOS => 'chaos_or_invariant_gate_failed',
            self::RUNG_ONE_REAL => 'no_proven_real_merged_cycle_with_readiness_autonomy_isolation_chaos',
            self::RUNG_THREE_PACKETS => 'need_3_distinct_real_merged_packets',
            self::RUNG_TEN_REAL => 'need_10_real_merged_cycles',
            self::RUNG_TWO_HOURS => 'need_2h_continuous_productive_soak',
            self::RUNG_EIGHT_HOURS => 'need_8h_continuous_productive_soak',
            self::RUNG_TWENTY_FOUR_HOURS => 'need_24h_continuous_productive_soak',
            self::RUNG_THREE_DAYS => 'need_3d_continuous_productive_soak',
            self::RUNG_SEVEN_DAYS => 'need_7d_continuous_productive_soak',
            self::RUNG_FOURTEEN_DAYS => 'need_14d_continuous_productive_soak',
            self::RUNG_THIRTY_DAYS => 'need_30d_continuous_productive_soak',
            default => 'rung_gate_failed:'.$rung,
        };
    }

    /** Precise next-blocker string for a rung whose gate is not yet proven (pending). */
    private function pendingBlockerFor(string $rung): string
    {
        return match ($rung) {
            self::RUNG_TEN_SIMULATED, self::RUNG_THOUSAND_SIMULATED => 'simulation_report_absent',
            self::RUNG_CHAOS => 'chaos_or_invariant_report_absent',
            self::RUNG_ONE_REAL, self::RUNG_THREE_PACKETS, self::RUNG_TEN_REAL => 'real_cycle_proof_absent',
            default => 'soak_proof_absent',
        };
    }

    // ------------------------------------------------------------ skip receipts

    /**
     * Index operator skip decision receipts by rung. Each receipt must carry an
     * actor and a decision; only an explicit `skip`/`waive` decision with an
     * actor counts as an operator-accepted skip.
     *
     * @param  mixed  $receipts
     * @return array<string,array<string,mixed>>
     */
    private function indexSkipReceipts(mixed $receipts): array
    {
        $indexed = [];
        if (! is_array($receipts)) {
            return $indexed;
        }
        foreach ($receipts as $key => $row) {
            if (! is_array($row)) {
                // A bare `'ten_real' => true` shorthand is NOT a valid receipt
                // (no actor/decision) — record it so it can be refused.
                if (is_string($key)) {
                    $indexed[$key] = ['rung' => $key, 'decision' => 'shorthand', 'actor' => ''];
                }

                continue;
            }
            $rung = trim((string) ($row['rung'] ?? (is_string($key) ? $key : '')));
            if ($rung === '') {
                continue;
            }
            $indexed[$rung] = $row;
        }

        return $indexed;
    }

    /**
     * Resolve whether a rung's skip is operator-accepted.
     *   'skipped'                    => valid operator receipt accepted the gap
     *   'unreceipted_skip_requested' => a skip was REQUESTED but no valid receipt
     *   null                         => no skip requested for this rung
     *
     * @param  array<string,array<string,mixed>>  $skipReceipts
     */
    private function skipDecision(array $skipReceipts, string $rung): ?string
    {
        if (! array_key_exists($rung, $skipReceipts)) {
            return null;
        }
        $receipt = $skipReceipts[$rung];
        $actor = trim((string) ($receipt['actor'] ?? ($receipt['decided_by'] ?? '')));
        $decision = strtolower(trim((string) ($receipt['decision'] ?? '')));
        $accepted = in_array($decision, ['skip', 'waive', 'accept', 'approved', 'allow'], true);

        if ($accepted && $actor !== '') {
            return 'skipped';
        }

        return 'unreceipted_skip_requested';
    }

    // ------------------------------------------------------------ horizon

    private function resolveHorizonTopRung(string $horizon): ?string
    {
        if ($horizon === '') {
            return null;
        }
        $key = strtolower(str_replace([' ', '-'], '_', $horizon));

        return self::HORIZON_TOP_RUNG[$key] ?? (self::HORIZON_TOP_RUNG[$horizon] ?? null);
    }

    /**
     * The visible ladder truncated to (and including) the horizon top rung.
     * An unrecognized horizon falls back to the full ladder.
     *
     * @return list<string>
     */
    private function ladderForHorizon(?string $horizonTopRung): array
    {
        if ($horizonTopRung === null) {
            return self::LADDER;
        }
        $out = [];
        foreach (self::LADDER as $rung) {
            $out[] = $rung;
            if ($rung === $horizonTopRung) {
                break;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------ gate helpers

    /**
     * Count gate: $count present and >= $threshold and $honest !== false => true;
     * $count present but short, or $honest === false => false; $count absent => null.
     */
    private function countGate(?int $count, int $threshold, ?bool $honest): ?bool
    {
        if ($honest === false) {
            return false;
        }
        if ($count === null) {
            return null;
        }

        return $count >= $threshold;
    }

    /**
     * Gated count: like countGate but also requires a precondition bundle to be
     * proven `true`. If the precondition is false/null, the rung cannot pass.
     */
    private function gatedCount(?int $count, int $threshold, ?bool $precondition): ?bool
    {
        if ($precondition === false) {
            return false;
        }
        if ($count === null) {
            return $precondition === true ? null : ($precondition === null ? null : false);
        }
        if ($count < $threshold) {
            return false;
        }

        return $precondition === true ? true : ($precondition === null ? null : false);
    }

    /** Duration gate over hours/days. */
    private function durationGate(?float $value, float $threshold, ?bool $honest): ?bool
    {
        if ($honest === false) {
            return false;
        }
        if ($value === null) {
            return null;
        }

        return $value + 1e-9 >= $threshold;
    }

    /** Logical AND over three-valued gates (false dominates, then null, else true). */
    private function andGate(?bool $a, ?bool $b): ?bool
    {
        if ($a === false || $b === false) {
            return false;
        }
        if ($a === null || $b === null) {
            return null;
        }

        return true;
    }

    /**
     * AND over a list of three-valued gates (false dominates, then null).
     *
     * @param  list<bool|null>  $gates
     */
    private function allTrue(array $gates): ?bool
    {
        $sawNull = false;
        foreach ($gates as $g) {
            if ($g === false) {
                return false;
            }
            if ($g === null) {
                $sawNull = true;
            }
        }

        return $sawNull ? null : true;
    }

    /** A passed gate that is downgraded to "not proven" demotes a soak rung. */
    private function demoteIfNotProven(?bool $gate): ?bool
    {
        // Only demote a `true` that rests purely on duration without backlog; a
        // genuinely false gate stays false, a null stays null.
        return $gate === true ? false : $gate;
    }

    /**
     * Boolean gate: read the first present truthy/known flag from $keys on $report.
     * Returns null when the report itself is empty/absent (unproven).
     *
     * @param  array<string,mixed>|null  $report
     * @param  list<string>  $keys
     */
    private function boolGate(?array $report, array $keys, bool $treatMissingFlagAsTrue): ?bool
    {
        if ($report === null || $report === []) {
            return null;
        }
        foreach ($keys as $k) {
            if (array_key_exists($k, $report)) {
                return (bool) $report[$k];
            }
        }
        // The report exists but carries none of the known flags. For an honesty
        // flag (treatMissingFlagAsTrue) assume honest; otherwise unproven.
        return $treatMissingFlagAsTrue ? true : null;
    }

    /**
     * Status gate: a report whose `status` is in $okValues => true; in $blockValues
     * => false; report absent/empty => null.
     *
     * @param  array<string,mixed>|null  $report
     * @param  list<string>  $okValues
     * @param  list<string>  $blockValues
     */
    private function statusGate(?array $report, array $okValues, array $blockValues): ?bool
    {
        if ($report === null || $report === []) {
            return null;
        }
        if (array_key_exists('ready', $report) && is_bool($report['ready'])) {
            return $report['ready'];
        }
        $status = strtolower(trim((string) ($report['status'] ?? '')));
        if ($status === '') {
            return null;
        }
        if (in_array($status, $okValues, true)) {
            return true;
        }
        if (in_array($status, $blockValues, true)) {
            return false;
        }

        return null;
    }

    // ------------------------------------------------------------ small helpers

    /** @param  array<int,array<string,mixed>>  $rungs */
    private function rungReached(array $rungs, string $target): bool
    {
        foreach ($rungs as $r) {
            if (($r['rung'] ?? null) === $target) {
                return ($r['status'] ?? null) === self::RUNG_PASSED;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function reportRef(array $input, string $rung): ?string
    {
        $refs = is_array($input['report_refs'] ?? null) ? $input['report_refs'] : [];
        $ref = $refs[$rung] ?? null;

        return $this->nullableString($ref);
    }

    /** @param  array<int,array<string,mixed>>  $rungs */
    private function ladderSignature(array $rungs): string
    {
        $sig = [];
        foreach ($rungs as $r) {
            $sig[] = ($r['rung'] ?? '').':'.($r['status'] ?? '').':'.((string) ($r['next_blocker'] ?? ''));
        }

        return implode('|', $sig);
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '' || is_bool($value)) {
            return is_bool($value) ? null : null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    private function floatOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '' || is_bool($value)) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * A wiring-phase `fixture` may carry the whole composed bundle; fold it under
     * the explicit input so direct keys still take precedence (input-seam
     * composition mirroring TenCycleReadinessGovernorService).
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function mergeFixture(array $input): array
    {
        $fixture = $input['fixture'] ?? null;
        if (! is_array($fixture) || $fixture === []) {
            return $input;
        }
        unset($input['fixture']);

        return array_merge($fixture, $input);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function withoutVolatile(array $payload): array
    {
        unset($payload['checked_at'], $payload['report_hash']);

        return $payload;
    }
}
