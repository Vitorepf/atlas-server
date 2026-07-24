<?php

declare(strict_types=1);

namespace App\Services\Ai\OpenBrainMcp;

/**
 * Shared input-normalization helpers for the OpenBrainMcp *Tools family.
 *
 * De-duplicates five byte-identical private helpers (object/positiveInt/string/stringList/
 * workspace) that were copied verbatim across the MCP tool classes when AtlasOpenBrainMcpService
 * was split into per-domain Tools. Single source of truth — a fix propagates to every tool.
 * (onlyScalarFilters is intentionally NOT here: it depends on a per-class $replayInput property.)
 */
trait OpenBrainMcpToolInput
{
    private function object(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function stringList(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $item): ?string => $this->string($item),
            $values,
        ))));
    }

    private function workspace(mixed $workspace): ?string
    {
        $workspace = $this->string($workspace) ?: (config('atlas.ai.workdir') ?: null);
        if ($workspace === null) {
            return base_path();
        }

        return realpath($workspace) ?: $workspace;
    }
}
