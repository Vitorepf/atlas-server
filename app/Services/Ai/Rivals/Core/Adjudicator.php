<?php

namespace App\Services\Ai\Rivals\Core;

use App\Services\Ai\Rivals\Support\RunPaths;
use App\Services\Ai\Rivals\Support\SchemaContract;

/**
 * SÓ hard gates, fail-closed. claim_allowed=false é o default.
 * Exact set equality de receipt keys; sem score único.
 */
class Adjudicator
{
    public function __construct(private readonly ReplayVerifier $verifier = new ReplayVerifier) {}

    public function adjudicate(string $runId): array
    {
        $pipelineBlockers = [];
        $internalBlockers = [];

        try {
            $plan = RunPlan::load($runId);
        } catch (\Throwable $e) {
            return $this->persist(
                $runId,
                ["plan_invalid:{$e->getMessage()}"],
                [],
                [],
                ClaimTier::HARNESS,
            );
        }
        $claimTier = (string) ($plan->data['claim_tier'] ?? ClaimTier::forPlan(
            (string) $plan->data['suite_id'],
            (array) $plan->data['arms'],
        ));

        $receipts = [];
        try {
            $receipts = RunReceipt::loadAll($runId);
        } catch (\Throwable $e) {
            $pipelineBlockers[] = 'receipts_unparseable:'.$e->getMessage();
        }

        $seen = array_map(fn (RunReceipt $r) => $r->key(), $receipts);
        $unique = array_values(array_unique($seen));
        if (count($seen) !== count($unique)) {
            foreach (array_count_values($seen) as $key => $count) {
                if ($count > 1) {
                    $pipelineBlockers[] = "duplicate_receipt:{$key}";
                }
            }
        }

        $expected = $plan->expectedReceiptKeys();
        sort($expected);
        $observed = $unique;
        sort($observed);
        foreach (array_diff($expected, $observed) as $missing) {
            $pipelineBlockers[] = "missing_receipt:{$missing}";
        }
        foreach (array_diff($observed, $expected) as $extra) {
            $pipelineBlockers[] = "extra_receipt:{$extra}";
        }

        $minReps = (int) config('atlas_rivals.claim.min_repetitions', 3);
        if ($plan->data['repetitions'] < $minReps) {
            $internalBlockers[] = "repetitions_below_min:{$plan->data['repetitions']}<{$minReps}";
        }
        if (! ClaimTier::permitsInternalClaim($claimTier)) {
            $internalBlockers[] = "claim_tier_not_production:{$claimTier}";
        }

        $models = new ModelRegistry;
        foreach ($receipts as $receipt) {
            if (! is_numeric($receipt->data['cost_usd']) || ! is_numeric($receipt->data['wall_ms'])) {
                $internalBlockers[] = 'cost_or_time_missing:'.$receipt->key();
            }
            foreach (['cost_usd', 'wall_ms'] as $field) {
                if ((($receipt->data['field_presence'][$field] ?? [])['present'] ?? false) !== true) {
                    $internalBlockers[] = "field_not_present:{$field}:".$receipt->key();
                }
            }
            if (($receipt->data['harness_only'] ?? false) === true) {
                $internalBlockers[] = 'harness_only_receipt:'.$receipt->key();
            }
            $modelId = explode('@', (string) $receipt->data['arm_id'], 2)[0];
            if ($models->isHarnessOnly($modelId)) {
                $internalBlockers[] = 'harness_only_model:'.$receipt->key();
            }
            $model = $models->get($modelId) ?? [];
            if (($model['local'] ?? false) !== true && ! $models->isHarnessOnly($modelId)) {
                foreach (['tokens_in', 'tokens_out'] as $field) {
                    if ((($receipt->data['field_presence'][$field] ?? [])['present'] ?? false) !== true) {
                        $internalBlockers[] = "field_not_present:{$field}:".$receipt->key();
                    }
                }
                if (((int) $receipt->data['tokens_in'] + (int) $receipt->data['tokens_out']) <= 0) {
                    $internalBlockers[] = 'provider_usage_empty:'.$receipt->key();
                }
            }
        }

        if (is_file(RunPaths::nativeManifestPath($runId))) {
            try {
                $nativeManifest = NativeExecutionManifest::load($runId);
                $nativeReceipts = NativeExecutionReceipt::loadAll($runId);
                if (count($nativeReceipts) !== count($nativeManifest->entries())) {
                    $internalBlockers[] = 'native_receipts_incomplete';
                }
                foreach ($nativeReceipts as $nativeReceipt) {
                    if ($nativeReceipt->data['status'] !== 'success') {
                        $internalBlockers[] = 'native_receipt_not_success:'
                            .$nativeReceipt->data['execution_id'];
                    }
                    if (($nativeReceipt->data['runner']['mode'] ?? null) !== 'execute') {
                        $internalBlockers[] = 'native_runner_mode_not_execute:'
                            .$nativeReceipt->data['execution_id'];
                    }
                }
            } catch (\Throwable $e) {
                $internalBlockers[] = 'native_execution_evidence_invalid:'.$e->getMessage();
            }
        }

        $planJudge = $plan->data['judge_config'] ?? null;
        if ($planJudge !== null) {
            $pinned = json_encode($planJudge);
            foreach ($receipts as $receipt) {
                if (json_encode($receipt->data['judge_config'] ?? null) !== $pinned) {
                    $pipelineBlockers[] = 'judge_config_mismatch:'.$receipt->key();
                }
            }
        }

        $packPath = RunPaths::evidencePath($runId);
        $pack = [];
        if (! is_file($packPath)) {
            $pipelineBlockers[] = 'evidence_pack_missing';
        } else {
            $pack = json_decode(file_get_contents($packPath), true) ?? [];
            foreach (['plan_hash', 'receipts_hash'] as $field) {
                if ((($pack[$field] ?? [])['present'] ?? false) !== true) {
                    $pipelineBlockers[] = "evidence_incomplete:{$field}";
                }
            }
            foreach ($pack['artifacts'] ?? [] as $rel => $meta) {
                if (($meta['present'] ?? false) !== true) {
                    $pipelineBlockers[] = "evidence_incomplete:artifact:{$rel}";
                }
            }
        }

        $replay = $this->verifier->verify($runId);
        if (! $replay['verified']) {
            foreach ($replay['failures'] as $failure) {
                $pipelineBlockers[] = "replay_failed:{$failure}";
            }
        }

        if ($this->isExternalSuite((string) $plan->data['suite_id'])) {
            $hasBare = false;
            foreach ($plan->data['arms'] as $arm) {
                if (($arm['runtime'] ?? null) === 'bare') {
                    $hasBare = true;
                    break;
                }
            }
            if (! $hasBare) {
                $internalBlockers[] = 'difficulty_uncalibrated';
            }
        }

        $statisticalAnalysis = [
            'adequate' => false,
            'blockers' => ['claim_tier_not_production'],
            'segments' => [],
        ];
        if (ClaimTier::permitsInternalClaim($claimTier)) {
            $production = $this->productionBlockers($plan, $receipts, $pack);
            $internalBlockers = array_merge($internalBlockers, $production);
            $statisticalAnalysis = (new StatisticalPolicy)->evaluate($plan, $receipts);
            $internalBlockers = array_merge(
                $internalBlockers,
                $statisticalAnalysis['blockers'],
            );
        }

        $pipelineBlockers = array_values(array_unique($pipelineBlockers));
        $internalBlockers = array_values(array_unique(array_merge(
            $pipelineBlockers,
            $internalBlockers,
        )));
        $publicBlockers = $internalBlockers;
        if (! ClaimTier::permitsPublicClaim($claimTier)) {
            $publicBlockers[] = "public_claim_tier_required:{$claimTier}";
        } else {
            // Public promotion remains fail-closed until every thesis gate is
            // implemented as code rather than inferred from a green internal run.
            $publicBlockers[] = 'enterprise_claim_gate_not_implemented';
        }

        return $this->persist(
            $runId,
            $pipelineBlockers,
            $internalBlockers,
            array_values(array_unique($publicBlockers)),
            $claimTier,
            $plan->data,
            $receipts,
            $statisticalAnalysis,
        );
    }

