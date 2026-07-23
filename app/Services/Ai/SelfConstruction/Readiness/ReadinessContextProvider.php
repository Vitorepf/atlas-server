<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

/**
 * The single constructor of ReadinessProjectionContext.
 *
 * ARCH BLUEPRINT SelfConstructionReadiness §2.1: the ONLY class in the Readiness
 * aggregate authorized to touch Schema (or Eloquent) for projection probes.
 * It imports Illuminate\Support\Facades\Schema correctly — the class of fatal
 * that A1-SC-0019 produced (an unimported Schema in a Section) cannot recur here.
 */
final class ReadinessContextProvider
{
    /**
     * Probe each requested table exactly once and freeze the result.
     *
     * @param  list<string>  $tables
     * @param  array<string, mixed>  $snapshots  pre-computed aggregate snapshots
     */
    public function build(array $tables = [], array $snapshots = []): ReadinessProjectionContext
    {
        $tableExists = [];
        foreach ($tables as $table) {
            $table = (string) $table;
            if ($table === '' || array_key_exists($table, $tableExists)) {
                continue;
            }
            $tableExists[$table] = Schema::hasTable($table);
        }

        return new ReadinessProjectionContext($tableExists, CarbonImmutable::now(), $snapshots);
    }
}
