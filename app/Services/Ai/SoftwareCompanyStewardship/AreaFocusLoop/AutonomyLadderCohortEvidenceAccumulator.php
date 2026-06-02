<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S84 — AutonomyLadderCohortEvidenceAccumulator.
 *
 * Counts the L1-L4 real-work cohorts that the Autonomy Ladder promotion runbook
 * requires before a rung may be claimed:
 *
 *   L1 Slice Co-Pilot   -> 20 green slices (must be consecutive)
 *   L2 Multi-Slice Pair -> 30 pair works
 *   L3 Feature Owner    -> 15 certified features
 *   L4 Obra Owner       -> 5 certified Obras with dual_signature_count = 5
 *
 * Only real work is counted. A cycle is rejected (and never counted toward a
 * rung) when it is a dry-run, docs-only, did not advance main, has no judge
 * verdict, or has no provider receipt. There is no artificial compression: the
 * counts are derived purely from the supplied cycles.
 *
 * Pure: no I/O, no clock, no randomness. Every returned field is computed from
 * the method input.
 */
final class AutonomyLadderCohortEvidenceAccumulator
{
    private const SCHEMA_VERSION = 'atlas.loop.autonomy_ladder_cohort_evidence.v1';

    private const RUNG_L1 = 'L1';
    private const RUNG_L2 = 'L2';
    private const RUNG_L3 = 'L3';
    private const RUNG_L4 = 'L4';

    private const KIND_GREEN_SLICE = 'green_slice';
    private const KIND_PAIR_WORK = 'pair_work';
    private const KIND_CERTIFIED_FEATURE = 'certified_feature';
    private const KIND_CERTIFIED_OBRA = 'certified_obra';

    private const REQUIRED_L1 = 20;
    private const REQUIRED_L2 = 30;
    private const REQUIRED_L3 = 15;
    private const REQUIRED_L4 = 5;
    private const REQUIRED_L4_DUAL_SIGNATURE = 5;

    private const REASON_DRY_RUN = 'dry_run';
    private const REASON_DOCS_ONLY = 'docs_only';
    private const REASON_NO_MAIN_ADVANCE = 'no_main_advance';
    private const REASON_MISSING_JUDGE = 'missing_judge';
    private const REASON_MISSING_PROVIDER_RECEIPT = 'missing_provider_receipt';

