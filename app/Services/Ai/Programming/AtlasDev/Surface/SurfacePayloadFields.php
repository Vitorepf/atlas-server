<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Surface;

use App\Services\Ai\Programming\AtlasDev\Pipeline\IntakeNormalizer;
use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevStringListNormalizer;

/**
 * Shared, byte-identical helpers used by every concrete
 * {@see AtlasDevSurfaceAdapter} to pluck primitives out of a surface-native
 * payload before handing them to {@see IntakeNormalizer}.
 *
 * The trait is intentionally narrow: only the two helpers that were duplicated
 * verbatim across all four adapters (`stringField` and `stringListField`). The
 * `extractSurfaceHints` helper stays per-adapter because each surface has its
 * own native vocabulary (e.g. Desktop carries `attachments`/`policy_hints`,
 * CLI accepts a flat key list, App is intentionally compact).
 *
 * Trait scope is also a boundary: nothing here imports core (Schemas/Pipeline)
 * or third-party namespaces. Adapters get the helpers for free and keep their
 * file sizes thin.
 */
trait SurfacePayloadFields
{
    /**
     * Read a string field from the payload, trimming whitespace. Anything
     * non-string (null, int, bool, array) collapses to ''.
     *
     * @param  array<string, mixed>  $payload
     */
    private function stringField(array $payload, string $key): string
    {
        $value = $payload[$key] ?? '';
        if (! is_string($value)) {
            return '';
        }

        return trim($value);
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
}
