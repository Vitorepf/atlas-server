<?php

declare(strict_types=1);

namespace App\Services\Ai\Compounding;

use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;

final class CausalLearningGate
{
    public function __construct(private readonly ?AtlasEvidenceLedger $ledger = null) {}

    public function adjudicate(CausalLearningCandidate $candidate): CausalLearningVerdict
    {
        $d = $candidate->data;
        $verdict = 'hold'; $reason = 'causal_evidence_incomplete';
        if ((float) $d['ci_low'] > (float) $d['ci_high'] || (float) $d['effect'] < (float) $d['ci_low'] || (float) $d['effect'] > (float) $d['ci_high']) {
            $verdict = 'reject'; $reason = 'causal_interval_inconsistent';
        } elseif ($d['assignment_precedes_run'] !== true || $d['real_outcome'] !== true || $d['assignment_hash'] === str_repeat('0', 64) || $d['order_hash'] === str_repeat('0', 64)) {
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

        $result = new CausalLearningVerdict($verdict, $reason, CompoundingHash::make(['candidate' => $candidate->candidateHash, 'verdict' => $verdict, 'reason' => $reason]));
        $this->record($candidate, $result);

        return $result;
    }

    private function record(CausalLearningCandidate $candidate, CausalLearningVerdict $verdict): void
    {
        $ledger = $this->ledger;
        if ($ledger === null && function_exists('app')) {
            try {
                $ledger = app()->bound(AtlasEvidenceLedger::class) ? app(AtlasEvidenceLedger::class) : null;
            } catch (\Throwable) {
                $ledger = null;
            }
        }
        if (! $ledger instanceof AtlasEvidenceLedger) return;
        $eventId = 'causal-'.substr($verdict->decisionHash, 0, 24);
        if ($ledger->eventById($eventId) !== null) return;
        $ledger->record(LedgerEventType::LearningProposed, [
            'event_name' => 'learning.adjudicated', 'candidate_hash' => $candidate->candidateHash,
            'decision_hash' => $verdict->decisionHash, 'verdict' => $verdict->verdict, 'reason' => $verdict->reason,
        ], ['event_id' => $eventId, 'correlation_id' => $candidate->candidateHash,
            'scope_type' => 'causal_learning', 'scope_id' => $candidate->candidateHash, 'emitter_stage' => 'atlas.compounding.causal_gate']);
    }
}
