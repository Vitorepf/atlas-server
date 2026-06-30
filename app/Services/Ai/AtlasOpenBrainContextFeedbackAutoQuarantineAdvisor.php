<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Pure, proposal-only advisor over AOBG context-feedback records. Turns repeated worker feedback (hostile,
 * stale, or noisy memory refs) into concrete quarantine/demotion PROPOSALS before the next context-pack
 * export — never auto-promotes, deletes, or mutates memory itself. A later governed writer decides whether
 * to apply a proposal; this class only groups evidence by source_ref and names the smallest safe action.
 *
 * Input feedback record shape:
 *   {context_pack_hash, source_ref, issue_code, severity:'low'|'medium'|'high', observed_effect?, suggested_filter?}
 */
final class AtlasOpenBrainContextFeedbackAutoQuarantineAdvisor
{
    public const SCHEMA = 'atlas.ai.open_brain.context_feedback_auto_quarantine_advisor.v1';

    public const ACTION_DEMOTE = 'demote';

    public const ACTION_REQUIRE_SANITIZATION = 'require_sanitization';

    public const ACTION_QUARANTINE = 'quarantine_from_provider_context';

    public const ACTION_NO_ACTION = 'no_action';

    private const STALE_DEMOTE_THRESHOLD = 3;

    private const NOISY_DEMOTE_THRESHOLD = 2;

    private const HOSTILE_QUARANTINE_THRESHOLD = 2;

    /**
     * Issue codes that represent an adversarial or unsafe memory ref (not just low-quality):
     * hostile language, emotionally-manipulative wording designed to bias a worker, or
     * instruction-like text smuggled into a memory ref to redirect a worker's behavior
     * (prompt injection). Any of these at high severity is treated identically — a single
     * flag requires sanitization, repeated flags escalate straight to quarantine.
     */
    private const HIGH_RISK_ISSUE_CODES = ['hostile_memory', 'emotional_manipulation', 'instruction_like'];

    /**
     * @param  list<array<string, mixed>>  $feedbackRecords
     * @return array<string, mixed>
     */
    public function advise(array $feedbackRecords): array
    {
        $grouped = [];
        foreach ($feedbackRecords as $record) {
            $sourceRef = (string) ($record['source_ref'] ?? '');
            if ($sourceRef === '') {
                continue;
            }
            $grouped[$sourceRef][] = $record;
        }

        $proposals = [];
        foreach ($grouped as $sourceRef => $records) {
            $proposals[] = $this->proposalFor($sourceRef, $records);
        }
        usort($proposals, static fn (array $a, array $b): int => strcmp((string) $a['source_ref'], (string) $b['source_ref']));

        return [
            'schema' => self::SCHEMA,
            'proposals' => $proposals,
            'mutates_memory' => false,
            'auto_promotes' => false,
            'auto_deletes' => false,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array<string, mixed>
     */
    private function proposalFor(string $sourceRef, array $records): array
    {
        $issueCounts = [];
        $highRiskHighSeverityCount = 0;
        foreach ($records as $record) {
            $issue = (string) ($record['issue_code'] ?? 'unknown');
            $issueCounts[$issue] = ($issueCounts[$issue] ?? 0) + 1;
            if (in_array($issue, self::HIGH_RISK_ISSUE_CODES, true) && (string) ($record['severity'] ?? '') === 'high') {
                $highRiskHighSeverityCount++;
            }
        }
        ksort($issueCounts);

        $staleCount = $issueCounts['stale'] ?? 0;
        $noisyCount = $issueCounts['noisy'] ?? 0;

        [$action, $reason] = match (true) {
            $highRiskHighSeverityCount >= self::HOSTILE_QUARANTINE_THRESHOLD => [self::ACTION_QUARANTINE, 'repeated_high_severity_unsafe_memory_flags'],
            $highRiskHighSeverityCount >= 1 => [self::ACTION_REQUIRE_SANITIZATION, 'high_severity_unsafe_memory_flag'],
            $staleCount >= self::STALE_DEMOTE_THRESHOLD => [self::ACTION_DEMOTE, 'repeated_stale_flags'],
            $noisyCount >= self::NOISY_DEMOTE_THRESHOLD => [self::ACTION_DEMOTE, 'repeated_noisy_flags'],
            default => [self::ACTION_NO_ACTION, 'insufficient_evidence'],
        };

        $evidenceRefs = array_values(array_unique(array_filter(array_map(
            static fn (array $r): string => (string) ($r['context_pack_hash'] ?? ''),
            $records,
        ))));
        sort($evidenceRefs);

        return [
            'source_ref' => $sourceRef,
            'action' => $action,
            'reason' => $reason,
            'evidence_counts' => $issueCounts,
            'evidence_refs' => $evidenceRefs,
        ];
    }
}
