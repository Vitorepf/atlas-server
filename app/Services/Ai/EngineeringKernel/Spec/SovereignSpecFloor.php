<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Spec;

use App\Services\Ai\EngineeringKernel\CriteriaCanonicalizer;
use App\Services\Ai\EngineeringKernel\TrustLevel;

/**
 * Engineering Kernel mechanism: THE sovereign spec floor. The single deterministic, provider-free
 * authority that seals FREEZE. A model-based signal reaches it only as pre-resolved witness data
 * (a WitnessResolver) and can only DEMOTE (revise/hold) — it never grants freeze credit.
 *
 * Owns: computing the frozen_hash over the real criteria and deciding freeze|revise|refuse|hold from
 * pure invariants a model cannot talk past. Each threshold is max(piso, config) — config only tightens.
 * Must never own: running the oracle (SpecOracle port), calling a provider (WitnessResolver port), or
 * composing the spec. It is a pure value-in/verdict-out floor: no Eloquent, no config side effects, no I/O.
 */
final class SovereignSpecFloor implements SpecAdversary
{
    /** Bumped whenever the invariant set changes — sealed into provenance. */
    public const FLOOR_VERSION = 'atlas.engineering_kernel.sovereign_spec_floor.v1';

    /** At least this many behavioral criteria must go RED on the no-op stub (discrimination). */
    public const SOVEREIGN_MIN_DISCRIMINATING = 1;

    public function __construct(
        private readonly SpecOracle $oracle,
        private readonly WitnessResolver $witnessResolver,
        private readonly int $configMinDiscriminating = 0,
    ) {}

    public function minDiscriminating(): int
    {
        return max(self::SOVEREIGN_MIN_DISCRIMINATING, $this->configMinDiscriminating);
    }

    public function contest(SpecDraft $draft, IntentEnvelope $intent, TrustLevel $lane): SpecVerdict
    {
        $frozenHash = CriteriaCanonicalizer::hash($draft->acceptanceCriteria);
        $report = $this->oracle->probe($draft);
        $witness = $this->witnessResolver->resolve($draft, $intent, $lane);
        $findings = SpecAmbiguityProducer::findings($draft, $intent);

        $invariants = [
            'verb_fidelity' => $this->verbFidelity($draft, $intent),
            'oracle_adequacy' => $this->oracleAdequacy($draft, $report),
            'ambiguity_resolved' => $this->ambiguityResolved($findings, $intent),
            'spec_source_independence' => $this->sourceIndependence($witness, $lane),
            'intent_divergence' => $this->intentDivergence($witness),
        ];

        $provenance = new SpecProvenance(
            frozenHash: $frozenHash,
            divergenceStatus: $witness->divergenceStatus,
            specSourceIndependence: $witness->sourceIndependence,
            oracleMode: $report->mode,
            ambiguityFindings: $findings,
        );

        $gapsOf = static fn (string $status): array => array_keys(array_filter(
            $invariants,
            static fn (array $r): bool => $r['status'] === $status,
        ));

        // Precedence, most-severe first: a deterministic hard-fail always wins.
        if (($refuse = $gapsOf('fail')) !== []) {
            return SpecVerdict::refuse($refuse, $invariants, $provenance);
        }
        if (($revise = $gapsOf('revise')) !== []) {
            return SpecVerdict::revise($revise, $invariants, $provenance);
        }
        if (($hold = $gapsOf('hold')) !== []) {
            return SpecVerdict::hold($hold, $invariants, $provenance);
        }

        return SpecVerdict::freeze($invariants, $provenance);
    }

    /**
     * A write task whose intent carries recognized verbs MUST carry behavioral criteria — the
     * e2.mode=off silent-empty-spec escape hatch is a hard REFUSE, never a pass.
     *
     * @return array{status:string,detail:string}
     */
    private function verbFidelity(SpecDraft $draft, IntentEnvelope $intent): array
    {
        if ($intent->recognizedVerbs === []) {
            return $this->r('pass', 'no_recognized_verbs_no_behavioral_requirement');
        }

        return $draft->behavioralCriteria() !== []
            ? $this->r('pass', 'behavioral_criteria_present_for_recognized_verbs')
            : $this->r('fail', 'verb_present_but_no_behavioral_criteria');
    }

    /**
     * The ONLY honest oracle: the frozen tests must go genuinely RED against a no-op wrong impl.
     * Green-on-noop = tautological = REFUSE. Unmeasured = HOLD (never fabricate a pass).
     *
     * @return array{status:string,detail:string}
     */
    private function oracleAdequacy(SpecDraft $draft, OracleReport $report): array
    {
        if ($report->mode === SpecProvenance::ORACLE_UNMEASURED) {
            return $this->r('hold', 'oracle_unmeasured_cannot_prove_discrimination');
        }

        $behavioralIds = array_values(array_map(
            static fn (array $ac): string => (string) ($ac['id'] ?? ''),
            $draft->behavioralCriteria(),
        ));
        $discriminating = count(array_intersect($behavioralIds, $report->redCriteriaIds));
        $floor = $this->minDiscriminating();

        return $discriminating >= $floor
            ? $this->r('pass', "discriminating_behavioral_criteria {$discriminating} >= {$floor}")
            : $this->r('fail', "green_on_noop_discriminating {$discriminating} < {$floor}");
    }

    /**
     * @param  list<string>  $findings
     * @return array{status:string,detail:string}
     */
    private function ambiguityResolved(array $findings, IntentEnvelope $intent): array
    {
        $unresolved = array_values(array_filter(
            $findings,
            static fn (string $f): bool => ! $intent->wasElicited($f),
        ));

        return $unresolved === []
            ? $this->r('pass', 'no_unresolved_ambiguity')
            : $this->r('hold', 'unresolved_ambiguity:'.implode(',', $unresolved));
    }

    /**
     * @return array{status:string,detail:string}
     */
    private function sourceIndependence(WitnessContext $witness, TrustLevel $lane): array
    {
        return $witness->sourceIndependence->mayFreezeIn($lane)
            ? $this->r('pass', 'spec_source_independence_'.$witness->sourceIndependence->value)
            : $this->r('hold', 'spec_source_unwitnessed_in_lane_'.$lane->value);
    }

    /**
     * Shadow-spec divergence is one-directional: DIVERGED => revise. AGREED grants ZERO credit
     * (anti correlated-hallucination); UNAVAILABLE/NOT_REQUIRED never block here.
     *
     * @return array{status:string,detail:string}
     */
    private function intentDivergence(WitnessContext $witness): array
    {
        return $witness->divergenceStatus === DivergenceStatus::Diverged
            ? $this->r('revise', 'shadow_spec_diverged_from_composed_spec')
            : $this->r('pass', 'no_actionable_divergence_'.$witness->divergenceStatus->value);
    }

    /**
     * @return array{status:string,detail:string}
     */
    private function r(string $status, string $detail): array
    {
        return ['status' => $status, 'detail' => $detail];
    }
}
