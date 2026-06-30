<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Honest certification gate for the Atlas external brain.
 *
 * Evaluates seven evidence dimensions and returns one of:
 *   final_95_candidate — all dimensions proven by live evidence
 *   near_final         — 5 or 6 of 7 dimensions pass
 *   below_final        — ≤4 dimensions pass
 *
 * KEY CONTRACT:
 *   - final_95_candidate is impossible from authored specs or queue counts alone.
 *     It requires real cycle evidence, anti-Goodhart pass, self-improvement output,
 *     muscle outcome learning, property-gated path readiness, doc proposals, and
 *     no human/provider dependency in steady state.
 *   - Every failing dimension emits an actionable blocker entry.
 *
 * INPUT:
 *   live_cycle_evidence:      {cycle_count:int, resolved_task_count:int}
 *   anti_goodhart:            {verdict:'pass'|'reject'|'repair_required'|'unknown'}
 *   self_improvement_cycle:   {has_output:bool, recommendation_count:int}
 *   muscle_learning:          {outcome_count:int, success_rate:float}
 *   property_gated_path:      {ready:bool, blocking_gates:list<string>}
 *   doc_proposal:             {drafted:bool, certification_blocked:bool}
 *   autonomy:                 {human_dependency_in_loop:bool, provider_dependency_in_steady_state:bool}
 *
 * Pure / deterministic. No I/O.
 */
final class AtlasExternalBrainFinalCertificationGate
{
    public const SCHEMA = 'atlas.external_brain.final_certification_gate.v1';

    public const VERDICT_FINAL_95    = 'final_95_candidate';
    public const VERDICT_NEAR_FINAL  = 'near_final';
    public const VERDICT_BELOW_FINAL = 'below_final';

    private const NEAR_FINAL_THRESHOLD = 5; // of 7 required dimensions

    /** Minimum evidence refs required per dimension to reach final_95_candidate. */
    private const REQUIRED_REFS = [
        'live_cycle_evidence'           => ['cycle_run_receipt'],
        'anti_goodhart_pass'            => ['audit_report'],
        'self_improvement_cycle_output' => ['recommendation_receipt'],
        'muscle_outcome_learning'       => ['outcome_ledger_ref'],
        'property_gated_path'           => ['gate_readiness_cert'],
        'doc_proposal_readiness'        => ['doc_draft_ref'],
        'autonomy_steady_state'         => ['autonomy_assessment_ref'],
    ];

