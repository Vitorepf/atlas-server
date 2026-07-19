<?php

namespace App\Services\Ai\Rivals\Core;

use App\Services\Ai\Rivals\Support\AtlasRuntimeProofValidator;
use App\Services\Ai\Rivals\Support\AtomicWriter;
use App\Services\Ai\Rivals\Support\RunPaths;
use InvalidArgumentException;

/**
 * Compara o MESMO modelo bare vs atlas_* sobre receipts já executados.
 * Sem runtime_commands configurado ⇒ uplift_supported=false (exceto local_fake harness).
 */
class AtlasUpliftRunner
{
    public function compare(string $runId, string $modelId, string $baseRuntime = 'bare', string $atlasRuntime = 'atlas_dev'): array
    {
        $runtimes = config('atlas_rivals.runtimes', []);
        if ($baseRuntime === $atlasRuntime || ! in_array($atlasRuntime, $runtimes, true)) {
            throw new InvalidArgumentException("rivals_uplift_invalid_runtimes:{$baseRuntime}vs{$atlasRuntime}");
        }

        $plan = null;
        try {
            $plan = RunPlan::load($runId);
        } catch (\Throwable) {
            $plan = null;
        }
        $suiteId = $plan->data['suite_id'] ?? null;
        $harnessOnly = $suiteId === 'local_fake';

        if (! $harnessOnly) {
            $cmd = config("atlas_rivals.runtime_commands.{$atlasRuntime}");
            if (! is_string($cmd) || trim($cmd) === '') {
                return $this->persist($runId, [
                    'uplift_supported' => false,
                    'uplift_kind' => 'unsupported',
                    'reason' => "runtime_command_not_configured:{$atlasRuntime}",
                    'model_id' => $modelId,
                    'claim_allowed' => false,
                    'claim_blockers' => ["runtime_command_not_configured:{$atlasRuntime}"],
                    'deltas' => [],
                ]);
            }
        }

        $baseArm = "{$modelId}@{$baseRuntime}";
        $atlasArm = "{$modelId}@{$atlasRuntime}";
        $byArm = ['base' => [], 'atlas' => []];
        foreach (RunReceipt::loadAll($runId) as $receipt) {
            if ($receipt->data['arm_id'] === $baseArm) {
                $byArm['base'][$this->pairKey($receipt->data)] = $receipt->data;
            } elseif ($receipt->data['arm_id'] === $atlasArm) {
                $byArm['atlas'][$this->pairKey($receipt->data)] = $receipt->data;
            }
        }

        $adjPath = RunPaths::adjudicationPath($runId);
        $adjudication = is_file($adjPath) ? (json_decode(file_get_contents($adjPath), true) ?? []) : [];

        if ($byArm['base'] === [] || $byArm['atlas'] === []) {
            $missing = $byArm['atlas'] === [] ? $atlasArm : $baseArm;

            return $this->persist($runId, [
                'uplift_supported' => false,
                'uplift_kind' => 'unsupported',
                'reason' => "arm_did_not_run:{$missing}",
                'model_id' => $modelId,
                'claim_allowed' => false,
                'claim_blockers' => ["uplift_arm_missing:{$missing}"],
                'deltas' => [],
            ]);
        }
        $baseKeys = array_keys($byArm['base']);
        $atlasKeys = array_keys($byArm['atlas']);
        sort($baseKeys);
        sort($atlasKeys);
        if ($baseKeys !== $atlasKeys) {
            return $this->persist($runId, [
                'uplift_supported' => false,
                'uplift_kind' => 'unsupported',
                'reason' => 'partial_case_repetition_pairing',
                'model_id' => $modelId,
                'claim_allowed' => false,
                'claim_blockers' => [
                    'uplift_pair_set_mismatch:missing_atlas='.implode(',', array_diff($baseKeys, $atlasKeys))
                    .';missing_base='.implode(',', array_diff($atlasKeys, $baseKeys)),
                ],
                'deltas' => [],
            ]);
        }

        $excluded = [];
        if (! $harnessOnly) {
            $provenBase = [];
            $provenAtlas = [];
            foreach ($byArm['base'] as $key => $receipt) {
                $atlasReceipt = $byArm['atlas'][$key] ?? null;
                $bareOk = data_get($receipt, 'metadata.direct_provider.real_provider') === true;
                // v2 proves the actual Atlas path with the Hermes provider
                // contract, ExecutiveMission/ResultPacket governance hashes and
                // readable per-call stream paths. Legacy receipts still require
                // the independently-derived atlas_runtime marker.
                $atlasProof = is_array($atlasReceipt)
                    ? data_get($atlasReceipt, 'metadata.runtime_bridge')
                    : null;
                $atlasOk = is_array($atlasProof)
                    && (new AtlasRuntimeProofValidator)->valid(
                        $atlasProof,
                        (string) data_get($receipt, 'metadata.direct_provider.model'),
                        RunPaths::runDir($runId),
                    );
                $modelMatch = is_array($atlasReceipt)
                    && data_get($receipt, 'metadata.direct_provider.model') === data_get(
                        $atlasReceipt,
                        'metadata.runtime_bridge.model',
                    );
                if ($bareOk && $atlasOk && $modelMatch) {
                    $provenBase[$key] = $receipt;
                    $provenAtlas[$key] = $atlasReceipt;

                    continue;
                }
                if (! $bareOk || ! $modelMatch) {
                    $excluded[] = "bare_runtime_proof_missing:{$key}";
                }
                if (! $atlasOk) {
                    $excluded[] = "atlas_runtime_proof_missing:{$key}";
                }
            }
            if ($provenBase === []) {
                return $this->persist($runId, [
                    'uplift_supported' => false,
                    'uplift_kind' => 'unsupported',
                    'reason' => ($excluded[0] ?? 'runtime_proof_missing'),
                    'model_id' => $modelId,
                    'claim_allowed' => false,
                    'claim_blockers' => array_values(array_unique($excluded)) ?: ['runtime_proof_missing'],
                    'deltas' => [],
                    'excluded_pair_keys' => array_values(array_unique(array_map(
                        static fn (string $b): string => preg_replace('/^[^:]+:/', '', $b) ?? $b,
                        $excluded,
                    ))),
                ]);
            }
            $byArm['base'] = $provenBase;
            $byArm['atlas'] = $provenAtlas;
        }

        $baseMetrics = $this->metrics(array_values($byArm['base']));
        $atlasMetrics = $this->metrics(array_values($byArm['atlas']));
        $baseTypes = array_keys($baseMetrics);
        sort($baseTypes);
        $atlasTypes = array_keys($atlasMetrics);
        sort($atlasTypes);
        if ($baseTypes !== $atlasTypes) {
            return $this->persist($runId, [
                'uplift_supported' => false,
                'uplift_kind' => 'unsupported',
                'reason' => 'partial_task_type_coverage',
                'model_id' => $modelId,
                'claim_allowed' => false,
                'claim_blockers' => ['uplift_partial_coverage'],
                'deltas' => [],
            ]);
        }

        $deltas = [];
        $stopTheLine = false;
        $measurementBlockers = [];
        foreach ($baseMetrics as $taskType => $base) {
            $atlas = $atlasMetrics[$taskType];
            $pairs = [];
            foreach ($byArm['base'] as $key => $baseReceipt) {
                if ($baseReceipt['task_type'] !== $taskType) {
                    continue;
                }
                $pairs[] = [
                    'key' => $key,
                    'base_success' => $baseReceipt['status'] === 'success' ? 1.0 : 0.0,
                    'atlas_success' => $byArm['atlas'][$key]['status'] === 'success' ? 1.0 : 0.0,
                    'base_cost_usd' => (float) $baseReceipt['cost_usd'],
                    'atlas_cost_usd' => (float) $byArm['atlas'][$key]['cost_usd'],
                    'base_wall_ms' => (float) $baseReceipt['wall_ms'],
                    'atlas_wall_ms' => (float) $byArm['atlas'][$key]['wall_ms'],
                    'base_measurement_type' => data_get(
                        $baseReceipt,
                        'metadata.native.measurement_type',
                    ),
                    'atlas_measurement_type' => data_get(
                        $byArm['atlas'][$key],
                        'metadata.native.measurement_type',
                    ),
                    'base_score_metric' => data_get(
                        $baseReceipt,
                        'metadata.native.score_metric',
                    ),
                    'atlas_score_metric' => data_get(
                        $byArm['atlas'][$key],
                        'metadata.native.score_metric',
                    ),
                    'base_native_score' => data_get($baseReceipt, 'metadata.native.score'),
                    'atlas_native_score' => data_get(
                        $byArm['atlas'][$key],
                        'metadata.native.score',
                    ),
                ];
            }
            $successDeltas = array_map(
                fn (array $pair): float => $pair['atlas_success'] - $pair['base_success'],
                $pairs,
            );
            $successCi = $this->pairedBootstrapMeanCi(
                $successDeltas,
                crc32($runId.'|'.$taskType.'|success'),
            );
            $deltaSuccess = round($atlas['success_rate'] - $base['success_rate'], 4);
            $continuousDeclared = count(array_filter(
                $pairs,
                static fn (array $pair): bool => $pair['base_measurement_type'] === 'continuous'
                    || $pair['atlas_measurement_type'] === 'continuous',
            )) > 0;
            $continuousPairs = array_values(array_filter(
                $pairs,
                static fn (array $pair): bool => $pair['base_measurement_type'] === 'continuous'
                    && $pair['atlas_measurement_type'] === 'continuous'
                    && is_numeric($pair['base_native_score'])
                    && is_numeric($pair['atlas_native_score'])
                    && is_string($pair['base_score_metric'])
                    && $pair['base_score_metric'] !== ''
                    && $pair['base_score_metric'] === $pair['atlas_score_metric'],
            ));
            $continuousComplete = $continuousDeclared && count($continuousPairs) === count($pairs);
            if ($continuousDeclared && ! $continuousComplete) {
                $measurementBlockers[] = "continuous_native_score_pair_incomplete:{$taskType}";
            }
            $nativeScoreDeltas = array_map(
                static fn (array $pair): float => (float) $pair['atlas_native_score']
                    - (float) $pair['base_native_score'],
                $continuousPairs,
            );
            $nativeScoreCi = $continuousComplete
                ? $this->pairedBootstrapMeanCi(
                    $nativeScoreDeltas,
                    crc32($runId.'|'.$taskType.'|native_score'),
                )
                : ['low' => null, 'high' => null, 'samples' => 0];
            $deltaNativeScore = $continuousComplete
                ? array_sum($nativeScoreDeltas) / count($nativeScoreDeltas)
                : null;
            $outcome = $continuousComplete
                ? match (true) {
                    ($nativeScoreCi['samples'] ?? 0) > 1
                        && ($nativeScoreCi['high'] ?? 0) < 0 => 'confirmed_negative',
                    $deltaNativeScore < 0 => 'possible_negative',
                    $deltaNativeScore === 0.0 => 'neutral',
                    default => 'positive',
                }
                : match (true) {
                    ($successCi['samples'] ?? 0) > 1
                        && ($successCi['high'] ?? 0) < 0 => 'confirmed_negative',
                    $deltaSuccess < 0 => 'possible_negative',
                    abs($deltaSuccess) <= 0.05 => 'neutral',
                    default => 'positive',
                };
            $stopTheLine = $stopTheLine
                || in_array($outcome, ['confirmed_negative', 'possible_negative'], true);
            $deltas[] = [
                'task_type' => $taskType,
                'base' => $base,
                'atlas' => $atlas,
                'pairs' => count($pairs),
                'pair_keys' => array_column($pairs, 'key'),
                'primary_outcome' => $continuousComplete ? 'native_score' : 'artifact_status',
                'success_semantics' => $continuousDeclared
                    ? 'valid_native_artifact'
                    : 'benchmark_success',
                'native_score_metric' => $continuousComplete
                    ? $continuousPairs[0]['base_score_metric']
                    : null,
                'native_score_pairs' => count($continuousPairs),
                'delta_native_score' => $deltaNativeScore,
                'delta_native_score_ci_95' => $nativeScoreCi,
                'delta_success_rate' => $deltaSuccess,
                'delta_success_rate_ci_95' => $successCi,
                'delta_avg_cost_usd' => round($atlas['avg_cost_usd'] - $base['avg_cost_usd'], 6),
                'delta_avg_wall_ms' => $atlas['avg_wall_ms'] - $base['avg_wall_ms'],
                'improved_pairs' => count(array_filter(
                    $continuousComplete ? $nativeScoreDeltas : $successDeltas,
                    fn (float $d): bool => $d > 0,
                )),
                'regressed_pairs' => count(array_filter(
                    $continuousComplete ? $nativeScoreDeltas : $successDeltas,
                    fn (float $d): bool => $d < 0,
                )),
                'unchanged_pairs' => count(array_filter(
                    $continuousComplete ? $nativeScoreDeltas : $successDeltas,
                    fn (float $d): bool => $d === 0.0,
                )),
                'outcome' => $outcome,
            ];
        }

        $internalAllowed = ! $harnessOnly
            && ! $stopTheLine
            && $excluded === []
            && $measurementBlockers === []
            && (($adjudication['internal_claim_allowed'] ?? $adjudication['claim_allowed'] ?? false) === true);

        $blockers = $harnessOnly
            ? ['harness_uplift_not_claimable']
            : array_values(array_unique(array_merge(
                $adjudication['internal_claim_blockers']
                    ?? $adjudication['claim_blockers']
                    ?? ['adjudication_missing'],
                $stopTheLine ? ['negative_multiplier_stop_the_line'] : [],
                $excluded !== [] ? ['uplift_pairs_filtered_to_proven_runtime', 'diagnostic_only'] : [],
                $excluded,
                $measurementBlockers,
            )));

        return $this->persist($runId, [
            'uplift_supported' => true,
            'uplift_kind' => $harnessOnly ? 'harness_uplift' : 'real_uplift',
            'diagnostic_only' => $excluded !== [],
            'model_id' => $modelId,
            'base_runtime' => $baseRuntime,
            'atlas_runtime' => $atlasRuntime,
            'deltas' => $deltas,
            'stop_the_line' => $stopTheLine,
            'internal_claim_allowed' => $internalAllowed,
            'public_claim_allowed' => $internalAllowed
                && (($adjudication['public_claim_allowed'] ?? false) === true),
            'claim_allowed' => $internalAllowed,
            'claim_blockers' => $blockers,
            'measurement_blockers' => $measurementBlockers,
            'claim_scope' => $adjudication['claim_scope'] ?? null,
            'excluded_pair_keys' => $excluded === [] ? [] : array_values(array_unique(array_map(
                static function (string $blocker): string {
                    return preg_replace('/^[^:]+:/', '', $blocker) ?? $blocker;
                },
                $excluded,
            ))),
            'proven_pair_count' => count($byArm['base']),
        ]);
    }

