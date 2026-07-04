<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Decides what the external brain must do instead of stopping when a low-yield wave is detected.
 *
 * ESCALATION LADDER (in order — each mode unlocks only when the prior one has recorded evidence):
 *   1. contract_mismatch          — second-pass hunt for interface/implementation contract drift
 *   2. cross_domain_pattern       — transfer patterns proven in one domain to analogous gaps elsewhere
 *   3. research_backed_design     — ground the next proposal in arXiv / OSS research evidence
 *   4. architecture_simplification — find complexity removed from the critical path without behaviour loss
 *   5. runtime_health             — instrument or improve observability, error recovery, circuit-breakers
 *   6. certification_gap          — find the next slice that is implemented but not yet certified/gated
 *
 * HONEST_EXHAUSTED — only returned after all six modes carry recorded evidence.
 *
 * PURE / DETERMINISTIC. No I/O.
 */
final class AtlasExternalBrainAmbitionEscalationPolicy
{
    public const SCHEMA = 'atlas.external_brain.ambition_escalation_policy.v1';

    public const MODE_CONTRACT_MISMATCH           = 'contract_mismatch';
    public const MODE_CROSS_DOMAIN_PATTERN        = 'cross_domain_pattern';
    public const MODE_RESEARCH_BACKED_DESIGN      = 'research_backed_design';
    public const MODE_ARCHITECTURE_SIMPLIFICATION = 'architecture_simplification';
    public const MODE_RUNTIME_HEALTH              = 'runtime_health';
    public const MODE_CERTIFICATION_GAP           = 'certification_gap';
    public const MODE_HONEST_EXHAUSTED            = 'honest_exhausted';

    /** Ordered escalation ladder — modes tried in this sequence. */
    private const LADDER = [
        self::MODE_CONTRACT_MISMATCH,
        self::MODE_CROSS_DOMAIN_PATTERN,
        self::MODE_RESEARCH_BACKED_DESIGN,
        self::MODE_ARCHITECTURE_SIMPLIFICATION,
        self::MODE_RUNTIME_HEALTH,
        self::MODE_CERTIFICATION_GAP,
    ];

    public const ACTION_ESCALATE_STRATEGY = 'escalate_strategy';

    public const CATEGORY_ARCHITECTURE           = 'architecture';
    public const CATEGORY_SIMPLIFICATION         = 'simplification';
    public const CATEGORY_RESEARCH               = 'research';
    public const CATEGORY_TASK_FABRIC_HARDENING  = 'task_fabric_hardening';

    /**
     * Every ladder mode belongs to exactly one broad escalation category — the vocabulary the
     * mission narrates in ("architecture, simplification, research or task-fabric upgrades")
     * without renaming the ladder modes existing consumers already key on.
     */
    private const MODE_CATEGORY = [
        self::MODE_CONTRACT_MISMATCH           => self::CATEGORY_TASK_FABRIC_HARDENING,
        self::MODE_CROSS_DOMAIN_PATTERN        => self::CATEGORY_TASK_FABRIC_HARDENING,
        self::MODE_RESEARCH_BACKED_DESIGN      => self::CATEGORY_RESEARCH,
        self::MODE_ARCHITECTURE_SIMPLIFICATION => self::CATEGORY_ARCHITECTURE,
        self::MODE_RUNTIME_HEALTH              => self::CATEGORY_TASK_FABRIC_HARDENING,
        self::MODE_CERTIFICATION_GAP           => self::CATEGORY_SIMPLIFICATION,
    ];

    /**
     * Decide the next action for a brain that detected a low-yield wave.
     *
     * @param  array{
     *     attempted_modes?:list<string>,
     *     evidence_by_mode?:array<string,list<string>>,
     *     wave_number?:int,
     *     wave_yield?:float,
     * }  $state  Current escalation state — what modes have been attempted and what evidence exists.
     *
     * @return array{
     *     schema:string,
     *     next_mode:string,
     *     rationale:string,
     *     modes_remaining:list<string>,
     *     modes_with_evidence:list<string>,
     *     honest_exhausted:bool
     * }
     */
    public function decide(array $state): array
    {
        $attempted    = array_values(array_filter(array_map('strval', (array) ($state['attempted_modes'] ?? [])), static fn (string $m): bool => $m !== ''));
        $evidenceMap  = is_array($state['evidence_by_mode'] ?? null) ? $state['evidence_by_mode'] : [];

        // Modes that have been attempted AND carry at least one evidence entry.
        $withEvidence = array_values(array_filter(self::LADDER, static function (string $m) use ($evidenceMap): bool {
            $refs = is_array($evidenceMap[$m] ?? null) ? array_values($evidenceMap[$m]) : [];
            return $refs !== [];
        }));

        // Next mode = first in the ladder that has NOT yet been attempted.
        $next = null;
        foreach (self::LADDER as $mode) {
            if (! in_array($mode, $attempted, true)) {
                $next = $mode;
                break;
            }
        }

        // All modes attempted AND all carry evidence → honest_exhausted.
        $allAttempted   = array_diff(self::LADDER, $attempted) === [];
        $allHaveEvidence = count($withEvidence) === count(self::LADDER);

        // AC2: exhaustion_dossier — always present; proves every mode's evidence status.
        $dossier = $this->buildDossier($attempted, $evidenceMap);

        if ($next === null && $allAttempted && $allHaveEvidence) {
            return $this->envelope(self::MODE_HONEST_EXHAUSTED, 'all_escalation_modes_attempted_with_evidence', [], $withEvidence, true, $dossier);
        }

        // If all modes are attempted but not all carry evidence, re-run the first mode lacking evidence.
        $missingEvidenceRequeue = false;
        if ($next === null) {
            foreach (self::LADDER as $mode) {
                $refs = is_array($evidenceMap[$mode] ?? null) ? array_values($evidenceMap[$mode]) : [];
                if ($refs === []) {
                    $next = $mode;
                    $missingEvidenceRequeue = true;
                    break;
                }
            }
        }

        // Fallback (should not occur, but keeps the type system happy).
        $next ??= self::LADDER[0];

        $rationale = $missingEvidenceRequeue
            ? "mode_lacks_evidence:{$next}:re_run_required_before_honest_exhausted_is_allowed"
            : $this->rationale($next);

        $remaining = array_values(array_filter(self::LADDER, static fn (string $m): bool => ! in_array($m, $attempted, true) && $m !== $next));

        return $this->envelope($next, $rationale, $remaining, $withEvidence, false, $dossier);
    }

