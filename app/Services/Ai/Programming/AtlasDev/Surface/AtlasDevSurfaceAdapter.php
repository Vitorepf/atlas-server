<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Surface;

use App\Services\Ai\Programming\AtlasDev\Pipeline\PlanOnlyResult;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;

/**
 * Surface translator between a native client payload (Desktop / CLI / App /
 * API) and the Atlas Dev core. The contract is intentionally narrow:
 *
 *   - buildEnvelope: surface-native payload → OperationEnvelope (via the
 *     surface-agnostic IntakeNormalizer). The adapter is the ONLY place
 *     allowed to know surface-specific keys (thread_id, composer_mode, etc.).
 *   - formatPlanOnly: PlanOnlyResult → array shaped for that surface's wire
 *     format. Output is a projection of canonical artifacts; the adapter
 *     never decides routing, risk, provider or completion — it only renders
 *     what the core already decided.
 *
 * Core services in `AtlasDev/{Schemas,Discovery,Pipeline,Persistence,Gate,
 * Provider,Repair,Escalation,PromptProjection,Telemetry}` are forbidden from
 * importing this namespace. The boundary points one way: Surface → Core.
 */
interface AtlasDevSurfaceAdapter
{
    /**
     * Canonical surface identifier this adapter handles. The value matches
     * `OperationEnvelope.surfaceId` produced by buildEnvelope().
     */
    public function surfaceId(): string;

    /**
     * Translate a surface-native payload into an {@see OperationEnvelope}.
     *
     * The adapter is responsible for: extracting the four primitive intake
     * inputs (workspace, raw_intent, user_constraints, surface hints) and
     * validating any surface-specific override of surface_id.
     *
     * @param  array<string, mixed>  $payload  surface-native request body
     */
    public function buildEnvelope(array $payload): OperationEnvelope;

    /**
     * Project a {@see PlanOnlyResult} into the response shape expected by the
     * client surface. The base canonical projection is identical across
     * surfaces; surface-specific fields (e.g. Desktop `ui_hints`) MUST be
     * derived purely from the artifacts inside the result.
     *
     * @return array<string, mixed>
     */
    public function formatPlanOnly(PlanOnlyResult $result): array;
}
