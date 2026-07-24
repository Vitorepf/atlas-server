<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Coverage;

use App\Services\Ai\ExecutionAuthority\AwisExecutionGatePort;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceExecutionGateService;

final class EngineeringExecutionSurfaceRegistry
{
    public const SCHEMA_VERSION = 'atlas.engineering_execution_surface_registry.v1';

    /**
     * Confirmed mutative engineering execution surfaces. These are the places
     * where "kernel routed" is insufficient unless execution receipts line up.
     *
     * @var array<string,array{id:string,path:string,owner:string,mutative:bool,awis_gate_class:class-string,awis_mode:string}>
     */
    private const SURFACES = [
        'atlas_dev.pipeline_run_executor' => [
            'id' => 'atlas_dev.pipeline_run_executor',
            'path' => 'app/Services/Ai/Programming/AtlasDev',
            'owner' => 'atlas_dev',
            'mutative' => true,
            'awis_gate_class' => AtlasWorkspaceIntelligenceExecutionGateService::class,
            'awis_mode' => 'dev',
        ],
        'atlas_forge.work_packet_execution_cycle' => [
            'id' => 'atlas_forge.work_packet_execution_cycle',
            'path' => 'app/Services/Ai/Programming/Forge',
            'owner' => 'atlas_forge',
            'mutative' => true,
            'awis_gate_class' => AtlasWorkspaceIntelligenceExecutionGateService::class,
            'awis_mode' => 'forge',
        ],
        'atlas_autonomos.task_serving' => [
            'id' => 'atlas_autonomos.task_serving',
            'path' => 'app/Services/Ai/SelfConstruction/TaskServing',
            'owner' => 'atlas_autonomos',
            'mutative' => true,
            'awis_gate_class' => AtlasWorkspaceIntelligenceExecutionGateService::class,
            // R102: Autônomos must not present as Dev (confused-deputy).
            'awis_mode' => 'autonomos',
        ],
        'atlas_autonomos.native_worker' => [
            'id' => 'atlas_autonomos.native_worker',
            'path' => 'app/Services/Ai/SelfConstruction',
            'owner' => 'atlas_autonomos',
            'mutative' => true,
            'awis_gate_class' => AtlasWorkspaceIntelligenceExecutionGateService::class,
            'awis_mode' => 'autonomos',
        ],
        'atlas_autonomos.commit_governance' => [
            'id' => 'atlas_autonomos.commit_governance',
            'path' => 'app/Services/Ai/SelfConstruction/AtlasTaskScopedCommitter.php',
            'owner' => 'atlas_autonomos',
            'mutative' => true,
            'awis_gate_class' => AtlasWorkspaceIntelligenceExecutionGateService::class,
            'awis_mode' => 'autonomos',
        ],
        'engineering_kernel.merge_actuator' => [
            'id' => 'engineering_kernel.merge_actuator',
            'path' => 'app/Services/Ai/EngineeringKernel/MergeActuator.php',
            'owner' => 'engineering_kernel',
            'mutative' => true,
            'awis_gate_class' => AtlasWorkspaceIntelligenceExecutionGateService::class,
            'awis_mode' => 'patch',
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
     * Behavioral medidor for ENG-07: execute the surface's registered AWIS gate
     * and fail closed when the workspace is not certified.
     *
     * @return array{surface:string,status:string,allowed:bool,blockers:list<string>,gate:array<string,mixed>}
     */
    public static function probeAwisGateRefusal(string $id, AwisExecutionGatePort $gate, ?string $workspace): array
    {
        $surface = self::SURFACES[$id] ?? null;
        if ($surface === null || ($surface['mutative'] ?? false) !== true) {
            throw new \InvalidArgumentException('Unknown mutative engineering surface: '.$id);
        }

        $verdict = $gate->gate(
            workspace: $workspace,
            mode: (string) $surface['awis_mode'],
            task: $id,
        );
        $allowed = ($verdict['allowed'] ?? false) === true;

        return [
            'surface' => $id,
            'status' => $allowed ? 'allowed' : 'refused',
            'allowed' => $allowed,
            'blockers' => array_values(array_map('strval', (array) ($verdict['blockers'] ?? []))),
            'gate' => $verdict,
        ];
    }

    /**
     * @return array{schema_version:string,count:int,surface_ids:list<string>,surfaces:list<array<string,mixed>>}
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
