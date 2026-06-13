<?php

declare(strict_types=1);

namespace App\Services\Ai\Analysis;

/**
 * G6 — metric-family-aware honesty gate for cross-domain analyses.
 *
 * Deterministic (no provider call). Lesson carried over from the trading
 * honesty work: applying ONE family's thresholds to ANOTHER family's metrics
 * produces fake-secure verdicts. So the gate is family-aware by construction:
 *
 *  - the family is resolved from the EXPLICIT `metric_family` declaration
 *    (default `generic_claims` when absent);
 *  - an unknown declared family is refused fail-closed (`unknown_metric_family`);
 *  - an analysis presenting financial-family metric keys (sharpe / win_rate /
 *    pnl / ...) while declaring a NON-financial family is refused with
 *    `metric_family_mismatch` — the exact anti-fake-secure check;
 *  - each known family carries its own required fields + sanity checks
 *    (win_rate as headline metric is forbidden for financial work; pass-rate
 *    claims without totals are forbidden for engineering work).
 */
final class AnalysisHonestyGate
{
    public const FAMILY_FINANCIAL_BACKTEST = 'financial_backtest';

    public const FAMILY_ENGINEERING_TESTS = 'engineering_tests';

    public const FAMILY_GENERIC_CLAIMS = 'generic_claims';

    public const KNOWN_FAMILIES = [
        self::FAMILY_FINANCIAL_BACKTEST,
        self::FAMILY_ENGINEERING_TESTS,
        self::FAMILY_GENERIC_CLAIMS,
    ];

    /**
     * Metric keys whose presence marks an analysis as carrying
     * financial-backtest-family numbers, regardless of the declared family.
     */
    private const FINANCIAL_METRIC_KEYS = [
        'sharpe',
        'deflated_sharpe',
        'holdout_sharpe',
        'win_rate',
        'pnl',
        'sortino',
        'drawdown',
        'max_drawdown',
        'profit_factor',
        'pbo',
    ];

