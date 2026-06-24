<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Supply;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;

/**
 * §1. Stable seam for provider-free supply lanes.
 *
 * The returned value mirrors the exact refiller-consumable shape from the existing
 * DocGap / Dedup / OrphanWiring lanes:
 * `list<array{objective:string, payload:array<string,mixed>, members:list<string>}>`.
 *
 * ANTI-GOODHART: implementations must never mint proxy-only work such as coverage-only,
 * behavior-preserving refactors, or other cosmetic/spec-preserving churn. Every emitted
 * spec must be MATERIAL: red-to-green, real wiring, cross-leverage, or genuine pattern
 * transfer.
 *
 * Provider-free contract: mint() is pure over the comprehension model and performs no
 * AI/provider calls. The incoming $repoRoot must be normalized with rtrim('/', ...) before
 * lane-specific path logic uses it.
 */
interface SupplyLaneContract
{
    /**
     * @return list<array{objective:string, payload:array<string,mixed>, members:list<string>}>
     */
    public function mint(AtlasLoopScopeComprehensionModel $model, string $repoRoot): array;
}