    /** @return array<string, array<string, int|float|null>> */
    private function metrics(array $receipts): array
    {
        $groups = [];
        foreach ($receipts as $r) {
            $groups[$r['task_type']][] = $r;
        }
        $out = [];
        foreach ($groups as $taskType => $items) {
            $n = count($items);
            $nativeScores = array_values(array_filter(
                array_map(
                    static fn (array $receipt): mixed => data_get(
                        $receipt,
                        'metadata.native.score',
                    ),
                    $items,
                ),
                'is_numeric',
            ));
            $out[$taskType] = [
                'n' => $n,
                'success_rate' => round(count(array_filter($items, fn ($r) => $r['status'] === 'success')) / $n, 4),
                'avg_cost_usd' => round(array_sum(array_column($items, 'cost_usd')) / $n, 6),
                'avg_wall_ms' => (int) round(array_sum(array_column($items, 'wall_ms')) / $n),
                'native_score_n' => count($nativeScores),
                'avg_native_score' => $nativeScores === []
                    ? null
                    : array_sum(array_map('floatval', $nativeScores)) / count($nativeScores),
            ];
        }

        return $out;
    }

    private function persist(string $runId, array $result): array
    {
        $result = ['schema_version' => 'atlas.rivals2.uplift.v1', 'run_id' => $runId] + $result
            + ['computed_at' => now()->toIso8601String()];
        RunPaths::ensureDir(RunPaths::runDir($runId));
        AtomicWriter::write(
            RunPaths::runDir($runId).'/uplift.json',
            json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
            )
        );

