<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticWorkcell\Contracts;

use App\Models\AiJob;

/**
 * Workcell Adapter — the provider-neutral RUNTIME-ADAPTER contract that runs
 * UNDER the Workcell Fabric (AAWR / AtlasAgenticWorkcellRuntimeService). It
 * turns ONE mission + subtasks into a governed, ready-to-dispatch cell and
 * runs it through an injected worker seam. Per the Atlas Orchestrator Canon
 * rule `role slot != runtime identity`, the runtime that fills the slot is
 * substitutable: Hermes is only ONE implementation (HermesWorkcellAdapter);
 * no architecture layer ever carries a provider name.
 *
 * Canon: docs/engineering-knowledge-base/atlas-orchestrator-canon.md.
 */
interface WorkcellAdapter
{
    /**
     * Compose a governed, ready-to-dispatch plan. Pure: launches nothing.
     *
     * @param  array<string,mixed>  $parentMission
     * @param  array<int,array<string,mixed>>  $subtasks
     * @param  array<string,mixed>|null  $policy
     * @param  array<int,array<string,mixed>>  $capabilityManifest
     * @return array<string,mixed>
     */
    public function plan(AiJob $job, array $parentMission, array $subtasks, ?array $policy = null, array $capabilityManifest = []): array;

    /**
     * End-to-end: plan -> dispatch -> reconcile, sealed into a run receipt.
     * $worker STARTS one child and returns a worker handle; Atlas owns the pool.
     *
     * @param  array<string,mixed>  $parentMission
     * @param  array<int,array<string,mixed>>  $subtasks
     * @param  callable(array<string,mixed>):mixed  $worker
     * @param  array<string,mixed>|null  $policy
     * @param  array<int,array<string,mixed>>  $capabilityManifest
     * @param  null|callable(array<string,mixed>,array<string,mixed>):array<string,mixed>  $verifier
     * @return array<string,mixed>
     */
    public function run(AiJob $job, array $parentMission, array $subtasks, callable $worker, ?array $policy = null, array $capabilityManifest = [], ?callable $verifier = null): array;
}
