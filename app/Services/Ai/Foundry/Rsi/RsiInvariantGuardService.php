<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry\Rsi;

use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * Governed RSI · Part A (Build-Safety) · Invariant Guard (fail-closed).
 *
 * The mandatory PRE-GATE that screens every Recursive Self-Improvement proposal
 * BEFORE it can reach the operator's human gate. It is the single barrier that
 * keeps the loop from improving its own machinery in a way that weakens a sacred
 * gate. It consumes ImmutableInvariantRegistryService (the frozen sacred set)
 * and FAILS CLOSED:
 *
 *   - A proposal whose diff ADDS / MODIFIES / DELETES any guarded sacred path is
 *     AUTO-REJECTED (verdict=rejected, reason=sacred_path_touched) with the exact
 *     gate id(s) and path. It NEVER reaches the human gate.
 *   - A proposal whose diff REMOVES a gate's weakening signature (e.g. drops the
 *     `providerCalls > 0` check) is AUTO-REJECTED (reason=invariant_weakened).
 *   - A proposal that adds an eligibility override / auto-canonization /
 *     auto-apply signal is AUTO-REJECTED (reason=eligibility_override_added).
 *   - A proposal touching ONLY non-sacred components (e.g. the decomposer
 *     heuristic) PASSES the guard (verdict=passed) — but it is still
 *     PROPOSAL-ONLY: passed never means applied, it means "may be routed to the
 *     operator's existing human gate". The guard NEVER applies, NEVER canonizes,
 *     NEVER calls a provider.
 *
 * The guard is itself self-protecting: because the registry lists its own file
 * and the guard's file under GATE_REGISTRY_SELF, a proposal editing the guard or
 * the registry is rejected like any other sacred touch.
 *
 * Read-only, deterministic, seam-driven. No provider, no state write, no apply.
 */
final class RsiInvariantGuardService
{
    public const SCREENING_SCHEMA = 'atlas.foundry.rsi.invariant_guard_screening.v1';

    public const VERDICT_PASSED = 'passed';

    public const VERDICT_REJECTED = 'rejected';

    public const REASON_SACRED_PATH_TOUCHED = 'sacred_path_touched';

    public const REASON_INVARIANT_WEAKENED = 'invariant_weakened';

    public const REASON_ELIGIBILITY_OVERRIDE_ADDED = 'eligibility_override_added';

    public const REASON_AUTO_CANONIZE_ATTEMPTED = 'auto_canonize_attempted';

    public const REASON_DIFF_MALFORMED = 'diff_malformed';

    /**
     * Auto-canonization / auto-apply intent signatures. If a proposal's diff
     * introduces any of these in ANY file (sacred or not), the proposal is trying
     * to skip the human gate => auto-reject. Matched case-insensitively against
     * added diff lines.
     *
     * @var list<string>
     */
    private const AUTO_CANONIZE_SIGNATURES = [
        'auto_apply',
        'auto_canonize',
        'auto_canonical',
        'autocanonize',
        'auto_merge',
        'auto_promote',
        'skip_human_gate',
        'bypass_operator',
        'requires_operator_review = false',
        "'requires_operator_review' => false",
        '"requires_operator_review" => false',
    ];

    /**
     * Eligibility-override signatures. The I8 exhaustion / proposal-only gates may
     * never be opened by a flag a proposal adds to itself.
     *
     * @var list<string>
     */
    private const ELIGIBILITY_OVERRIDE_SIGNATURES = [
        'force_eligible',
        'force_promote',
        'override_invariant',
        'disable_invariant_guard',
        'bypass_invariant',
        'rsi_unsafe_mode',
        'allow_sacred_edit',
    ];

    public function __construct(
        private readonly ImmutableInvariantRegistryService $registry,
    ) {}

