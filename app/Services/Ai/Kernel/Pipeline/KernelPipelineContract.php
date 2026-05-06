<?php

namespace App\Services\Ai\Kernel\Pipeline;

final readonly class KernelPipelineContract
{
    public const SCHEMA_VERSION = 'atlas.kernel.pipeline.scaffold.v1';

    public const MODE = 'scaffold_dry_run';

    public const STATUS = 'planned_scaffold';

    /**
     * @return array<int,string>
     */
    public static function programmingSurfaces(): array
    {
        return ['atlas_cli_dev', 'atlas_cli_forge', 'atlas_ai_chat'];
    }

    /**
     * @return array<int,string>
     */
    public static function programmingFlows(): array
    {
        return ['programming.dev', 'programming.forge', 'programming.repair'];
    }

    /**
     * @return array<int,string>
     */
    public static function programmingInputModes(): array
    {
        return ['interactive', 'one_shot', 'declared_dev_plan', 'chat_dev_auto_plan'];
    }

    /**
     * @return array<int,string>
     */
    public static function programmingCommands(): array
    {
        return ['atlas:cli:dev', 'atlas:ai:chat'];
    }

    /**
     * @return array<int,string>
     */
    public static function surfaceContractSources(): array
    {
        return ['KernelPipelineDevPlanBuilder'];
    }

    /**
     * @return array<string,bool|string>
     */
    public static function requiredSurfaceContract(string $source): array
    {
        return [
            'required' => true,
            'source' => $source,
            'surface_must_not_decide' => true,
            'provider_execution_blocked_until_runtime_migration' => true,
            'runtime_execution_blocked_until_runtime_migration' => true,
        ];
    }

    /**
     * @return array<int,string>
     */
    public static function canonicalFlow(): array
    {
        return KernelPipelineStage::orderedValues();
    }

    public static function canonicalFlowHash(): string
    {
        return hash('sha256', json_encode(self::canonicalFlow(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{dry_run_requested:bool,dry_run_effective:bool,provider_execution_allowed:bool,runtime_execution_allowed:bool,surface_runtime_migration_allowed:bool}
     */
    public static function executionGuards(bool $dryRunRequested): array
    {
        return [
            'dry_run_requested' => $dryRunRequested,
            'dry_run_effective' => true,
            'provider_execution_allowed' => false,
            'runtime_execution_allowed' => false,
            'surface_runtime_migration_allowed' => false,
        ];
    }
}
