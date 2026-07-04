<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\ProviderLearning;

/**
 * FACT-only ledger of per-(provider, task_class) outcomes. Tracks aggregates ONLY:
 *   success_count, give_back_count, duration_ms_sum, duration_ms_count, last_outcome_at.
 *
 * NO scoring, ranking, auto-routing — those are higher-layer concerns.
 *
 * Persistence: single JSON snapshot under storage/atlas/maestro/provider_performance_ledger.json
 * (following the AtlasLoopProjectionOutcomeLedger pattern). Byte-identical writes when no fact
 * changed: aggregates are kept in canonical sorted key order so the serialized blob is stable.
 */
final class AtlasMaestroProviderPerformanceLedger
{
    public const OUTCOME_SUCCESS = 'success';
    public const OUTCOME_GIVE_BACK = 'give_back';

    /** Atlas-native execution: zero provider cost, tracked as first-class provider. */
    public const ATLAS_NATIVE = 'atlas_native';

    public const PROOF_PASSED = 'passed';
    public const PROOF_FAILED = 'failed';

    /** AC4: below this sample size, evidence is too sparse to drive hard routing. */
    private const SPARSE_SAMPLE_THRESHOLD = 5;

    /** AC4: at/above this sample size (and non-contradictory), evidence supports high confidence. */
    private const HIGH_SAMPLE_THRESHOLD = 20;

    /** AC4: a give_back rate in this band means the evidence itself disagrees with itself. */
    private const CONTRADICTORY_RATE_LOW = 0.35;

    private const CONTRADICTORY_RATE_HIGH = 0.65;

    private static ?string $rootOverride = null;

    public static function setRootForTesting(?string $root): void
    {
        self::$rootOverride = $root;
    }

    public function recordOutcome(
        string $provider,
        string $taskClass,
        string $outcome,
        int $durationMs,
        int $lastOutcomeAt,
        ?string $taskFamily = null,
        ?string $modelTier = null,
        ?int $tokenCostEstimate = null,
        ?bool $hasRequiredEvidence = null,
        ?string $workerClass = null,
        ?string $proofResult = null,
    ): void {
        $this->withLockedFile(function (array $state) use ($provider, $taskClass, $outcome, $durationMs, $lastOutcomeAt, $taskFamily, $modelTier, $tokenCostEstimate, $hasRequiredEvidence, $workerClass, $proofResult): array {
            $facts = $state['facts'] ?? [];
            $facts[$taskClass] ??= [];
            $row = $facts[$taskClass][$provider] ?? [
                'success_count' => 0,
                'give_back_count' => 0,
                'duration_ms_sum' => 0,
                'duration_ms_count' => 0,
                'last_outcome_at' => 0,
                'token_cost_sum' => 0,
                'has_required_evidence_count' => 0,
                'proof_passed_count' => 0,
                'proof_failed_count' => 0,
            ];
            $knownOutcome = false;
            if ($outcome === self::OUTCOME_SUCCESS) {
                $row['success_count']++;
                $knownOutcome = true;
            } elseif ($outcome === self::OUTCOME_GIVE_BACK) {
                $row['give_back_count']++;
                $knownOutcome = true;
            }
            if ($knownOutcome && $durationMs >= 0) {
                $row['duration_ms_sum'] += $durationMs;
                $row['duration_ms_count']++;
            }
            if ($lastOutcomeAt > (int) $row['last_outcome_at']) {
                $row['last_outcome_at'] = $lastOutcomeAt;
            }
            if ($taskFamily !== null) {
                $row['task_family'] = $taskFamily;
            }
            if ($modelTier !== null) {
                $row['model_tier'] = $modelTier;
            }
            if ($tokenCostEstimate !== null && $tokenCostEstimate >= 0) {
                $row['token_cost_sum'] = (int) ($row['token_cost_sum'] ?? 0) + $tokenCostEstimate;
            }
            if ($hasRequiredEvidence === true) {
                $row['has_required_evidence_count'] = (int) ($row['has_required_evidence_count'] ?? 0) + 1;
            }
            if ($workerClass !== null) {
                $row['worker_class'] = $workerClass;
            }
            if ($proofResult === self::PROOF_PASSED) {
                $row['proof_passed_count'] = (int) ($row['proof_passed_count'] ?? 0) + 1;
            } elseif ($proofResult === self::PROOF_FAILED) {
                $row['proof_failed_count'] = (int) ($row['proof_failed_count'] ?? 0) + 1;
            }
            ksort($row);
            $facts[$taskClass][$provider] = $row;

            // family-level index
            if ($taskFamily !== null) {
                $familyFacts = (array) ($state['family_facts'] ?? []);
                $familyFacts[$taskFamily] ??= [];
                $frow = $familyFacts[$taskFamily][$provider] ?? [
                    'success_count' => 0,
                    'give_back_count' => 0,
                    'duration_ms_sum' => 0,
                    'duration_ms_count' => 0,
                ];
                if ($outcome === self::OUTCOME_SUCCESS) {
                    $frow['success_count']++;
                } elseif ($outcome === self::OUTCOME_GIVE_BACK) {
                    $frow['give_back_count']++;
                }
                if ($knownOutcome && $durationMs >= 0) {
                    $frow['duration_ms_sum'] += $durationMs;
                    $frow['duration_ms_count']++;
                }
                ksort($frow);
                $familyFacts[$taskFamily][$provider] = $frow;
                $state['family_facts'] = $familyFacts;
            }

            $state['facts'] = $facts;

            return $state;
        });
    }