    /**
     * @param  array<string,mixed>  $evidence
     * @return array{
     *     schema: string,
     *     verdict: string,
     *     passed_count: int,
     *     required_count: int,
     *     blockers: list<array{dimension:string, reason:string, action:string}>,
     *     dimension_results: array<string,bool>,
     * }
     */
    public function certify(array $evidence): array
    {
        $results  = [];
        $blockers = [];
        $dossier  = [];

        // Dimension 1: live cycle evidence
        $cycleCount    = (int) ($evidence['live_cycle_evidence']['cycle_count'] ?? 0);
        $resolvedCount = (int) ($evidence['live_cycle_evidence']['resolved_task_count'] ?? 0);
        $liveCyclePass = $cycleCount >= 1 && $resolvedCount >= 1;
        $results['live_cycle_evidence'] = $liveCyclePass;
        $dossier[] = $this->dossierEntry('live_cycle_evidence', $liveCyclePass, $evidence['live_cycle_evidence']['evidence_refs'] ?? []);
        if (! $liveCyclePass) {
            $blockers[] = [
                'dimension' => 'live_cycle_evidence',
                'reason'    => "cycle_count={$cycleCount}, resolved_task_count={$resolvedCount}: no live cycle proven",
                'action'    => 'Run at least one complete originate→task→resolve cycle with measurable output',
            ];
        }

        // Dimension 2: anti-Goodhart audit pass
        $auditVerdict = (string) ($evidence['anti_goodhart']['verdict'] ?? 'unknown');
        $auditPass    = $auditVerdict === 'pass';
        $results['anti_goodhart_pass'] = $auditPass;
        $dossier[] = $this->dossierEntry('anti_goodhart_pass', $auditPass, $evidence['anti_goodhart']['evidence_refs'] ?? []);
        if (! $auditPass) {
            $blockers[] = [
                'dimension' => 'anti_goodhart_pass',
                'reason'    => "audit verdict='{$auditVerdict}': not a confirmed pass",
                'action'    => 'Run AntiGoodhartAuditor and reach a pass verdict before certifying',
            ];
        }

        // Dimension 3: self-improvement cycle output
        $hasOutput           = (bool) ($evidence['self_improvement_cycle']['has_output'] ?? false);
        $recommendationCount = (int) ($evidence['self_improvement_cycle']['recommendation_count'] ?? 0);
        $selfImprovementPass = $hasOutput && $recommendationCount >= 1;
        $results['self_improvement_cycle_output'] = $selfImprovementPass;
        $dossier[] = $this->dossierEntry('self_improvement_cycle_output', $selfImprovementPass, $evidence['self_improvement_cycle']['evidence_refs'] ?? []);
        if (! $selfImprovementPass) {
            $blockers[] = [
                'dimension' => 'self_improvement_cycle_output',
                'reason'    => "has_output={$hasOutput}, recommendation_count={$recommendationCount}: no cycle output produced",
                'action'    => 'Execute one full self-improvement cycle that produces at least one actionable recommendation',
            ];
        }

        // Dimension 4: muscle outcome learning
        $outcomeCount = (int) ($evidence['muscle_learning']['outcome_count'] ?? 0);
        $successRate  = (float) ($evidence['muscle_learning']['success_rate'] ?? 0.0);
        $musclePass   = $outcomeCount >= 1 && $successRate > 0.0;
        $results['muscle_outcome_learning'] = $musclePass;
        $dossier[] = $this->dossierEntry('muscle_outcome_learning', $musclePass, $evidence['muscle_learning']['evidence_refs'] ?? []);
        if (! $musclePass) {
            $blockers[] = [
                'dimension' => 'muscle_outcome_learning',
                'reason'    => "outcome_count={$outcomeCount}, success_rate={$successRate}: no muscle outcomes recorded",
                'action'    => 'Record at least one real muscle task outcome (success or give_back) in the learning ledger',
            ];
        }

        // Dimension 5: property-gated path readiness
        $pgReady           = (bool) ($evidence['property_gated_path']['ready'] ?? false);
        $blockingGates     = (array) ($evidence['property_gated_path']['blocking_gates'] ?? []);
        $propertyGatedPass = $pgReady && $blockingGates === [];
        $results['property_gated_path'] = $propertyGatedPass;
        $dossier[] = $this->dossierEntry('property_gated_path', $propertyGatedPass, $evidence['property_gated_path']['evidence_refs'] ?? []);
        if (! $propertyGatedPass) {
            $gateList = $blockingGates !== [] ? implode(', ', $blockingGates) : 'ready=false';
            $blockers[] = [
                'dimension' => 'property_gated_path',
                'reason'    => "not ready: {$gateList}",
                'action'    => 'Resolve all blocking property gates before the certification run',
            ];
        }

        // Dimension 6: documentation proposal readiness
        $docDrafted     = (bool) ($evidence['doc_proposal']['drafted'] ?? false);
        $docCertBlocked = (bool) ($evidence['doc_proposal']['certification_blocked'] ?? true);
        $docPass        = $docDrafted && ! $docCertBlocked;
        $results['doc_proposal_readiness'] = $docPass;
        $dossier[] = $this->dossierEntry('doc_proposal_readiness', $docPass, $evidence['doc_proposal']['evidence_refs'] ?? []);
        if (! $docPass) {
            $reason = ! $docDrafted ? 'proposals not drafted' : 'doc certification is blocked';
            $blockers[] = [
                'dimension' => 'doc_proposal_readiness',
                'reason'    => $reason,
                'action'    => 'Draft doc proposals via DocSyncDrafter and clear all doc-level certification blockers',
            ];
        }

        // Dimension 7: autonomy / no human or provider dependency in steady state
        $humanDep     = (bool) ($evidence['autonomy']['human_dependency_in_loop'] ?? true);
        $providerDep  = (bool) ($evidence['autonomy']['provider_dependency_in_steady_state'] ?? true);
        $autonomyPass = ! $humanDep && ! $providerDep;
        $results['autonomy_steady_state'] = $autonomyPass;
        $dossier[] = $this->dossierEntry('autonomy_steady_state', $autonomyPass, $evidence['autonomy']['evidence_refs'] ?? []);
        if (! $autonomyPass) {
            $deps = [];
            if ($humanDep) {
                $deps[] = 'human_dependency_in_loop';
            }
            if ($providerDep) {
                $deps[] = 'provider_dependency_in_steady_state';
            }
            $blockers[] = [
                'dimension' => 'autonomy_steady_state',
                'reason'    => 'dependencies remain: '.implode(', ', $deps),
                'action'    => 'Eliminate human and provider dependencies from the steady-state loop path',
            ];
        }

        $passedCount   = count(array_filter($results));
        $requiredCount = count($results);

        // Passing dimensions with missing evidence_refs block final_95_candidate.
        $hasMissingRefs = (bool) count(array_filter(
            $dossier,
            fn(array $e): bool => $e['passed'] && $e['missing_refs'] !== [],
        ));

        return [
            'schema'            => self::SCHEMA,
            'verdict'           => $this->computeVerdict($passedCount, $requiredCount, $hasMissingRefs),
            'passed_count'      => $passedCount,
            'required_count'    => $requiredCount,
            'blockers'          => $blockers,
            'dimension_results' => $results,
            'evidence_dossier'  => $dossier,
        ];
    }

    private function computeVerdict(int $passed, int $required, bool $hasMissingRefs = false): string
    {
        if ($passed === $required && ! $hasMissingRefs) {
            return self::VERDICT_FINAL_95;
        }
        if ($passed >= self::NEAR_FINAL_THRESHOLD) {
            return self::VERDICT_NEAR_FINAL;
        }

        return self::VERDICT_BELOW_FINAL;
    }

    /** @return array{dimension:string, passed:bool, evidence_refs:list<string>, missing_refs:list<string>} */
    private function dossierEntry(string $dimension, bool $passed, array $providedRefs): array
    {
        $required    = self::REQUIRED_REFS[$dimension] ?? [];
        $missingRefs = array_values(array_diff($required, $providedRefs));

        return [
            'dimension'    => $dimension,
            'passed'       => $passed,
            'evidence_refs' => array_values($providedRefs),
            'missing_refs'  => $missingRefs,
        ];
    }
}
