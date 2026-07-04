<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Dry-runs a proposed task batch against synthetic queue/worker/gate outcomes before enqueueing.
 * Produces a risk verdict so expensive bad batches are blocked before they start.
 *
 * RISK FLAGS (any one → verdict = risky):
 *   give_back_pressure           — simulated give_back_rate >= give_back_rate_threshold
 *   forbidden_target_pressure    — any packet has is_forbidden_target = true
 *   queue_oversaturation         — batch_size + current_queue_depth > saturation_limit
 *   low_compounding_value        — avg compounding value of batch < compounding_value_floor
 *   insufficient_worker_capacity — worker_capacity < batch_size
 *   write_set_collision          — two or more packets share a file in their write_set
 *   insufficient_verification_evidence — verification_evidence_coverage < verification_evidence_threshold
 *
 * VERDICT = acceptable when zero flags triggered.
 *
 * INPUT:
 *   batch:                          list<{ packet_id, is_forbidden_target?, estimated_compounding_value?, write_set? }>
 *   worker_capacity:                int   (default 0)
 *   current_queue_depth:            int   (default 0)
 *   saturation_limit?:              int   (default 100)
 *   give_back_rate?:                float (default 0.0)
 *   give_back_rate_threshold?:      float (default 0.30)
 *   verification_evidence_coverage?: float (default 1.0)
 *   verification_evidence_threshold?: float (default 0.70)
 *   compounding_value_floor?:       float (default 0.20)
 *
 * OUTPUT:
 *   { schema, verdict, risk_flags, risk_reasons, simulation_summary }
 *
 * PURE / DETERMINISTIC / NO I/O. Does NOT enqueue tasks.
 */
final class AtlasExternalBrainSimulationTwinPlanProbe
{
    public const SCHEMA = 'atlas.external_brain.simulation_twin_plan_probe.v1';

    public const VERDICT_ACCEPTABLE = 'acceptable';
    public const VERDICT_RISKY      = 'risky';

    public const FLAG_GIVE_BACK_PRESSURE             = 'give_back_pressure';
    public const FLAG_FORBIDDEN_TARGET_PRESSURE      = 'forbidden_target_pressure';
    public const FLAG_QUEUE_OVERSATURATION           = 'queue_oversaturation';
    public const FLAG_LOW_COMPOUNDING_VALUE          = 'low_compounding_value';
    public const FLAG_INSUFFICIENT_WORKER_CAPACITY   = 'insufficient_worker_capacity';
    public const FLAG_WRITE_SET_COLLISION            = 'write_set_collision';
    public const FLAG_INSUFFICIENT_VERIFICATION_EVIDENCE = 'insufficient_verification_evidence';
    public const FLAG_DUPLICATE_TARGET               = 'duplicate_target';
    public const FLAG_CONTRADICTION_RISK             = 'contradiction_risk';
    public const FLAG_MISSING_FILES                  = 'missing_implementation_or_test_file';
    public const FLAG_LOW_VALUE_DENSITY              = 'low_value_density';

    private const DEFAULT_SATURATION_LIMIT                = 100;
    private const DEFAULT_GIVE_BACK_RATE_THRESHOLD        = 0.30;
    private const DEFAULT_VERIFICATION_EVIDENCE_THRESHOLD = 0.70;
    private const DEFAULT_COMPOUNDING_VALUE_FLOOR         = 0.20;
    private const DEFAULT_CONTRADICTION_RISK_THRESHOLD    = 0.50;
    private const DEFAULT_VALUE_DENSITY_FLOOR             = 0.30;

