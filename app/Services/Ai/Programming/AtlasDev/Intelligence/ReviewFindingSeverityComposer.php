<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Intelligence;

use InvalidArgumentException;

/**
 * Pure evidence-to-severity composer for Atlas Dev Review Intelligence.
 *
 * Given the raw evidence a reviewer would weigh on a single finding —
 * exploitability, blast radius, reversibility, confidence, whether the change
 * lands on a security-sensitive path and whether a remediation exists — this
 * collapses them into one deterministic severity verdict. Same input always
 * yields the same output; nothing here touches I/O, the clock, randomness or
 * any collaborator. The severity vocabulary and rank precedence mirror
 * ReviewFinding::ALLOWED_SEVERITIES / SEVERITY_RANK byte-for-byte (own copies
 * are kept here on purpose so this stays pure-logic and import-free).
 */
final class ReviewFindingSeverityComposer
{
    public const SCHEMA_VERSION = 'atlas.dev.intelligence.review_finding_severity.v1';

    public const SEVERITY_BLOCKER = 'blocker';

    public const SEVERITY_CRITICAL = 'critical';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_LOW = 'low';

    public const SEVERITY_INFO = 'info';

    /** Mirrors ReviewFinding::ALLOWED_SEVERITIES (own copy to stay pure-logic). */
    public const ALLOWED_SEVERITIES = [
        self::SEVERITY_BLOCKER,
        self::SEVERITY_CRITICAL,
        self::SEVERITY_HIGH,
        self::SEVERITY_MEDIUM,
        self::SEVERITY_LOW,
        self::SEVERITY_INFO,
    ];

    /** Mirrors ReviewFinding::SEVERITY_RANK (0=blocker .. 5=info). */
    public const SEVERITY_RANK = [
        self::SEVERITY_BLOCKER => 0,
        self::SEVERITY_CRITICAL => 1,
        self::SEVERITY_HIGH => 2,
        self::SEVERITY_MEDIUM => 3,
        self::SEVERITY_LOW => 4,
        self::SEVERITY_INFO => 5,
    ];

    /** Risk classes that carry the heavy +0.15 composite weight. */
    private const HEAVY_RISK_TYPES = [
        'security',
        'data_loss',
        'secret_leak',
    ];

    private const WEIGHT_EXPLOITABILITY = 0.40;

    private const WEIGHT_BLAST_RADIUS = 0.20;

    private const WEIGHT_REVERSIBILITY = 0.20;

    private const WEIGHT_CONFIDENCE = 0.20;

    private const HEAVY_RISK_BUMP = 0.15;

    private const SENSITIVE_PATH_BUMP = 0.10;

    private const BLAST_RADIUS_CAP_FILES = 10;

    /** Score thresholds, worst-first, mapped to severity bands. */
    private const SCORE_THRESHOLDS = [
        [0.90, self::SEVERITY_BLOCKER],
        [0.75, self::SEVERITY_CRITICAL],
        [0.60, self::SEVERITY_HIGH],
        [0.40, self::SEVERITY_MEDIUM],
        [0.20, self::SEVERITY_LOW],
    ];

    /** Below this confidence the verdict is downgraded one rank toward info. */
    private const LOW_CONFIDENCE_THRESHOLD = 0.35;

    /** A security-sensitive path floors the verdict to at least critical. */
    private const SENSITIVE_PATH_FLOOR_RANK = 1;

    /** Blocker/critical without remediation is capped to high. */
    private const REMEDIATION_CAP_SEVERITY = self::SEVERITY_HIGH;

