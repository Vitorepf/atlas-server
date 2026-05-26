<?php

declare(strict_types=1);

namespace App\Services\Ai\DualCore;

/**
 * Gap5.F2 — Canonical route_decision.v1 envelope builder.
 *
 * Single helper that any Programming-adjacent controller can consult to
 * emit `atlas.dual_core.route_decision.v1` in its JSON response. Centralises
 * the canonical shape so future schema evolution touches one file, not 47.
 *
 * Usage in a controller method:
 *
 *     return response()->json([
 *         ...$payload,
 *         'route_decision' => CanonicalRouteDecisionEnvelope::emit(
 *             route: 'programming',
 *             reason: 'http_atlas_code_thread_index',
 *         ),
 *     ]);
 */
final class CanonicalRouteDecisionEnvelope
{
    public const SCHEMA_VERSION = 'atlas.dual_core.route_decision.v1';

    /**
     * @return array{
     *   schema_version: string,
     *   route: string,
     *   reason: string,
     *   recorded_at: string
     * }
     */
    public static function emit(string $route, string $reason): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'route' => $route,
            'reason' => $reason,
            'recorded_at' => now()->toAtomString(),
        ];
    }
}
