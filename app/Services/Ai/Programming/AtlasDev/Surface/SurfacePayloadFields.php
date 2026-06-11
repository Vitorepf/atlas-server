<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Surface;

use App\Services\Ai\Programming\AtlasDev\Pipeline\IntakeNormalizer;
use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevStringListNormalizer;
use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevValueNormalizer;

/**
 * Shared, byte-identical helpers used by every concrete
 * {@see AtlasDevSurfaceAdapter} to pluck primitives out of a surface-native
 * payload before handing them to {@see IntakeNormalizer}.
 *
 * The trait is intentionally narrow: only helpers duplicated verbatim across
 * all four adapters live here. Surface-specific hint vocabulary (e.g. Desktop
 * `attachments`/`policy_hints`) stays inside the concrete adapter.
 *
 * Trait scope is also a boundary: nothing here imports core (Schemas/Pipeline)
 * or third-party namespaces. Adapters get the helpers for free and keep their
 * file sizes thin.
 */
trait SurfacePayloadFields
{
    /**
     * @var list<string>
     */
    private const COMMON_SURFACE_HINT_KEYS = [
        'thread_id',
        'conversation_id',
        'composer_mode',
        'composer_task',
        'provider_choice',
        'previous_run_id',
    ];

    /**
     * Read a string field from the payload, trimming whitespace. Anything
     * non-string (null, int, bool, array) collapses to ''.
     *
     * @param  array<string, mixed>  $payload
     */
    private function stringField(array $payload, string $key): string
    {
        return AtlasDevValueNormalizer::stringOrNull($payload[$key] ?? null) ?? '';
    }

    /**
     * Read a list-of-strings field. Skips empty / non-string entries. Returns
     * an empty list when the field is missing or not an array.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function stringListField(array $payload, string $key): array
    {
        return AtlasDevStringListNormalizer::trimmedStrings($payload[$key] ?? []);
    }

    /**
     * Extract the shared surface hint vocabulary used by CLI, Desktop, App,
     * and API surfaces. Router flow metadata is appended by
     * {@see appendRouterFlowHints()} so adapters can insert native extras
     * before it when that preserves their wire intent.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function baseSurfaceHints(array $payload): array
    {
        $hints = $this->stringSurfaceHints($payload, self::COMMON_SURFACE_HINT_KEYS);
        if (isset($payload['operator_explicit']) && is_bool($payload['operator_explicit'])) {
            $hints['operator_explicit'] = $payload['operator_explicit'];
        }

        return $hints;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function commonSurfaceHints(array $payload): array
    {
        return $this->appendRouterFlowHints($this->baseSurfaceHints($payload), $payload);
    }

    /**
     * @param  array<string, mixed>  $hints
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function appendRouterFlowHints(array $hints, array $payload): array
    {
        foreach (['flow_origin', 'command_intent'] as $key) {
            $value = AtlasDevValueNormalizer::stringOrNull($payload[$key] ?? null);
            if ($value !== null) {
                $hints[$key] = $value;
            }
        }

        return $hints;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private function stringSurfaceHints(array $payload, array $keys): array
    {
        $hints = [];
        foreach ($keys as $key) {
            $value = AtlasDevValueNormalizer::stringOrNull($payload[$key] ?? null);
            if ($value !== null) {
                $hints[$key] = $value;
            }
        }

        return $hints;
    }
}
