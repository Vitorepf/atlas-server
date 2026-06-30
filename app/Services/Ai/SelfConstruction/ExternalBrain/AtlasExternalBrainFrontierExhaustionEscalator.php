<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure escalator: when a bug-hunt surface thins, selects the next deeper pass
 * from the ordered ladder. NEVER returns template_farm.
 *
 * Each later pass requires a stronger evidence floor:
 *   bug_hunt                   → inspected_surface
 *   compression                → + rejected_false_leads
 *   research_transfer          → + expected_yield_range
 *   contract_mismatch_deepening → + why_not_stop
 *   stop_with_evidence         → same as above
 *
 * Thinning signals: declining verified_findings, rising token_cost, high duplicate_findings.
 */
final class AtlasExternalBrainFrontierExhaustionEscalator
{
    public const SCHEMA = 'atlas.external_brain.frontier_exhaustion_escalator.v1';

    /** @var list<string> */
    public const PASS_LADDER = [
        'bug_hunt',
        'compression',
        'research_transfer',
        'contract_mismatch_deepening',
        'stop_with_evidence',
    ];

    /** @var list<string> */
    public const FORBIDDEN_PASSES = ['template_farm'];

    /** @var array<string,list<string>> Cumulative evidence floor per pass. */
    public const EVIDENCE_FLOOR_BY_PASS = [
        'bug_hunt'                    => ['inspected_surface'],
        'compression'                  => ['inspected_surface', 'rejected_false_leads'],
        'research_transfer'            => ['inspected_surface', 'rejected_false_leads', 'expected_yield_range'],
        'contract_mismatch_deepening' => ['inspected_surface', 'rejected_false_leads', 'expected_yield_range', 'why_not_stop'],
        'stop_with_evidence'          => ['inspected_surface', 'rejected_false_leads', 'expected_yield_range', 'why_not_stop'],
    ];

    /** Minimum duplicate rate (vs total) to classify as high-duplicate wave. */
    private const HIGH_DUPLICATE_THRESHOLD = 0.5;

    /**
     * Evaluate wave history and return the next escalation pass.
     *
     * @param  list<array<string,mixed>>  $waveHistory  chronological list of wave records
     * @param  array<string,mixed>  $context  optional: current_pass, remaining_high_risk_domains
     * @return array<string,mixed>
     */
    public function escalate(array $waveHistory, array $context = []): array
    {
        $currentPass = (string) ($context['current_pass'] ?? 'bug_hunt');
        if (in_array($currentPass, self::FORBIDDEN_PASSES, true) || ! in_array($currentPass, self::PASS_LADDER, true)) {
            $currentPass = 'bug_hunt';
        }
        $remainingHighRisk = is_array($context['remaining_high_risk_domains'] ?? null)
            ? array_values(array_map('strval', $context['remaining_high_risk_domains']))
            : [];

        $decliningFindings = $this->isDecliningFindings($waveHistory);
        $risingCost = $this->isRisingCost($waveHistory);
        $highDuplicates = $this->isHighDuplicates($waveHistory);

        $nextPass = $this->computeNextPass($currentPass, $decliningFindings, $risingCost, $highDuplicates, $remainingHighRisk);

        $latestWave = $waveHistory !== [] ? $waveHistory[count($waveHistory) - 1] : [];
        $requiredFloor = self::EVIDENCE_FLOOR_BY_PASS[$currentPass] ?? ['inspected_surface'];
        $missingEvidence = $this->missingFloor($latestWave, $requiredFloor);

        $reasonParts = [];
        if ($decliningFindings) {
            $reasonParts[] = 'declining_verified_findings';
        }
        if ($risingCost) {
            $reasonParts[] = 'rising_token_cost';
        }
        if ($highDuplicates) {
            $reasonParts[] = 'high_duplicate_rate';
        }
        if ($remainingHighRisk !== []) {
            $reasonParts[] = 'remaining_high_risk_domains';
        }
        $escalationReason = $reasonParts !== [] ? implode(', ', $reasonParts) : 'surface_stable';

        return [
            'schema_version' => self::SCHEMA,
            'next_pass' => $nextPass,
            'current_pass' => $currentPass,
            'escalation_reason' => $escalationReason,
            'evidence_floor' => self::EVIDENCE_FLOOR_BY_PASS[$nextPass] ?? [],
            'missing_evidence' => $missingEvidence,
            'evidence_floor_satisfied' => $missingEvidence === [],
            'wave_analysis' => [
                'wave_count' => count($waveHistory),
                'declining_findings' => $decliningFindings,
                'rising_cost' => $risingCost,
                'high_duplicate_rate' => $highDuplicates,
                'remaining_high_risk_count' => count($remainingHighRisk),
            ],
        ];
    }

    private function computeNextPass(string $currentPass, bool $decliningFindings, bool $risingCost, bool $highDuplicates, array $remainingHighRisk): string
    {
        $ladder = self::PASS_LADDER;
        $idx = array_search($currentPass, $ladder, true);
        if ($idx === false) {
            $idx = 0;
        }

        $surfaceThin = $decliningFindings && $risingCost && $highDuplicates;
        $shouldEscalate = $surfaceThin || ($remainingHighRisk !== [] && ($decliningFindings || $highDuplicates));

        if ($shouldEscalate && $idx < count($ladder) - 1) {
            return $ladder[$idx + 1];
        }

        return $ladder[$idx];
    }

    private function isDecliningFindings(array $waveHistory): bool
    {
        if (count($waveHistory) < 2) {
            return false;
        }
        $last = (int) ($waveHistory[count($waveHistory) - 1]['verified_findings'] ?? 0);
        $prev = (int) ($waveHistory[count($waveHistory) - 2]['verified_findings'] ?? 0);

        return $last < $prev;
    }

    private function isRisingCost(array $waveHistory): bool
    {
        if (count($waveHistory) < 2) {
            return false;
        }
        $last = (float) ($waveHistory[count($waveHistory) - 1]['token_cost'] ?? 0.0);
        $prev = (float) ($waveHistory[count($waveHistory) - 2]['token_cost'] ?? 0.0);

        return $last > $prev;
    }

    private function isHighDuplicates(array $waveHistory): bool
    {
        if ($waveHistory === []) {
            return false;
        }
        $latest = $waveHistory[count($waveHistory) - 1];
        $verified = (int) ($latest['verified_findings'] ?? 0);
        $dupes = (int) ($latest['duplicate_findings'] ?? 0);
        $total = $verified + $dupes;

        return $total > 0 && ($dupes / $total) >= self::HIGH_DUPLICATE_THRESHOLD;
    }

    /**
     * @param  list<string>  $required
     * @return list<string>
     */
    private function missingFloor(array $wave, array $required): array
    {
        $missing = [];
        foreach ($required as $field) {
            $val = $wave[$field] ?? null;
            if ($val === null || $val === '' || $val === []) {
                $missing[] = $field;
            }
        }

        return $missing;
    }
}