    /** @param list<string> $remaining @param list<string> $withEvidence @param list<array<string,mixed>> $dossier */
    private function envelope(string $next, string $rationale, array $remaining, array $withEvidence, bool $exhausted, array $dossier): array
    {
        $escalationMoves = $this->deriveEscalationMoves($next, $remaining, $withEvidence, $exhausted);
        $stopAllowed = $exhausted && count($withEvidence) === count(self::LADDER);

        return [
            'schema'              => self::SCHEMA,
            'next_mode'           => $next,
            'rationale'           => $rationale,
            'modes_remaining'     => $remaining,
            'modes_with_evidence' => $withEvidence,
            'honest_exhausted'    => $exhausted,
            'exhaustion_dossier'  => $dossier,
            'action'              => $exhausted ? self::MODE_HONEST_EXHAUSTED : self::ACTION_ESCALATE_STRATEGY,
            'escalation_category' => self::MODE_CATEGORY[$next] ?? null,
            'escalation_moves'    => $escalationMoves,
            'stop_allowed'        => $stopAllowed,
        ];
    }

    /**
     * Derive concrete escalation moves from the current mode and remaining options.
     *
     * @return list<string>
     */
    private function deriveEscalationMoves(string $next, array $remaining, array $withEvidence, bool $exhausted): array
    {
        if ($exhausted) {
            return [];
        }

        $moves = [];

        // Map modes to escalation move types
        $modeToMove = [
            self::MODE_CONTRACT_MISMATCH           => 'deeper_probe',
            self::MODE_CROSS_DOMAIN_PATTERN        => 'research_transfer',
            self::MODE_RESEARCH_BACKED_DESIGN      => 'research_transfer',
            self::MODE_ARCHITECTURE_SIMPLIFICATION => 'simplification_path',
            self::MODE_RUNTIME_HEALTH              => 'deeper_probe',
            self::MODE_CERTIFICATION_GAP           => 'counterfactual_review',
        ];

        // Current mode's move
        if (isset($modeToMove[$next])) {
            $moves[] = $modeToMove[$next];
        }

        // Remaining modes' moves
        foreach ($remaining as $r) {
            if (isset($modeToMove[$r])) {
                $moves[] = $modeToMove[$r];
            }
        }

        return array_values(array_unique($moves));
    }

    /**
     * Build a per-mode dossier listing attempted status, evidence list, and a deterministic evidence hash.
     *
     * @param  list<string>                $attempted
     * @param  array<string,list<string>>  $evidenceMap
     * @return list<array<string,mixed>>
     */
    private function buildDossier(array $attempted, array $evidenceMap): array
    {
        $dossier = [];
        foreach (self::LADDER as $mode) {
            $refs    = is_array($evidenceMap[$mode] ?? null) ? array_values($evidenceMap[$mode]) : [];
            $dossier[] = [
                'mode'           => $mode,
                'attempted'      => in_array($mode, $attempted, true),
                'evidence'       => $refs,
                'evidence_hash'  => hash('sha256', (string) json_encode($refs)),
            ];
        }
        return $dossier;
    }

    private function rationale(string $mode): string
    {
        return match ($mode) {
            self::MODE_CONTRACT_MISMATCH           => 'second_pass_contract_drift_may_hide_high_leverage_gaps',
            self::MODE_CROSS_DOMAIN_PATTERN        => 'proven_patterns_in_one_domain_transfer_to_analogous_gaps',
            self::MODE_RESEARCH_BACKED_DESIGN      => 'research_evidence_grounds_next_proposal_in_external_signal',
            self::MODE_ARCHITECTURE_SIMPLIFICATION => 'removing_complexity_on_critical_path_compounds_future_velocity',
            self::MODE_RUNTIME_HEALTH              => 'observability_and_recovery_unlock_safe_autonomous_operation',
            self::MODE_CERTIFICATION_GAP           => 'implemented_but_uncertified_slices_are_latent_compounding_value',
            default                                => 'escalation_required',
        };
    }
}
