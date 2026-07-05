<?php

declare(strict_types=1);

namespace App\Services\Ai\ControlPlane;

use App\Services\Ai\Support\DatabaseTableAvailability;

/**
 * Disponibilidade de tabelas do plano de controle — era clonado byte a byte nos 6
 * services do ControlPlane (censo de metodos duplicados 05/07); a unica divergencia
 * era a const consultada (REQUIRED_TABLES vs POSSIBLE_TABLES no Router), agora
 * parametro do helper.
 */
trait ChecksControlPlaneTableAvailability
{
    /**
     * @param  list<string>  $requiredTables
     * @return array{status:AtlasControlPlaneStatus, tables:array<string,bool>, tables_present:int, tables_required:int}
     */
    private function tableAvailabilityFor(array $requiredTables): array
    {
        $tables = [];
        $present = 0;
        foreach ($requiredTables as $table) {
            $exists = DatabaseTableAvailability::has($table);
            $tables[$table] = $exists;
            if ($exists) {
                $present++;
            }
        }

        $status = match (true) {
            $present === 0 => AtlasControlPlaneStatus::MISSING,
            $present === count($requiredTables) => AtlasControlPlaneStatus::READY,
            default => AtlasControlPlaneStatus::DEGRADED,
        };

        return [
            'status' => $status,
            'tables' => $tables,
            'tables_present' => $present,
            'tables_required' => count($requiredTables),
        ];
    }
}
