<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\SelfModel\Training;

/**
 * SELF-MODEL TRAINING-PLAN COMPOSER — composes the governed training PLAN (a pure spec artifact) that the
 * operator's EXTERNAL training infrastructure consumes. It only DRAFTS the spec: it never trains, never
 * spawns, and never reaches the network — there is no actuator here.
 *
 * Pure + deterministic: same inputs ⇒ identical plan_hash (the sha256 of the canonical, key-sorted plan).
 * Fail-closed: a corpus reference without a corpus_hash is BLOCKED (an un-hashed corpus is unauditable). The
 * held-out fraction is clamped to [0.1, 0.5] so a degenerate hyper-param can never request a 0%- or 80%-holdout.
 */
final class AtlasLoopSelfModelTrainingPlanComposer
{
    public const SCHEMA = 'atlas.loop.self_model.training_plan.v1';

    private const HELD_OUT_DEFAULT = 0.2;

    private const HELD_OUT_MIN = 0.1;

    private const HELD_OUT_MAX = 0.5;

    /**
     * @param  array<string,mixed>  $corpusRef     must carry corpus_hash
     * @param  array<string,mixed>  $oracleBinding {domain}
     * @param  array<string,mixed>  $hyperParams
     * @return array<string,mixed>
     */
    public function compose(array $corpusRef, string $baseModel, array $oracleBinding, array $hyperParams = []): array
    {
        $corpusHash = trim((string) ($corpusRef['corpus_hash'] ?? ''));
        if ($corpusHash === '') {
            return ['status' => 'blocked', 'reason' => 'corpus_hash_missing']; // unauditable corpus ⇒ no plan
        }

        $heldOut = isset($hyperParams['held_out_fraction']) && is_numeric($hyperParams['held_out_fraction'])
            ? (float) $hyperParams['held_out_fraction']
            : self::HELD_OUT_DEFAULT;
        $heldOut = max(self::HELD_OUT_MIN, min(self::HELD_OUT_MAX, $heldOut));

        $hyper = $hyperParams;
        $hyper['held_out_fraction'] = $heldOut; // keep the canonical (clamped) value consistent inside hyper_params
        ksort($hyper);

        $plan = [
            'schema' => self::SCHEMA,
            'base_model' => $baseModel,
            'corpus_hash' => $corpusHash,
            'oracle_domain' => (string) ($oracleBinding['domain'] ?? ''),
            'held_out_fraction' => $heldOut,
            'hyper_params' => $hyper,
        ];
        ksort($plan);

        $plan['plan_hash'] = hash('sha256', (string) json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $plan;
    }
}
