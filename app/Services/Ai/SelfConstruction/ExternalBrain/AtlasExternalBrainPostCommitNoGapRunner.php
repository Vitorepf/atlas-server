<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Post-commit no-gap runner: after a commit lands, composes the wave resequencer,
 * compression auditor, originator gate, and end-to-end proof into a single verdict
 * on whether the external brain is gap-free (ready to allow stop/pivot).
 *
 * Each sub-orch is pure / deterministic — no I/O, no side effects.
 *
 * Output shape:
 *   {
 *     no_gap:             bool,
 *     resequenced_wave:   array,
 *     compression_audit:  array,
 *     originator_gate:    array,
 *     e2e_proof:          array,
 *     reasons:            list<string>,
 *   }
 */
final class AtlasExternalBrainPostCommitNoGapRunner
{
    public const SCHEMA = 'atlas.external_brain.post_commit_no_gap_runner.v1';

    private AtlasExternalBrainPostCommitWaveResequencer $resequencer;

    private AtlasExternalBrainPostMergeCompressionAuditor $compressionAuditor;

    private AtlasExternalBrainNoGapOriginatorGate $originatorGate;

    private AtlasExternalBrainNoGapEndToEndProof $endToEndProof;

    public function __construct(
        ?AtlasExternalBrainPostCommitWaveResequencer $resequencer = null,
        ?AtlasExternalBrainPostMergeCompressionAuditor $compressionAuditor = null,
        ?AtlasExternalBrainNoGapOriginatorGate $originatorGate = null,
        ?AtlasExternalBrainNoGapEndToEndProof $endToEndProof = null,
    ) {
        $this->resequencer = $resequencer ?? new AtlasExternalBrainPostCommitWaveResequencer;
        $this->compressionAuditor = $compressionAuditor ?? new AtlasExternalBrainPostMergeCompressionAuditor;
        $this->originatorGate = $originatorGate ?? new AtlasExternalBrainNoGapOriginatorGate;
        $this->endToEndProof = $endToEndProof ?? new AtlasExternalBrainNoGapEndToEndProof;
    }

    /**
     * @param  array<string,mixed>  $input
     *   {
     *     resequence_facts?:     array  — passed to resequencer->resequence()
     *     compression_facts?:    array  — passed to compressionAuditor->audit()
     *     originator_proposal?:  array  — passed to originatorGate->evaluate()
     *     proof_facts?:          array  — passed to endToEndProof->prove()
     *   }
     * @return array<string,mixed>
     */
    public function run(array $input): array
    {
        $resequenceFacts = is_array($input['resequence_facts'] ?? null) ? $input['resequence_facts'] : [];
        $compressionFacts = is_array($input['compression_facts'] ?? null) ? $input['compression_facts'] : [];
        $originatorProposal = is_array($input['originator_proposal'] ?? null) ? $input['originator_proposal'] : [];
        $proofFacts = is_array($input['proof_facts'] ?? null) ? $input['proof_facts'] : [];

        $resequencedWave = $this->resequencer->resequence($resequenceFacts);
        $compressionAudit = $this->compressionAuditor->audit($compressionFacts);
        $originatorGate = $this->originatorGate->evaluate($originatorProposal);
        $e2eProof = $this->endToEndProof->prove($proofFacts);

        // Derive no_gap: all four checks must pass.
        $waveClean = ($resequencedWave['removed_obsolete_task_ids'] ?? []) === [];
        $compressionMet = ($compressionAudit['status'] ?? '') === AtlasExternalBrainPostMergeCompressionAuditor::STATUS_PROMISE_MET;
        $gateSeedable = ($originatorGate['seedable'] ?? false) === true;
        $proofComplete = ($e2eProof['complete'] ?? false) === true;

        $noGap = $waveClean && $compressionMet && $gateSeedable && $proofComplete;

        // Aggregate reasons from each sub-orch.
        $reasons = [];

        if (! $waveClean) {
            $reasons[] = 'wave_has_obsolete_tasks';
        }
        if (! $compressionMet) {
            $reasons[] = 'compression_unmet';
        }
        if (! $gateSeedable) {
            $reasons[] = 'originator_gate_blocked';
        }
        if (! $proofComplete) {
            $reasons[] = 'e2e_proof_incomplete';
        }

        return [
            'schema' => self::SCHEMA,
            'no_gap' => $noGap,
            'resequenced_wave' => $resequencedWave,
            'compression_audit' => $compressionAudit,
            'originator_gate' => $originatorGate,
            'e2e_proof' => $e2eProof,
            'reasons' => $reasons,
        ];
    }
}
