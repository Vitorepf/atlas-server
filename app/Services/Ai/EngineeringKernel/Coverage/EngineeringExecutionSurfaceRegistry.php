<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Coverage;

final class EngineeringExecutionSurfaceRegistry
{
    public const SCHEMA_VERSION = 'atlas.engineering_execution_surface_registry.v1';

    /**
     * Confirmed mutative engineering execution surfaces. These are the places
     * where "kernel routed" is insufficient unless execution receipts line up.
     *
     * @var array<string,array{id:string,path:string,owner:string,mutative:bool}>
     */
    private const SURFACES = [
        'atlas_dev.pipeline_run_executor' => [
            'id' => 'atlas_dev.pipeline_run_executor',
            'path' => 'app/Services/Ai/Programming/AtlasDev',
            'owner' => 'atlas_dev',
            'mutative' => true,
        ],
        'atlas_forge.work_packet_execution_cycle' => [
            'id' => 'atlas_forge.work_packet_execution_cycle',
            'path' => 'app/Services/Ai/Programming/Forge',
            'owner' => 'atlas_forge',
            'mutative' => true,
        ],
        'atlas_autonomos.task_serving' => [
            'id' => 'atlas_autonomos.task_serving',
            'path' => 'app/Services/Ai/SelfConstruction/TaskServing',
            'owner' => 'atlas_autonomos',
            'mutative' => true,
        ],
        'atlas_autonomos.native_worker' => [
            'id' => 'atlas_autonomos.native_worker',
            'path' => 'app/Services/Ai/SelfConstruction',
            'owner' => 'atlas_autonomos',
            'mutative' => true,
        ],
        'atlas_autonomos.commit_governance' => [
            'id' => 'atlas_autonomos.commit_governance',
            'path' => 'app/Services/Ai/SelfConstruction/AtlasTaskScopedCommitter.php',
            'owner' => 'atlas_autonomos',
            'mutative' => true,
        ],
        'engineering_kernel.merge_actuator' => [
            'id' => 'engineering_kernel.merge_actuator',
            'path' => 'app/Services/Ai/EngineeringKernel/MergeActuator.php',
            'owner' => 'engineering_kernel',
            'mutative' => true,
        ],
    ];

    /**
     * @return list<array{id:string,path:string,owner:string,mutative:bool}>
     */
    public static function all(): array
    {
        return array_values(self::SURFACES);
    }

    /**
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_keys(self::SURFACES);
    }

    /**
     * @return array{id:string,path:string,owner:string,mutative:bool}|null
     */
    public static function surface(string $id): ?array
    {
        return self::SURFACES[$id] ?? null;
    }

    public static function isConfirmedMutativeSurface(string $id): bool
    {
        return (bool) (self::SURFACES[$id]['mutative'] ?? false);
    }

    /**
     * @return array{schema_version:string,count:int,surface_ids:list<string>,surfaces:list<array{id:string,path:string,owner:string,mutative:bool}>}
     */
    public static function report(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'count' => count(self::SURFACES),
            'surface_ids' => self::ids(),
            'surfaces' => self::all(),
        ];
    }
}