    /**
     * @param  array<string,mixed>  $analysis
     * @return array{certified:bool, reasons:list<string>, metric_family:string, report:array<string,mixed>}
     */
    public function evaluate(array $analysis): array
    {
        $declaredRaw = $analysis['metric_family'] ?? null;
        $declared = strtolower(trim((string) ($declaredRaw ?? '')));
        $family = $declared === '' ? self::FAMILY_GENERIC_CLAIMS : $declared;

        $reasons = [];
        $financialKeys = $this->presentFinancialKeys($analysis);

        if (! in_array($family, self::KNOWN_FAMILIES, true)) {
            // Fail-closed: a family this gate has no rules for cannot certify.
            $reasons[] = 'unknown_metric_family';
        }

        // Anti-fake-secure check: financial numbers under a non-financial
        // declaration would be judged by the wrong family's rules.
        if ($financialKeys !== [] && $family !== self::FAMILY_FINANCIAL_BACKTEST) {
            $reasons[] = 'metric_family_mismatch';
        }

        if (in_array($family, self::KNOWN_FAMILIES, true)) {
            $reasons = [...$reasons, ...$this->familyChecks($family, $analysis)];
        }

        $reasons = array_values(array_unique($reasons));
        $certified = $reasons === [];

        return [
            'certified' => $certified,
            'reasons' => $reasons,
            'metric_family' => $family,
            'report' => [
                'declared_metric_family' => $declaredRaw === null ? null : (string) $declaredRaw,
                'resolved_metric_family' => $family,
                'family_known' => in_array($family, self::KNOWN_FAMILIES, true),
                'financial_metric_keys_present' => $financialKeys,
                'checks_failed' => $reasons,
                'deterministic' => true,
                'provider_invoked' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $analysis
     * @return list<string>
     */
    private function familyChecks(string $family, array $analysis): array
    {
        return match ($family) {
            self::FAMILY_FINANCIAL_BACKTEST => $this->financialBacktestChecks($analysis),
            self::FAMILY_ENGINEERING_TESTS => $this->engineeringTestsChecks($analysis),
            default => $this->genericClaimsChecks($analysis),
        };
    }

    /**
     * Financial work must carry deflation/holdout-style evidence; win_rate is
     * never an acceptable headline metric.
     *
     * @param  array<string,mixed>  $analysis
     * @return list<string>
     */
    private function financialBacktestChecks(array $analysis): array
    {
        $reasons = [];

        $nTrials = $this->lookup($analysis, 'n_trials');
        if (! is_numeric($nTrials) || (int) $nTrials < 1) {
            $reasons[] = 'financial_missing_n_trials';
        }

        $outOfSample = $this->lookup($analysis, 'out_of_sample');
        if ($outOfSample === null || $outOfSample === '' || $outOfSample === [] || $outOfSample === false) {
            $reasons[] = 'financial_missing_out_of_sample';
        }

        $headline = strtolower(trim((string) ($analysis['headline_metric'] ?? '')));
        if ($headline === 'win_rate') {
            $reasons[] = 'win_rate_forbidden_as_headline';
        }

        return $reasons;
    }

    /**
     * Engineering test claims need real totals (pass-rate alone hides the
     * denominator) and at least one claim referencing the pass evidence.
     *
     * @param  array<string,mixed>  $analysis
     * @return list<string>
     */
    private function engineeringTestsChecks(array $analysis): array
    {
        $reasons = [];

        $passed = $this->lookup($analysis, 'tests_passed');
        $total = $this->lookup($analysis, 'tests_total');

        if (! is_numeric($passed)) {
            $reasons[] = 'engineering_missing_tests_passed';
        }

        if (is_numeric($passed) && floor((float) $passed) !== (float) $passed) {
            $reasons[] = 'engineering_tests_passed_not_integer';
        }

        if (is_numeric($passed) && (float) $passed < 0) {
            $reasons[] = 'engineering_tests_passed_negative';
        }

        if (! is_numeric($total) || (int) $total < 1) {
            $reasons[] = 'engineering_missing_tests_total';
        }

        if (is_numeric($total) && floor((float) $total) !== (float) $total) {
            $reasons[] = 'engineering_tests_total_not_integer';
        }

        if (is_numeric($passed) && is_numeric($total) && (int) $total >= 1 && (float) $passed > (float) $total) {
            $reasons[] = 'engineering_tests_passed_exceeds_total';
        }

        $passRate = $this->lookup($analysis, 'pass_rate');
        if (is_numeric($passRate) && (! is_numeric($total) || (int) $total < 1)) {
            $reasons[] = 'engineering_pass_rate_without_totals';
        }

        if (! $this->anyClaimCarriesEvidence($analysis)) {
            $reasons[] = 'engineering_pass_evidence_unreferenced';
        }

        return $reasons;
    }

    /**
     * Default family: every claim evidenced + at least one declared limitation.
     *
     * @param  array<string,mixed>  $analysis
     * @return list<string>
     */
    private function genericClaimsChecks(array $analysis): array
    {
        $reasons = [];

        $claims = $analysis['claims'] ?? null;
        if (! is_array($claims) || $claims === []) {
            $reasons[] = 'generic_no_claims_present';
        } else {
            foreach (array_values($claims) as $index => $claim) {
                $refs = is_array($claim) ? ($claim['evidence_refs'] ?? null) : null;
                $hasRefs = is_array($refs)
                    && array_filter($refs, static fn ($ref): bool => trim((string) $ref) !== '') !== [];

                if (! $hasRefs) {
                    $id = is_array($claim) ? trim((string) ($claim['id'] ?? '')) : '';
                    $reasons[] = 'generic_claim_missing_evidence_refs:'.($id !== '' ? $id : 'index_'.$index);
                }
            }
        }

        if (! $this->hasDeclaredLimitation($analysis)) {
            $reasons[] = 'generic_missing_limitations';
        }

        return $reasons;
    }

    /**
     * @param  array<string,mixed>  $analysis
     * @return list<string>
     */
    private function presentFinancialKeys(array $analysis): array
    {
        $present = [];

        foreach (self::FINANCIAL_METRIC_KEYS as $key) {
            if ($this->lookup($analysis, $key) !== null) {
                $present[] = $key;
            }
        }

        return $present;
    }

    /**
     * Look up a field at the analysis top level or inside its `metrics` map.
     *
     * @param  array<string,mixed>  $analysis
     */
    private function lookup(array $analysis, string $key): mixed
    {
        if (array_key_exists($key, $analysis)) {
            return $analysis[$key];
        }

        $metrics = $analysis['metrics'] ?? null;
        if (is_array($metrics) && array_key_exists($key, $metrics)) {
            return $metrics[$key];
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $analysis
     */
    private function anyClaimCarriesEvidence(array $analysis): bool
    {
        $claims = $analysis['claims'] ?? null;
        if (! is_array($claims)) {
            return false;
        }

        foreach ($claims as $claim) {
            if (! is_array($claim)) {
                continue;
            }

            $refs = $claim['evidence_refs'] ?? null;
            if (is_array($refs) && array_filter($refs, static fn ($ref): bool => trim((string) $ref) !== '') !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $analysis
     */
    private function hasDeclaredLimitation(array $analysis): bool
    {
        foreach (['limitations', 'blind_spots'] as $field) {
            $entries = $analysis[$field] ?? null;

            if (is_string($entries) && trim($entries) !== '') {
                return true;
            }

            if (is_array($entries)) {
                foreach ($entries as $entry) {
                    if (is_string($entry) && trim($entry) !== '') {
                        return true;
                    }
                    if (is_array($entry) && $entry !== []) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
