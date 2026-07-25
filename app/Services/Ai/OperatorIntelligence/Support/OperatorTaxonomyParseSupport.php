<?php

declare(strict_types=1);

namespace App\Services\Ai\OperatorIntelligence\Support;

use App\Services\Ai\OperatorIntelligence\OperatorTaxonomyRegistry;

/**
 * Pure markdown-table parser for the 170-item operator taxonomy (full-pass peel).
 * I/O (File::exists/get) stays on OperatorTaxonomyRegistry.
 */
final class OperatorTaxonomyParseSupport
{
    /**
     * @param  list<string>  $highStakes
     * @param  list<string>  $sensitiveDefault
     * @param  list<string>  $explicitOnly
     * @param  list<string>  $momentary
     * @return array<string,array<string,mixed>>
     */
    public static function parseMarkdownTable(
        string $markdown,
        array $highStakes,
        array $sensitiveDefault,
        array $explicitOnly,
        array $momentary,
    ): array {
        $items = [];
        foreach (preg_split('/\r?\n/', $markdown) ?: [] as $line) {
            if (preg_match('/^\|\s*((?:SYS|OP|COL)-\d{3})\s*\|\s*(\d{1,3})\s*\|\s*(.+?)\s*\|/u', $line, $m) !== 1) {
                continue;
            }
            $id = strtoupper($m[1]);
            $prefix = explode('-', $id)[0];
            $layer = match ($prefix) {
                'SYS' => OperatorTaxonomyRegistry::LAYER_SYSTEM,
                'OP' => OperatorTaxonomyRegistry::LAYER_OPERATOR,
                default => OperatorTaxonomyRegistry::LAYER_COLLABORATION,
            };

            $items[$id] = [
                'id' => $id,
                'num' => (int) $m[2],
                'layer' => $layer,
                'description' => trim($m[3]),
                'high_stakes' => in_array($id, $highStakes, true),
                'privacy_default' => in_array($id, $sensitiveDefault, true) ? 'sensitive' : 'normal',
                'validity_default' => in_array($id, $momentary, true) ? 'decaying' : 'permanent',
                'inferability' => self::inferabilityFor($id, $layer, $explicitOnly),
                'extraction_route' => $layer === OperatorTaxonomyRegistry::LAYER_SYSTEM ? 'system_runtime' : 'chat',
            ];
        }

        return $items;
    }

    /**
     * @param  list<string>  $explicitOnly
     */
    public static function inferabilityFor(string $id, int $layer, array $explicitOnly): string
    {
        if ($layer === OperatorTaxonomyRegistry::LAYER_SYSTEM) {
            return 'system_telemetry';
        }
        if (in_array($id, $explicitOnly, true)) {
            return 'explicit_only';
        }

        return 'inferable';
    }
}