    /** @param array<int, RunReceipt> $receipts */
    private function persist(
        string $runId,
        array $pipelineBlockers,
        array $internalBlockers,
        array $publicBlockers,
        string $claimTier,
        array $planData = [],
        array $receipts = [],
        array $statisticalAnalysis = [],
    ): array {
        $taskTypes = array_values(array_unique(array_map(
            fn (RunReceipt $r) => $r->data['task_type'],
            $receipts
        )));
        $models = array_values(array_unique(array_column($planData['arms'] ?? [], 'model_id')));
        $runtimes = array_values(array_unique(array_column($planData['arms'] ?? [], 'runtime')));
        $pipelineValid = $pipelineBlockers === [];
        $internalAllowed = $pipelineValid
            && ClaimTier::permitsInternalClaim($claimTier)
            && $internalBlockers === [];
        $publicAllowed = $internalAllowed
            && ClaimTier::permitsPublicClaim($claimTier)
            && $publicBlockers === [];

        $adjudication = [
            'schema_version' => SchemaContract::ADJUDICATION,
            'run_id' => $runId,
            'verdict' => $pipelineValid ? 'valid' : 'invalid',
            'pipeline_valid' => $pipelineValid,
            'pipeline_blockers' => array_values($pipelineBlockers),
            'claim_tier' => $claimTier,
            'internal_claim_allowed' => $internalAllowed,
            'internal_claim_blockers' => array_values($internalBlockers),
            'public_claim_allowed' => $publicAllowed,
            'public_claim_blockers' => array_values($publicBlockers),
            'statistical_analysis' => $statisticalAnalysis,
            'not_ready_reasons' => array_values(array_unique(array_merge(
                $internalBlockers,
                $publicBlockers,
            ))),
            // Backward-compatible alias. It means internal scoped claim only.
            'claim_allowed' => $internalAllowed,
            'claim_blockers' => array_values($internalBlockers),
            'claim_scope' => $planData === [] ? null : [
                'claim_tier' => $claimTier,
                'task_types' => $taskTypes,
                'suite' => $planData['suite_id'],
                'cases' => $planData['case_ids'],
                'models' => $models,
                'runtimes' => $runtimes,
                'arms' => array_column($planData['arms'], 'arm_id'),
                'repetitions' => $planData['repetitions'],
                'budget' => $planData['budget'],
                'environment' => $planData['environment'],
                'repo_commit' => $planData['environment']['repo_commit'] ?? null,
                'adapter_hash' => $planData['environment']['adapter_hash'] ?? null,
                'judge_config' => $planData['judge_config'] ?? null,
            ],
            'adjudicated_at' => now()->toIso8601String(),
        ];
        $violations = SchemaContract::validate(
            $adjudication,
            SchemaContract::ADJUDICATION,
        );
        if ($violations !== []) {
            throw new \RuntimeException('rivals_invalid_adjudication:'.implode(',', $violations));
        }
        RunPaths::ensureDir(RunPaths::runDir($runId));
        file_put_contents(
            RunPaths::adjudicationPath($runId),
            json_encode($adjudication, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        return $adjudication;
    }

    private function isExternalSuite(string $suiteId): bool
    {
        return in_array($suiteId, (new SuiteRegistry)->externalSuiteIds(), true);
    }

    /**
     * @param  list<RunReceipt>  $receipts
     * @param  array<string, mixed>  $pack
     * @return list<string>
     */
    private function productionBlockers(RunPlan $plan, array $receipts, array $pack): array
    {
        $blockers = [];
        $suiteId = (string) $plan->data['suite_id'];
        $external = $this->isExternalSuite($suiteId);
        if (($plan->data['preregistration_hash'] ?? null) === null) {
            $blockers[] = 'preregistration_not_pinned';
        }
        if (($pack['workspace_fingerprint']['dirty'] ?? true) === true
            && (bool) config('atlas_rivals.claim.block_dirty_workspace', true)) {
            $blockers[] = 'workspace_dirty';
        }

        $totalCost = array_sum(array_map(
            fn (RunReceipt $receipt): float => (float) $receipt->data['cost_usd'],
            $receipts,
        ));
        $budget = (float) ($plan->data['budget']['max_usd'] ?? 0.0);
        if ($totalCost > $budget + 0.000001) {
            $blockers[] = 'budget_exceeded:'.round($totalCost, 6).'>'.$budget;
        }
        $nonLocalModel = collect($plan->data['arms'])->contains(function (array $arm): bool {
            $model = (new ModelRegistry)->get((string) ($arm['model_id'] ?? ''));

            return ($model['local'] ?? false) !== true;
        });
        if ($nonLocalModel) {
            if (($plan->data['environment']['approve_provider_spend'] ?? false) !== true) {
                $blockers[] = 'provider_spend_not_approved_in_plan';
            }
            if (config('atlas_rivals.provider_spend_allowed') !== true) {
                $blockers[] = 'provider_spend_runtime_gate_closed';
            }
        }

        $unknownFailureClasses = array_values(array_unique(array_filter(array_map(
            fn (RunReceipt $receipt): ?string => ($receipt->data['failure_class'] ?? null) !== null
                && ! in_array($receipt->data['failure_class'], FailureClass::all(), true)
                    ? (string) $receipt->data['failure_class']
                    : null,
            $receipts,
        ))));
        foreach ($unknownFailureClasses as $failureClass) {
            $blockers[] = 'unknown_failure_class:'.$failureClass;
        }

        if ($external) {
            foreach (['repo_commit', 'adapter_hash'] as $field) {
                if (($plan->data['environment'][$field] ?? null) === null) {
                    $blockers[] = "plan_provenance_missing:{$field}";
                }
            }
            foreach (['manifest_hash', 'preregistration_hash', 'state_hash', 'events_hash', 'smoke_receipt_hash'] as $field) {
                if ((($pack[$field] ?? [])['present'] ?? false) !== true) {
                    $blockers[] = "production_evidence_missing:{$field}";
                }
            }
            $expectedNative = count($plan->expectedReceiptKeys());
            if (count($pack['native_receipts'] ?? []) < $expectedNative) {
                $blockers[] = 'native_execution_receipts_incomplete';
            }
            if (count($pack['raw_results'] ?? []) !== $expectedNative) {
                $blockers[] = 'native_raw_result_set_incomplete';
            }
            $smoke = is_file(RunPaths::smokeSnapshotPath($plan->runId()))
                ? json_decode((string) file_get_contents(RunPaths::smokeSnapshotPath($plan->runId())), true)
                : [];
            if (($smoke['status'] ?? null) !== 'running') {
                $blockers[] = 'benchmark_smoke_not_running';
            } elseif (is_string($smoke['finished_at'] ?? null)) {
                $ageHours = (time() - (strtotime($smoke['finished_at']) ?: 0)) / 3600;
                $maxAge = (int) config('atlas_rivals.claim.smoke_max_age_hours', 168);
                if ($ageHours > $maxAge) {
                    $blockers[] = 'benchmark_smoke_stale:'.round($ageHours, 2).'>'.$maxAge;
                }
            } else {
                $blockers[] = 'benchmark_smoke_timestamp_missing';
            }
        }

        return $blockers;
    }
}