    /**
     * @param  array{
     *     risk_type: string,
     *     exploitability: float,
     *     blast_radius_files: int,
     *     reversibility: float,
     *     in_security_sensitive_path: bool,
     *     confidence: float,
     *     has_remediation: bool
     * }  $signals
     * @return array{
     *     schema_version: string,
     *     severity: string,
     *     severity_rank: int,
     *     score: float,
     *     reasons: list<string>,
     *     downgraded_due_to_low_confidence: bool
     * }
     */
    public function compose(array $signals): array
    {
        $riskType = $this->stringSignal($signals, 'risk_type');
        $exploitability = $this->unitFloatSignal($signals, 'exploitability');
        $reversibility = $this->unitFloatSignal($signals, 'reversibility');
        $confidence = $this->unitFloatSignal($signals, 'confidence');
        $blastRadiusFiles = $this->nonNegativeIntSignal($signals, 'blast_radius_files');
        $inSensitivePath = $this->boolSignal($signals, 'in_security_sensitive_path');
        $hasRemediation = $this->boolSignal($signals, 'has_remediation');

        if ($riskType === '') {
            throw new InvalidArgumentException('ReviewFindingSeverityComposer: risk_type must not be empty.');
        }

        $isHeavyRisk = in_array($riskType, self::HEAVY_RISK_TYPES, true);

        $score = $this->composite(
            $exploitability,
            $blastRadiusFiles,
            $reversibility,
            $confidence,
            $isHeavyRisk,
            $inSensitivePath,
        );

        $thresholdSeverity = $this->severityForScore($score);
        $rank = self::SEVERITY_RANK[$thresholdSeverity];

        $reasons = ['threshold:'.$thresholdSeverity];

        $floorApplies = $inSensitivePath && $isHeavyRisk;
        $downgradeApplies = $confidence < self::LOW_CONFIDENCE_THRESHOLD;

        if ($downgradeApplies) {
            $rank = min($rank + 1, self::SEVERITY_RANK[self::SEVERITY_INFO]);
        }

        $capApplies = $rank <= self::SEVERITY_RANK[self::SEVERITY_CRITICAL] && ! $hasRemediation;
        if ($capApplies) {
            $rank = self::SEVERITY_RANK[self::REMEDIATION_CAP_SEVERITY];
        }

        // Hard floor wins last: a security-sensitive injection is at least critical.
        if ($floorApplies) {
            $rank = min($rank, self::SENSITIVE_PATH_FLOOR_RANK);
        }

        if ($floorApplies) {
            $reasons[] = 'floor:sensitive_path_injection';
        }
        if ($downgradeApplies) {
            $reasons[] = 'downgrade:low_confidence';
        }
        if ($capApplies) {
            $reasons[] = 'invariant:remediation_required_capped';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'severity' => self::ALLOWED_SEVERITIES[$rank],
            'severity_rank' => $rank,
            'score' => $score,
            'reasons' => $reasons,
            'downgraded_due_to_low_confidence' => $downgradeApplies,
        ];
    }

    private function composite(
        float $exploitability,
        int $blastRadiusFiles,
        float $reversibility,
        float $confidence,
        bool $isHeavyRisk,
        bool $inSensitivePath,
    ): float {
        $blastFraction = min($blastRadiusFiles / self::BLAST_RADIUS_CAP_FILES, 1.0);

        $score = self::WEIGHT_EXPLOITABILITY * $exploitability
            + self::WEIGHT_BLAST_RADIUS * $blastFraction
            + self::WEIGHT_REVERSIBILITY * (1.0 - $reversibility)
            + self::WEIGHT_CONFIDENCE * $confidence;

        if ($isHeavyRisk) {
            $score += self::HEAVY_RISK_BUMP;
        }
        if ($inSensitivePath) {
            $score += self::SENSITIVE_PATH_BUMP;
        }

        return round(min($score, 1.0), 4);
    }

    private function severityForScore(float $score): string
    {
        foreach (self::SCORE_THRESHOLDS as [$threshold, $severity]) {
            if ($score >= $threshold) {
                return $severity;
            }
        }

        return self::SEVERITY_INFO;
    }

    private function stringSignal(array $signals, string $key): string
    {
        $value = $signals[$key] ?? null;

        if (! is_string($value)) {
            throw new InvalidArgumentException(
                "ReviewFindingSeverityComposer: '{$key}' must be a string."
            );
        }

        return trim($value);
    }

    private function unitFloatSignal(array $signals, string $key): float
    {
        $value = $signals[$key] ?? null;

        if (! is_int($value) && ! is_float($value)) {
            throw new InvalidArgumentException(
                "ReviewFindingSeverityComposer: '{$key}' must be a number in [0.0, 1.0]."
            );
        }

        $float = (float) $value;

        if ($float < 0.0 || $float > 1.0) {
            throw new InvalidArgumentException(
                "ReviewFindingSeverityComposer: '{$key}' must be in [0.0, 1.0], got {$float}."
            );
        }

        return $float;
    }

    private function nonNegativeIntSignal(array $signals, string $key): int
    {
        $value = $signals[$key] ?? null;

        if (! is_int($value)) {
            throw new InvalidArgumentException(
                "ReviewFindingSeverityComposer: '{$key}' must be an integer >= 0."
            );
        }

        if ($value < 0) {
            throw new InvalidArgumentException(
                "ReviewFindingSeverityComposer: '{$key}' must be >= 0, got {$value}."
            );
        }

        return $value;
    }

    private function boolSignal(array $signals, string $key): bool
    {
        $value = $signals[$key] ?? null;

        if (! is_bool($value)) {
            throw new InvalidArgumentException(
                "ReviewFindingSeverityComposer: '{$key}' must be a boolean."
            );
        }

        return $value;
    }
}
