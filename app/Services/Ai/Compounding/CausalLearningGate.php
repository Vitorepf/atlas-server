<?php

declare(strict_types=1);

namespace App\Services\Ai\Compounding;

final class CausalLearningGate
{
    public function adjudicate(CausalLearningCandidate $candidate): CausalLearningVerdict
    {
        $d = $candidate->data;
        $verdict = 'hold'; $reason = 'causal_evidence_incomplete';
        if ($d['assignment_precedes_run'] !== true || $d['real_outcome'] !== true || $d['assignment_hash'] === str_repeat('0', 64)) {
            $reason = 'assignment_or_real_outcome_unproven';
        } elseif ((float) $d['ci_low'] <= 0.0 || (float) $d['effect'] <= 0.0) {
            $reason = 'causal_effect_uncertain';
        } elseif ($d['change_class'] === 'code_task') {
            $verdict = 'emit_code_task'; $reason = 'code_change_requires_engineering_factory';
        } elseif ($d['reversible'] !== true) {
            $reason = 'promotion_not_reversible';
        } elseif (trim((string) $d['rollback']) === '') {
            $reason = 'rollback_missing';
        } else {
            $verdict = 'promote_reversible'; $reason = 'causal_evidence_admitted';
        }

        return new CausalLearningVerdict($verdict, $reason, CompoundingHash::make(['candidate' => $candidate->candidateHash, 'verdict' => $verdict, 'reason' => $reason]));
    }
}
