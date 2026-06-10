<?php

declare(strict_types=1);

namespace App\Services\Ai\Obra;

/**
 * AOBG N3.F2 — the PER-NODE DELIVERY seam (the cost-free test boundary).
 *
 * The {@see AtlasObraExecutor} walks a plan-DAG node by node; for each node it asks
 * THIS seam to deliver the node's natural-language step into a CERTIFIED set of
 * {path, content} files. The single responsibility is exactly the proven mission
 * loop's STAGE-1 ("request → certified code"): the production binding
 * ({@see ProviderObraNodeDelivery}) drives the real
 * {@see \App\Services\Ai\RealExecution\AtlasMissionService} / delivery (provider
 * spend, the operator's single command). Tests inject a deterministic fake that
 * returns certified files per step — ZERO tokens, exactly the
 * {@see \Tests\Feature\Ai\RealExecution\MissionDeliveryOrchestratorTest} philosophy.
 *
 * Keeping per-node delivery behind this interface is what makes the WHOLE obra
 * executor provable cost-free: the executor never knows whether the files came from
 * a real provider or a fake — it only ever applies CERTIFIED files onto the single
 * accumulating obra branch (and a NON-certified result HALTS the obra).
 *
 * PRIVACY: the returned `files` are the operator's own generated code, applied onto
 * the governed obra branch (about to be reviewed). The delivery is the only place a
 * provider runs; the executor's brain anchoring + outcome recording use ids / labels
 * / paths only (provider-safe), never source.
 */
interface ObraNodeDelivery
{
    /**
     * Deliver ONE obra node's step into certified files.
     *
     * @param  string  $request  the node's natural-language step (the executor passes
     *                          the node's `request`)
     * @param  array<string,mixed>  $context  per-node context the executor supplies:
     *                          {node_id, plan_id, target_area?, brain_refs?, brain_context?,
     *                          repo_dir?, workspace?, provider?}
     * @return array<string,mixed> {
     *     certified: bool,             // false ⇒ the executor HALTS the obra
     *     files: list<array{path:string,content:string}>,  // the step's change
     *     gate_receipt?: string,       // the gates-passed credential (sha256 hex)
     *     provider?: ?string,          // which engine produced it (label only)
     *     reason?: string,             // why it did NOT certify (for the halt record)
     *     mission_id?: string,         // the underlying mission/delivery id (label)
     * }
     */
    public function deliver(string $request, array $context = []): array;

    /**
     * A short, stable label for this delivery binding, recorded into each node's
     * result so a reader knows HOW the step was delivered (provider vs fake) without
     * re-running it.
     */
    public function label(): string;
}