    public const OUTCOME_ENQUEUE = 'enqueue';
    public const OUTCOME_REPAIR  = 'repair';
    public const OUTCOME_SPLIT   = 'split';
    public const OUTCOME_DEFER   = 'defer';
    public const OUTCOME_REJECT  = 'reject';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function probe(array $input): array
    {
        $batch                   = is_array($input['batch'] ?? null) ? $input['batch'] : [];
        $workerCapacity          = max(0, (int) ($input['worker_capacity'] ?? 0));
        $currentQueueDepth       = max(0, (int) ($input['current_queue_depth'] ?? 0));
        $saturationLimit         = max(1, (int) ($input['saturation_limit'] ?? self::DEFAULT_SATURATION_LIMIT));
        $giveBackRate            = (float) ($input['give_back_rate'] ?? 0.0);
        $giveBackThreshold       = (float) ($input['give_back_rate_threshold'] ?? self::DEFAULT_GIVE_BACK_RATE_THRESHOLD);
        $evidenceCoverage        = (float) ($input['verification_evidence_coverage'] ?? 1.0);
        $evidenceThreshold       = (float) ($input['verification_evidence_threshold'] ?? self::DEFAULT_VERIFICATION_EVIDENCE_THRESHOLD);
        $compoundingFloor        = (float) ($input['compounding_value_floor'] ?? self::DEFAULT_COMPOUNDING_VALUE_FLOOR);
        $contradictionThreshold  = (float) ($input['contradiction_risk_threshold'] ?? self::DEFAULT_CONTRADICTION_RISK_THRESHOLD);
        $valueDensityFloor       = (float) ($input['value_density_floor'] ?? self::DEFAULT_VALUE_DENSITY_FLOOR);
        $existingTargetPaths     = [];
        foreach ((is_array($input['existing_target_paths'] ?? null) ? $input['existing_target_paths'] : []) as $path) {
            $existingTargetPaths[(string) $path] = true;
        }

        // Normalise batch packets.
        $packets = [];
        foreach ($batch as $p) {
            if (is_array($p) && isset($p['packet_id'])) {
                $packets[] = $p;
            }
        }

        $batchSize            = count($packets);
        $forbiddenCount       = 0;
        $compoundingTotal     = 0.0;
        $seenFiles            = [];
        $writeSetCollision    = false;
        $seenTargetPaths      = $existingTargetPaths;
        $perCandidatePredictions = [];
        $duplicateTargetCount        = 0;
        $missingImplementationCount  = 0;
        $missingTestFileCount        = 0;
        $highContradictionRiskCount  = 0;
        $lowValueDensityCount        = 0;

        foreach ($packets as $p) {
            if ((bool) ($p['is_forbidden_target'] ?? false)) {
                $forbiddenCount++;
            }

            $compoundingTotal += (float) ($p['estimated_compounding_value'] ?? 0.0);

            $writeSet = is_array($p['write_set'] ?? null) ? $p['write_set'] : [];
            foreach ($writeSet as $file) {
                $file = (string) $file;
                if (isset($seenFiles[$file])) {
                    $writeSetCollision = true;
                }
                $seenFiles[$file] = true;
            }

            // AC2/AC3: per-candidate outcome prediction (additive to the batch-level risk flags above).
            $targetPath          = (string) ($p['target_path'] ?? '');
            $hasImplementation   = (bool) ($p['has_implementation_file'] ?? true);
            $hasTestFile         = (bool) ($p['has_test_file'] ?? true);
            $contradictionRisk   = max(0.0, min(1.0, (float) ($p['contradiction_risk_score'] ?? 0.0)));
            $valueDensity        = max(0.0, (float) ($p['value_density'] ?? 1.0));

            $isDuplicateTarget = $targetPath !== '' && isset($seenTargetPaths[$targetPath]);
            if ($targetPath !== '') {
                $seenTargetPaths[$targetPath] = true;
            }

            $isHighContradictionRisk = $contradictionRisk >= $contradictionThreshold;
            $isLowValueDensity       = $valueDensity < $valueDensityFloor;

            if ($isDuplicateTarget) {
                $duplicateTargetCount++;
            }
            if (! $hasImplementation) {
                $missingImplementationCount++;
            }
            if (! $hasTestFile) {
                $missingTestFileCount++;
            }
            if ($isHighContradictionRisk) {
                $highContradictionRiskCount++;
            }
            if ($isLowValueDensity) {
                $lowValueDensityCount++;
            }

            $outcome  = self::OUTCOME_ENQUEUE;
            $evidence = [];
            if ($isDuplicateTarget) {
                $outcome    = self::OUTCOME_REJECT;
                $evidence[] = "duplicate_target_path={$targetPath}";
            } elseif ($isHighContradictionRisk) {
                $outcome    = self::OUTCOME_DEFER;
                $evidence[] = sprintf('contradiction_risk_score=%.2f >= threshold=%.2f', $contradictionRisk, $contradictionThreshold);
            } elseif (! $hasImplementation && ! $hasTestFile) {
                $outcome    = self::OUTCOME_DEFER;
                $evidence[] = 'missing_implementation_file_and_test_file';
            } elseif (! $hasImplementation || ! $hasTestFile) {
                $outcome    = self::OUTCOME_REPAIR;
                $evidence[] = ! $hasImplementation ? 'missing_implementation_file' : 'missing_test_file';
            } elseif ($isLowValueDensity) {
                $outcome    = self::OUTCOME_SPLIT;
                $evidence[] = sprintf('value_density=%.2f < floor=%.2f', $valueDensity, $valueDensityFloor);
            }

            $perCandidatePredictions[] = [
                'packet_id'         => (string) $p['packet_id'],
                'predicted_outcome' => $outcome,
                'evidence'          => $evidence,
            ];
        }

        $avgCompounding        = $batchSize > 0 ? $compoundingTotal / $batchSize : 0.0;
        $projectedGiveBackCount = $batchSize > 0 ? (int) round($giveBackRate * $batchSize) : 0;
        $canWorkerHandle       = $workerCapacity >= $batchSize;

        // Accumulate flags.
        $riskFlags   = [];
        $riskReasons = [];

        if ($giveBackRate >= $giveBackThreshold) {
            $riskFlags[]   = self::FLAG_GIVE_BACK_PRESSURE;
            $riskReasons[] = sprintf(
                'give_back_rate=%.2f >= threshold=%.2f',
                $giveBackRate,
                $giveBackThreshold,
            );
        }

        if ($forbiddenCount > 0) {
            $riskFlags[]   = self::FLAG_FORBIDDEN_TARGET_PRESSURE;
            $riskReasons[] = sprintf('forbidden_target_count=%d', $forbiddenCount);
        }

        if ($batchSize + $currentQueueDepth > $saturationLimit) {
            $riskFlags[]   = self::FLAG_QUEUE_OVERSATURATION;
            $riskReasons[] = sprintf(
                'batch_size(%d) + queue_depth(%d) = %d > saturation_limit(%d)',
                $batchSize,
                $currentQueueDepth,
                $batchSize + $currentQueueDepth,
                $saturationLimit,
            );
        }

        if ($batchSize > 0 && $avgCompounding < $compoundingFloor) {
            $riskFlags[]   = self::FLAG_LOW_COMPOUNDING_VALUE;
            $riskReasons[] = sprintf(
                'avg_compounding_value=%.3f < floor=%.3f',
                $avgCompounding,
                $compoundingFloor,
            );
        }

        if ($batchSize > 0 && ! $canWorkerHandle) {
            $riskFlags[]   = self::FLAG_INSUFFICIENT_WORKER_CAPACITY;
            $riskReasons[] = sprintf(
                'worker_capacity=%d < batch_size=%d',
                $workerCapacity,
                $batchSize,
            );
        }

        if ($writeSetCollision) {
            $riskFlags[]   = self::FLAG_WRITE_SET_COLLISION;
            $riskReasons[] = 'two or more packets share files in their write_set';
        }

        if ($batchSize > 0 && $evidenceCoverage < $evidenceThreshold) {
            $riskFlags[]   = self::FLAG_INSUFFICIENT_VERIFICATION_EVIDENCE;
            $riskReasons[] = sprintf(
                'verification_evidence_coverage=%.2f < threshold=%.2f',
                $evidenceCoverage,
                $evidenceThreshold,
            );
        }

        if ($duplicateTargetCount > 0) {
            $riskFlags[]   = self::FLAG_DUPLICATE_TARGET;
            $riskReasons[] = sprintf('duplicate_target_count=%d', $duplicateTargetCount);
        }

        if ($highContradictionRiskCount > 0) {
            $riskFlags[]   = self::FLAG_CONTRADICTION_RISK;
            $riskReasons[] = sprintf('high_contradiction_risk_count=%d', $highContradictionRiskCount);
        }

        if ($missingImplementationCount > 0 || $missingTestFileCount > 0) {
            $riskFlags[]   = self::FLAG_MISSING_FILES;
            $riskReasons[] = sprintf(
                'missing_implementation_file_count=%d missing_test_file_count=%d',
                $missingImplementationCount,
                $missingTestFileCount,
            );
        }

        if ($lowValueDensityCount > 0) {
            $riskFlags[]   = self::FLAG_LOW_VALUE_DENSITY;
            $riskReasons[] = sprintf('low_value_density_count=%d', $lowValueDensityCount);
        }

        // AC3: top-level recommended_outcome — most conservative per-candidate outcome
        // dominates (reject > defer > split > repair > enqueue).
        $outcomePriority = [
            self::OUTCOME_ENQUEUE => 0,
            self::OUTCOME_REPAIR  => 1,
            self::OUTCOME_SPLIT   => 2,
            self::OUTCOME_DEFER   => 3,
            self::OUTCOME_REJECT  => 4,
        ];
        $recommendedOutcome = self::OUTCOME_ENQUEUE;
        $highestPriority     = 0;
        foreach ($perCandidatePredictions as $pred) {
            $p = $outcomePriority[$pred['predicted_outcome']] ?? 0;
            if ($p > $highestPriority) {
                $highestPriority     = $p;
                $recommendedOutcome = $pred['predicted_outcome'];
            }
        }

        return [
            'schema'                   => self::SCHEMA,
            'verdict'                  => $riskFlags === [] ? self::VERDICT_ACCEPTABLE : self::VERDICT_RISKY,
            'risk_flags'               => $riskFlags,
            'risk_reasons'             => $riskReasons,
            'recommended_outcome'      => $recommendedOutcome,
            'simulation_summary'       => [
                'batch_size'                       => $batchSize,
                'forbidden_target_count'           => $forbiddenCount,
                'write_set_collision_count'        => $writeSetCollision ? 1 : 0,
                'projected_give_back_count'        => $projectedGiveBackCount,
                'can_worker_handle'                => $canWorkerHandle,
                'avg_compounding_value'            => $avgCompounding,
                'duplicate_target_count'           => $duplicateTargetCount,
                'missing_implementation_file_count' => $missingImplementationCount,
                'missing_test_file_count'          => $missingTestFileCount,
                'high_contradiction_risk_count'    => $highContradictionRiskCount,
                'low_value_density_count'          => $lowValueDensityCount,
            ],
            'per_candidate_predictions' => $perCandidatePredictions,
        ];
    }
}