    /**
     * @return array<string, array<string,mixed>>
     */
    public function factsForClass(string $taskClass): array
    {
        $state = $this->load();
        $classFacts = (array) ($state['facts'][$taskClass] ?? []);
        $out = [];
        foreach ($classFacts as $provider => $row) {
            $out[(string) $provider] = $this->enrichRow((array) $row);
        }
        ksort($out);

        return $out;
    }

    /**
     * @return array<string, array<string,mixed>>
     */
    public function factsForProvider(string $provider): array
    {
        $state = $this->load();
        $out = [];
        foreach ((array) ($state['facts'] ?? []) as $taskClass => $byProvider) {
            if (! is_array($byProvider) || ! isset($byProvider[$provider])) {
                continue;
            }
            $out[(string) $taskClass] = $this->enrichRow((array) $byProvider[$provider]);
        }
        ksort($out);

        return $out;
    }

    /**
     * Family-level read surface: aggregate facts by task_family.
     *
     * @return array<string, array<string,mixed>>
     */
    public function factsForFamily(string $taskFamily): array
    {
        $state = $this->load();
        $familyFacts = (array) ($state['family_facts'][$taskFamily] ?? []);
        $out = [];
        foreach ($familyFacts as $provider => $row) {
            $out[(string) $provider] = $this->enrichRow((array) $row);
        }
        ksort($out);

        return $out;
    }

    /**
     * AC3/AC4: adds avg_duration_ms, sample_size and confidence to a raw aggregate row —
     * confidence downgrades to 'low' for sparse OR internally contradictory evidence, so
     * a caller never mistakes a thin or self-disagreeing sample for a routing-grade signal.
     *
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function enrichRow(array $row): array
    {
        $row['avg_duration_ms'] = ((int) ($row['duration_ms_count'] ?? 0)) > 0
            ? (int) round(((int) ($row['duration_ms_sum'] ?? 0)) / ((int) ($row['duration_ms_count'] ?? 0)))
            : 0;

        $successCount = (int) ($row['success_count'] ?? 0);
        $giveBackCount = (int) ($row['give_back_count'] ?? 0);
        $sampleSize = $successCount + $giveBackCount;
        $row['sample_size'] = $sampleSize;

        if ($sampleSize < self::SPARSE_SAMPLE_THRESHOLD) {
            $confidence = 'low';
        } else {
            $giveBackRate = $giveBackCount / $sampleSize;
            $contradictory = $giveBackRate >= self::CONTRADICTORY_RATE_LOW && $giveBackRate <= self::CONTRADICTORY_RATE_HIGH;
            $confidence = match (true) {
                $contradictory => 'low',
                $sampleSize >= self::HIGH_SAMPLE_THRESHOLD => 'high',
                default => 'medium',
            };
        }
        $row['confidence'] = $confidence;

        return $row;
    }

    /**
     * Evidence-weighted performance facts: discounts success outcomes without runnable tests
     * or implementation evidence.
     *
     * @param  string  $taskClass
     * @return array<string, array{evidence_weighted_success_rate:float, give_back_rate:float, poison_rate:float, routing_confidence:string}>
     */
    public function evidenceWeightedFacts(string $taskClass): array
    {
        $state = $this->load();
        $classFacts = (array) ($state['facts'][$taskClass] ?? []);
        $out = [];

        foreach ($classFacts as $provider => $row) {
            $row = (array) $row;
            $successCount = (int) ($row['success_count'] ?? 0);
            $giveBackCount = (int) ($row['give_back_count'] ?? 0);
            $evidenceCount = (int) ($row['has_required_evidence_count'] ?? 0);
            $proofPassed = (int) ($row['proof_passed_count'] ?? 0);
            $proofFailed = (int) ($row['proof_failed_count'] ?? 0);

            $totalOutcomes = $successCount + $giveBackCount;
            if ($totalOutcomes === 0) {
                $out[$provider] = [
                    'evidence_weighted_success_rate' => 0.0,
                    'give_back_rate' => 0.0,
                    'poison_rate' => 0.0,
                    'routing_confidence' => 'unknown',
                ];
                continue;
            }

            // Evidence-weighted success: only count successes with evidence
            $evidenceWeightedSuccess = min($successCount, $evidenceCount);
            $evidenceWeightedSuccessRate = $evidenceWeightedSuccess / $totalOutcomes;

            // Give-back rate
            $giveBackRate = $giveBackCount / $totalOutcomes;

            // Poison rate: give_back + proof failures relative to total
            $poisonCount = $giveBackCount + $proofFailed;
            $poisonRate = $poisonCount / $totalOutcomes;

            // Routing confidence based on evidence quality
            $routingConfidence = $this->computeRoutingConfidence(
                $totalOutcomes,
                $evidenceCount,
                $proofPassed,
                $proofFailed,
                $giveBackRate,
            );

            $out[$provider] = [
                'evidence_weighted_success_rate' => round($evidenceWeightedSuccessRate, 4),
                'give_back_rate' => round($giveBackRate, 4),
                'poison_rate' => round($poisonRate, 4),
                'routing_confidence' => $routingConfidence,
            ];
        }

        ksort($out);

        return $out;
    }