    /**
     * @param list<array<string, mixed>> $cycles
     *
     * @return array{
     *     schema_version: string,
     *     counted: array{L1: int, L2: int, L3: int, L4: int},
     *     required: array{L1: int, L2: int, L3: int, L4: int},
     *     rejected: array{
     *         dry_run: int,
     *         docs_only: int,
     *         no_main_advance: int,
     *         missing_judge: int,
     *         missing_provider_receipt: int
     *     },
     *     rejected_total: int,
     *     dual_signature_count: int,
     *     consecutive_green: int,
     *     passed: array{L1: bool, L2: bool, L3: bool, L4: bool},
     *     highest_passed_rung: ?string
     * }
     */
    public function accumulate(array $cycles): array
    {
        $counted = [
            self::RUNG_L1 => 0,
            self::RUNG_L2 => 0,
            self::RUNG_L3 => 0,
            self::RUNG_L4 => 0,
        ];

        $rejected = [
            self::REASON_DRY_RUN => 0,
            self::REASON_DOCS_ONLY => 0,
            self::REASON_NO_MAIN_ADVANCE => 0,
            self::REASON_MISSING_JUDGE => 0,
            self::REASON_MISSING_PROVIDER_RECEIPT => 0,
        ];

        $dualSignatureCount = 0;
        $consecutiveGreen = 0;
        $bestConsecutiveGreen = 0;

        foreach ($cycles as $cycle) {
            $rung = $this->rungOf($cycle);
            $isGreenSlice = $rung === self::RUNG_L1;

            $reason = $this->rejectionReason($cycle);

            if ($reason !== null) {
                $rejected[$reason]++;

                // A rejected slice breaks any consecutive-green streak: the
                // L1 exit criterion demands 20 *consecutive* green slices.
                if ($isGreenSlice) {
                    $consecutiveGreen = 0;
                }

                continue;
            }

            if ($rung === null) {
                // Real work that does not map to an L1-L4 cohort does not
                // advance any rung and does not break the L1 streak.
                continue;
            }

            $counted[$rung]++;

            if ($isGreenSlice) {
                $consecutiveGreen++;

                if ($consecutiveGreen > $bestConsecutiveGreen) {
                    $bestConsecutiveGreen = $consecutiveGreen;
                }
            }

            if ($rung === self::RUNG_L4 && $this->hasDualSignature($cycle)) {
                $dualSignatureCount++;
            }
        }

        $passed = [
            self::RUNG_L1 => $bestConsecutiveGreen >= self::REQUIRED_L1,
            self::RUNG_L2 => $counted[self::RUNG_L2] >= self::REQUIRED_L2,
            self::RUNG_L3 => $counted[self::RUNG_L3] >= self::REQUIRED_L3,
            self::RUNG_L4 => $counted[self::RUNG_L4] >= self::REQUIRED_L4
                && $dualSignatureCount >= self::REQUIRED_L4_DUAL_SIGNATURE,
        ];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'counted' => $counted,
            'required' => [
                self::RUNG_L1 => self::REQUIRED_L1,
                self::RUNG_L2 => self::REQUIRED_L2,
                self::RUNG_L3 => self::REQUIRED_L3,
                self::RUNG_L4 => self::REQUIRED_L4,
            ],
            'rejected' => $rejected,
            'rejected_total' => array_sum($rejected),
            'dual_signature_count' => $dualSignatureCount,
            'consecutive_green' => $bestConsecutiveGreen,
            'passed' => $passed,
            'highest_passed_rung' => $this->highestPassedRung($passed),
        ];
    }

    /**
     * Map a cycle to its ladder rung by declared kind/rung, else null.
     *
     * @param array<string, mixed> $cycle
     */
    private function rungOf(array $cycle): ?string
    {
        $rung = $this->stringValue($cycle, 'rung');

        if ($rung !== '') {
            $normalized = strtoupper($rung);

            if (in_array($normalized, [self::RUNG_L1, self::RUNG_L2, self::RUNG_L3, self::RUNG_L4], true)) {
                return $normalized;
            }
        }

        $kind = strtolower($this->stringValue($cycle, 'kind'));

        return match ($kind) {
            self::KIND_GREEN_SLICE => self::RUNG_L1,
            self::KIND_PAIR_WORK => self::RUNG_L2,
            self::KIND_CERTIFIED_FEATURE => self::RUNG_L3,
            self::KIND_CERTIFIED_OBRA => self::RUNG_L4,
            default => null,
        };
    }

    /**
     * First failing real-work gate, in fixed evaluation order, or null when the
     * cycle is genuine real work.
     *
     * @param array<string, mixed> $cycle
     */
    private function rejectionReason(array $cycle): ?string
    {
        if ($this->isDryRun($cycle)) {
            return self::REASON_DRY_RUN;
        }

        if ($this->isDocsOnly($cycle)) {
            return self::REASON_DOCS_ONLY;
        }

        if (! $this->mainAdvanced($cycle)) {
            return self::REASON_NO_MAIN_ADVANCE;
        }

        if (! $this->hasJudge($cycle)) {
            return self::REASON_MISSING_JUDGE;
        }

        if (! $this->hasProviderReceipt($cycle)) {
            return self::REASON_MISSING_PROVIDER_RECEIPT;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $cycle
     */
    private function isDryRun(array $cycle): bool
    {
        if ($this->boolValue($cycle, 'dry_run')) {
            return true;
        }

        if ($this->boolValue($cycle, 'test_mode')) {
            return true;
        }

        return strtolower($this->stringValue($cycle, 'mode')) === self::REASON_DRY_RUN;
    }

    /**
     * @param array<string, mixed> $cycle
     */
    private function isDocsOnly(array $cycle): bool
    {
        if ($this->boolValue($cycle, 'docs_only')) {
            return true;
        }

        return strtolower($this->stringValue($cycle, 'change_shape')) === self::REASON_DOCS_ONLY;
    }

    /**
     * @param array<string, mixed> $cycle
     */
    private function mainAdvanced(array $cycle): bool
    {
        // An explicit boolean main_advanced flag is authoritative.
        $flag = $cycle['main_advanced'] ?? null;

        if (is_bool($flag)) {
            return $flag;
        }

        // Otherwise fall back to the recorded main HEAD hashes. This branch is
        // reached both when main_advanced is absent and when it is present but
        // not an explicit boolean (e.g. null), so a real commit whose hashes
        // genuinely differ is never miscounted as no_main_advance.
        $before = $this->stringValue($cycle, 'main_before');
        $after = $this->stringValue($cycle, 'main_after');

        if ($before !== '' || $after !== '') {
            return $before !== '' && $after !== '' && $before !== $after;
        }

        // No main-advance signal supplied at all -> treat as no advance.
        return false;
    }

    /**
     * @param array<string, mixed> $cycle
     */
    private function hasJudge(array $cycle): bool
    {
        if ($this->boolValue($cycle, 'judge_present')) {
            return true;
        }

        return $this->stringValue($cycle, 'judge_verdict') !== '';
    }

    /**
     * @param array<string, mixed> $cycle
     */
    private function hasProviderReceipt(array $cycle): bool
    {
        if ($this->boolValue($cycle, 'provider_receipt_present')) {
            return true;
        }

        return $this->stringValue($cycle, 'provider_receipt_id') !== '';
    }

    /**
     * @param array<string, mixed> $cycle
     */
    private function hasDualSignature(array $cycle): bool
    {
        if ($this->boolValue($cycle, 'dual_signature')) {
            return true;
        }

        return $this->stringValue($cycle, 'operator_signature') !== ''
            && $this->stringValue($cycle, 'architect_signature') !== '';
    }

    /**
     * @param array{L1: bool, L2: bool, L3: bool, L4: bool} $passed
     */
    private function highestPassedRung(array $passed): ?string
    {
        $highest = null;

        foreach ([self::RUNG_L1, self::RUNG_L2, self::RUNG_L3, self::RUNG_L4] as $rung) {
            if ($passed[$rung]) {
                $highest = $rung;
            }
        }

        return $highest;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function boolValue(array $payload, string $key): bool
    {
        return ($payload[$key] ?? false) === true;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function stringValue(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) ? trim($value) : '';
    }
}