    /**
     * Screen a self-improvement proposal diff. Fails closed.
     *
     * The diff descriptor must carry `changed_paths` (list of repo-relative or
     * absolute file paths the proposal would touch). Optional `removed_lines`
     * (map of path => list<string> of lines the diff DELETES) lets the guard
     * detect a weakening edit that removes a sacred check; optional `added_lines`
     * (list<string> across the whole diff) lets it detect auto-canonize /
     * eligibility-override intent. A descriptor with no usable changed_paths
     * fails closed (rejected, diff_malformed) — an unreadable diff is never
     * treated as safe.
     *
     * @param  array<string,mixed>  $diff
     * @return array<string,mixed>
     */
    public function screen(array $diff): array
    {
        $changedPaths = $this->normalizePathList($diff['changed_paths'] ?? null);

        // Fail closed: an empty / unreadable change set is never "safe to pass".
        if ($changedPaths === []) {
            return $this->emit(
                verdict: self::VERDICT_REJECTED,
                reasons: [$this->reason(self::REASON_DIFF_MALFORMED, '', '', 'diff carries no readable changed_paths; fail-closed')],
                touchedSacredPaths: [],
                touchedGates: [],
            );
        }

        $reasons = [];
        $touchedSacredPaths = [];
        $touchedGates = [];

        // (1) Any sacred path touched at all (add/modify/delete) => reject.
        foreach ($changedPaths as $path) {
            if ($this->registry->isSacredPath($path)) {
                $gates = $this->registry->gatesForPath($path);
                $touchedSacredPaths[] = $path;
                foreach ($gates as $g) {
                    $touchedGates[$g] = true;
                    $reasons[] = $this->reason(
                        self::REASON_SACRED_PATH_TOUCHED,
                        $g,
                        $path,
                        "proposal diff touches sacred path owned by gate '{$g}'",
                    );
                }
            }
        }

        // (2) Weakening edit: a removed line that strips a gate's weakening
        // signature out of a sacred file. (Sacred-path-touched already rejects,
        // but this records the PRECISE invariant being weakened for the receipt.)
        $removedLines = is_array($diff['removed_lines'] ?? null) ? $diff['removed_lines'] : [];
        foreach ($removedLines as $path => $lines) {
            if (! is_string($path) || ! is_array($lines)) {
                continue;
            }
            if (! $this->registry->isSacredPath($path)) {
                continue;
            }
            $joined = strtolower(implode("\n", array_filter($lines, 'is_string')));
            foreach ($this->registry->gatesForPath($path) as $gateId) {
                foreach ($this->registry->weakeningSignaturesFor($gateId) as $sig) {
                    if ($sig !== '' && str_contains($joined, strtolower($sig))) {
                        $touchedGates[$gateId] = true;
                        $reasons[] = $this->reason(
                            self::REASON_INVARIANT_WEAKENED,
                            $gateId,
                            $path,
                            "proposal removes sacred check '{$sig}' from gate '{$gateId}'",
                        );
                    }
                }
            }
        }

        // (3) Auto-canonize / auto-apply intent in ANY added line => reject.
        $addedLines = $this->collectAddedLines($diff['added_lines'] ?? null);
        $addedJoined = strtolower(implode("\n", $addedLines));
        foreach (self::AUTO_CANONIZE_SIGNATURES as $sig) {
            if (str_contains($addedJoined, strtolower($sig))) {
                $reasons[] = $this->reason(
                    self::REASON_AUTO_CANONIZE_ATTEMPTED,
                    ImmutableInvariantRegistryService::GATE_PROPOSAL_ONLY_GATING,
                    '',
                    "proposal introduces auto-apply / auto-canonize intent '{$sig}'; proposal-only is sacred",
                );
            }
        }

        // (4) Eligibility-override intent => reject.
        foreach (self::ELIGIBILITY_OVERRIDE_SIGNATURES as $sig) {
            if (str_contains($addedJoined, strtolower($sig))) {
                $reasons[] = $this->reason(
                    self::REASON_ELIGIBILITY_OVERRIDE_ADDED,
                    ImmutableInvariantRegistryService::GATE_EXHAUSTION_RARITY,
                    '',
                    "proposal introduces eligibility / invariant override '{$sig}'",
                );
            }
        }

        if ($reasons !== []) {
            return $this->emit(
                verdict: self::VERDICT_REJECTED,
                reasons: $reasons,
                touchedSacredPaths: array_values(array_unique($touchedSacredPaths)),
                touchedGates: array_keys($touchedGates),
            );
        }

        // Clean: touches only non-sacred components. PASSED is still proposal-only.
        return $this->emit(
            verdict: self::VERDICT_PASSED,
            reasons: [],
            touchedSacredPaths: [],
            touchedGates: [],
        );
    }

    /**
     * @param  list<array<string,string>>  $reasons
     * @param  list<string>  $touchedSacredPaths
     * @param  list<string>  $touchedGates
     * @return array<string,mixed>
     */
    private function emit(string $verdict, array $reasons, array $touchedSacredPaths, array $touchedGates): array
    {
        $payload = [
            'schema_version' => self::SCREENING_SCHEMA,
            'verdict' => $verdict,
            'rejected' => $verdict === self::VERDICT_REJECTED,
            // PASSED never means applied — the guard is a pre-gate, not an authority.
            'proposal_only' => true,
            'human_gate_required' => true,
            'auto_applied' => false,
            'reasons' => $reasons,
            'touched_sacred_paths' => $touchedSacredPaths,
            'touched_gates' => $touchedGates,
            'registry_hash' => $this->registry->registry()['registry_hash'],
            'provider_invoked' => false,
        ];

        $payload['screening_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,string>
     */
    private function reason(string $code, string $gateId, string $path, string $detail): array
    {
        return ['code' => $code, 'gate_id' => $gateId, 'path' => $path, 'detail' => $detail];
    }

    /**
     * @return list<string>
     */
    private function normalizePathList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $p) {
            if (is_string($p) && trim($p) !== '') {
                $out[] = trim($p);
            }
        }

        return array_values($out);
    }

    /**
     * @return list<string>
     */
    private function collectAddedLines(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $line) {
            if (is_string($line)) {
                $out[] = $line;
            }
        }

        return $out;
    }
}
