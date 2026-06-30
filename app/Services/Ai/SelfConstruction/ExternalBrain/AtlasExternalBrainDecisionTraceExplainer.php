<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure explainer. Converts a batch decision record into a compact, provider-safe
 * causal trace for operator audit and future learning.
 *
 * PROVIDER-SAFETY GUARANTEE: keys matching PRIVATE_KEY_PATTERNS are stripped
 * before any field is included in the trace. No raw prompts, no model responses,
 * no API keys, no system messages surface in the output.
 *
 * Output fields:
 *   trace_id              — stable hash of the decision
 *   top_signals           — key facts that drove the decision (provider-safe)
 *   chosen_reasons        — why selected tasks beat alternatives
 *   rejected_reasons      — per-alternative: { task_packet_id, reason }
 *   uncertainty           — 'low' | 'medium' | 'high' (confidence in decision)
 *   evidence_to_reconsider — facts that, if true, would change the outcome
 *   provider_safe         — always true (assertion on output contract)
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainDecisionTraceExplainer
{
    public const SCHEMA = 'atlas.external_brain.decision_trace_explainer.v1';

    // Any key matching one of these patterns is stripped from the trace.
    private const PRIVATE_KEY_PATTERNS = [
        'prompt', 'raw_prompt', 'system_message', 'model_response',
        'provider_response', 'api_key', 'token', 'secret', 'credential',
    ];

    /**
     * @param  array{
     *   selected_tasks?: list<array<string,mixed>>,
     *   rejected_alternatives?: list<array<string,mixed>>,
     *   queue_context?: array<string,mixed>,
     *   scoring_facts?: array<string,mixed>,
     * }  $decisionRecord
     * @return array{
     *   schema:string, trace_id:string, top_signals:list<string>,
     *   chosen_reasons:list<string>, rejected_reasons:list<array<string,string>>,
     *   uncertainty:string, evidence_to_reconsider:list<string>, provider_safe:true
     * }
     */
    public function explain(array $decisionRecord): array
    {
        $selected  = is_array($decisionRecord['selected_tasks']        ?? null) ? $decisionRecord['selected_tasks']        : [];
        $rejected  = is_array($decisionRecord['rejected_alternatives'] ?? null) ? $decisionRecord['rejected_alternatives'] : [];
        $queueCtx  = is_array($decisionRecord['queue_context']         ?? null) ? $decisionRecord['queue_context']         : [];
        $scoringFacts = is_array($decisionRecord['scoring_facts']      ?? null) ? $decisionRecord['scoring_facts']         : [];

        $topSignals      = $this->extractTopSignals($selected, $scoringFacts, $queueCtx);
        $chosenReasons   = $this->extractChosenReasons($selected, $scoringFacts);
        $rejectedReasons = $this->extractRejectedReasons($rejected);
        $uncertainty     = $this->computeUncertainty($selected, $rejected, $scoringFacts);
        $evidenceToReconsider = $this->extractEvidenceToReconsider($rejected, $scoringFacts);

        $traceId = 'trace_'.substr(hash('sha256', (string) json_encode([
            'selected_ids'  => array_column($selected, 'task_packet_id'),
            'rejected_ids'  => array_column($rejected, 'task_packet_id'),
            'top_signals'   => $topSignals,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 24);

        return [
            'schema'                => self::SCHEMA,
            'trace_id'              => $traceId,
            'top_signals'           => $topSignals,
            'chosen_reasons'        => $chosenReasons,
            'rejected_reasons'      => $rejectedReasons,
            'uncertainty'           => $uncertainty,
            'evidence_to_reconsider' => $evidenceToReconsider,
            'provider_safe'         => true,
        ];
    }

    /** Extract the key signals that drove the decision — provider-safe only. */
    private function extractTopSignals(array $selected, array $scoringFacts, array $queueCtx): array
    {
        $signals = [];
        $safe    = $this->stripPrivate($scoringFacts);

        if (isset($safe['top_dimension'])) {
            $signals[] = 'top_scoring_dimension:'.(string) $safe['top_dimension'];
        }
        if (isset($safe['algorithm'])) {
            $signals[] = 'algorithm:'.(string) $safe['algorithm'];
        }
        $queueDepth = (int) ($queueCtx['queue_depth'] ?? $queueCtx['claimable_depth'] ?? 0);
        if ($queueDepth > 0) {
            $signals[] = 'queue_depth_at_decision:'.$queueDepth;
        }
        $signals[] = 'selected_count:'.count($selected);

        return array_values($signals);
    }

    /** Derive why each selected task was chosen. */
    private function extractChosenReasons(array $selected, array $scoringFacts): array
    {
        $reasons = [];
        foreach ($selected as $task) {
            $id = (string) ($task['task_packet_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $safe   = $this->stripPrivate((array) ($task['scoring_facts'] ?? $task));
            $score  = isset($safe['arena_score']) ? 'arena_score:'.number_format((float) $safe['arena_score'], 3) : null;
            $dim    = isset($safe['top_dimension'])  ? 'top_dim:'.(string) $safe['top_dimension']   : null;
            $reason = 'selected:'.$id;
            if ($score !== null) {
                $reason .= ' '.$score;
            }
            if ($dim !== null) {
                $reason .= ' '.$dim;
            }
            $reasons[] = $reason;
        }

        return $reasons !== [] ? $reasons : ['selected_by_arena_score'];
    }

    /** Map each rejected alternative to its reason (provider-safe). */
    private function extractRejectedReasons(array $rejected): array
    {
        $out = [];
        foreach ($rejected as $alt) {
            $id     = (string) ($alt['task_packet_id'] ?? $alt['proposal_id'] ?? '');
            $reason = (string) ($alt['reason'] ?? $alt['rejection_reason'] ?? 'outscored');
            if ($id !== '') {
                $out[] = ['task_packet_id' => $id, 'reason' => $reason];
            }
        }

        return $out;
    }

    /**
     * Uncertainty: 'high' if few selected + many rejected, or weak scoring evidence.
     *              'medium' if modest alternatives or weak scoring.
     *              'low' if clear winner with strong evidence.
     */
    private function computeUncertainty(array $selected, array $rejected, array $scoringFacts): string
    {
        $safe            = $this->stripPrivate($scoringFacts);
        $evidenceStrength = (float) ($safe['evidence_strength'] ?? $safe['confidence'] ?? 0.5);
        $altCount        = count($rejected);
        $selCount        = count($selected);

        if ($selCount === 0 || $evidenceStrength < 0.40 || $altCount > 5) {
            return 'high';
        }
        if ($evidenceStrength < 0.65 || $altCount > 2) {
            return 'medium';
        }

        return 'low';
    }

    /** Evidence that would flip the decision (derived from rejection reasons). */
    private function extractEvidenceToReconsider(array $rejected, array $scoringFacts): array
    {
        $evidence = [];
        $safe     = $this->stripPrivate($scoringFacts);

        foreach ($rejected as $alt) {
            $reason = (string) ($alt['reason'] ?? $alt['rejection_reason'] ?? '');
            if ($reason === 'proxy_heavy') {
                $evidence[] = 'evidence_that_'.(string) ($alt['task_packet_id'] ?? 'task').'_delivers_real_capability';
            } elseif ($reason === 'no_runnable_evidence_path') {
                $evidence[] = 'runnable_test_or_gate_for_'.(string) ($alt['task_packet_id'] ?? 'task');
            } elseif ($reason === 'duplicate') {
                $evidence[] = 'proof_that_'.(string) ($alt['task_packet_id'] ?? 'task').'_is_not_covered';
            }
        }

        if (isset($safe['top_dimension'])) {
            $evidence[] = 'stronger_'.(string) $safe['top_dimension'].'_evidence_to_change_ranking';
        }

        return array_values(array_unique($evidence));
    }

    /**
     * Strip any key whose name contains a private-key pattern (case-insensitive).
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function stripPrivate(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string) $key);
            $isPrivate = false;
            foreach (self::PRIVATE_KEY_PATTERNS as $pattern) {
                if (str_contains($lowerKey, $pattern)) {
                    $isPrivate = true;
                    break;
                }
            }
            if (! $isPrivate) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
