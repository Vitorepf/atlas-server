<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Control;

/**
 * I/O allowed: builds world snapshot fail-open (empty/safe defaults if data missing).
 */
final class AaeosWorldSnapshotBuilder
{
    /**
     * @param  array<string,mixed>  $overrides
     */
    public function build(array $overrides = []): AaeosWorldSnapshot
    {
        $config = [];
        try {
            if (function_exists('config')) {
                $config = (array) config('atlas.aaeos.world', []);
            }
        } catch (\Throwable) {
            $config = [];
        }

        return AaeosWorldSnapshot::fromArray(array_merge([
            'incident_open' => (bool) ($config['incident_open'] ?? false),
            'queue_depth' => (int) ($config['queue_depth'] ?? 0),
            'budget_pressure' => (float) ($config['budget_pressure'] ?? 0.0),
            'recent_failure_count' => (int) ($config['recent_failure_count'] ?? 0),
        ], $overrides));
    }
}