        return $result;
    }

    private function pairKey(array $receipt): string
    {
        return $receipt['case_id'].'|'.$receipt['repetition'];
    }

    /** @return array{low: ?float, high: ?float, samples: int} */
    private function pairedBootstrapMeanCi(array $deltas, int $seed): array
    {
        if ($deltas === []) {
            return ['low' => null, 'high' => null, 'samples' => 0];
        }
        if (count($deltas) === 1) {
            return ['low' => $deltas[0], 'high' => $deltas[0], 'samples' => 1];
        }
        $samples = app()->environment('testing') ? 500 : 10_000;
        $state = (int) sprintf('%u', $seed);
        $means = [];
        $n = count($deltas);
        for ($iteration = 0; $iteration < $samples; $iteration++) {
            $sum = 0.0;
            for ($index = 0; $index < $n; $index++) {
                $state = (int) (($state * 1664525 + 1013904223) % 4294967296);
                $sum += $deltas[$state % $n];
            }
            $means[] = $sum / $n;
        }
        sort($means, SORT_NUMERIC);

        return [
            'low' => round($means[(int) floor(($samples - 1) * 0.025)], 6),
            'high' => round($means[(int) ceil(($samples - 1) * 0.975)], 6),
            'samples' => $samples,
        ];
    }
}