    /**
     * Compute routing confidence from evidence signals.
     */
    private function computeRoutingConfidence(
        int $totalOutcomes,
        int $evidenceCount,
        int $proofPassed,
        int $proofFailed,
        float $giveBackRate,
    ): string {
        // Too few outcomes
        if ($totalOutcomes < self::SPARSE_SAMPLE_THRESHOLD) {
            return 'unknown';
        }

        // High evidence coverage and proven proofs
        $evidenceCoverage = $evidenceCount / max(1, $totalOutcomes);
        if ($evidenceCoverage >= 0.8 && $proofPassed >= 3 && $giveBackRate < 0.2) {
            return 'high';
        }

        // Contradictory evidence
        if ($giveBackRate >= self::CONTRADICTORY_RATE_LOW && $giveBackRate <= self::CONTRADICTORY_RATE_HIGH) {
            return 'low';
        }

        // Moderate evidence
        if ($evidenceCoverage >= 0.5 && $totalOutcomes >= self::SPARSE_SAMPLE_THRESHOLD) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * @return array<string, array<string, array<string,mixed>>>
     */
    public function allFacts(): array
    {
        $state = $this->load();
        $all = (array) ($state['facts'] ?? []);
        ksort($all);
        foreach ($all as $taskClass => &$byProvider) {
            ksort($byProvider);
        }
        unset($byProvider);

        return $all;
    }

    public function snapshotPath(): string
    {
        $root = self::$rootOverride ?? storage_path('atlas/maestro');

        return rtrim($root, '/').'/provider_performance_ledger.json';
    }

    /**
     * @return array<string,mixed>
     */
    private function load(): array
    {
        $path = $this->snapshotPath();
        if (! is_file($path)) {
            return ['facts' => []];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : ['facts' => []];
    }

    /**
     * @param  callable(array<string,mixed>): array<string,mixed>  $mutator
     */
    private function withLockedFile(callable $mutator): void
    {
        $path = $this->snapshotPath();
        $dir = \dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            return;
        }
        $fh = @fopen($path, 'cb+');
        if ($fh === false) {
            return;
        }
        try {
            @flock($fh, LOCK_EX);
            $raw = stream_get_contents($fh);
            $state = is_string($raw) && $raw !== '' ? (array) json_decode($raw, true) : ['facts' => []];
            $next = $mutator(is_array($state) ? $state : ['facts' => []]);
            $next = $this->canonicalize($next);
            $encoded = (string) json_encode($next, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, $encoded);
        } finally {
            @flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * @param  array<string,mixed>  $state
     * @return array<string,mixed>
     */
    private function canonicalize(array $state): array
    {
        $facts = (array) ($state['facts'] ?? []);
        ksort($facts);
        foreach ($facts as &$byProvider) {
            if (! is_array($byProvider)) {
                continue;
            }
            ksort($byProvider);
            foreach ($byProvider as &$row) {
                if (is_array($row)) {
                    ksort($row);
                }
            }
            unset($row);
        }
        unset($byProvider);
        $state['facts'] = $facts;
        ksort($state);

        return $state;
    }
}
