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
