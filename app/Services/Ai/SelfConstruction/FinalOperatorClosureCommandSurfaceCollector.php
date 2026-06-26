<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\FinalOperatorClosureCorridor\OperatorCommandSurfaceIntegrityInspector;

/**
 * OPERATOR-COMMAND-SURFACE helper cluster, extracted from the god-class
 * {@see AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService}.
 *
 * Owns the command-surface collection helpers (collectOperatorCommands,
 * uniqueCommandsByText, extractCommandOptions, selfConstructionCommandOptions,
 * legacySelfConstructionCommandAliases) plus the integrity rollup
 * (operatorCommandSurfaceIntegrity). The corpus already lives in
 * {@see OperatorCommandSurfaceIntegrityInspector} as static methods; this
 * collector is the object-oriented seam the orchestrator now delegates to.
 */
final class FinalOperatorClosureCommandSurfaceCollector
{
    /**
     * @param  array<string, mixed>  $surface
     * @return array<string, mixed>
     */
    public function operatorCommandSurfaceIntegrity(array $surface): array
    {
        return OperatorCommandSurfaceIntegrityInspector::integrity($surface);
    }

    /**
     * @param  array<string, string>  $commands
     * @return array<string, string>
     */
    public function uniqueCommandsByText(array $commands): array
    {
        return OperatorCommandSurfaceIntegrityInspector::uniqueCommandsByText($commands);
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<string, string>
     */
    public function collectOperatorCommands(array $value, string $path = 'payload'): array
    {
        return OperatorCommandSurfaceIntegrityInspector::collectOperatorCommands($value, $path);
    }

    /**
     * @return list<string>
     */
    public function extractCommandOptions(string $command): array
    {
        return OperatorCommandSurfaceIntegrityInspector::extractCommandOptions($command);
    }

    /**
     * @return list<string>
     */
    public function selfConstructionCommandOptions(): array
    {
        return OperatorCommandSurfaceIntegrityInspector::selfConstructionCommandOptions();
    }

    /**
     * @return list<string>
     */
    public function legacySelfConstructionCommandAliases(): array
    {
        return OperatorCommandSurfaceIntegrityInspector::legacySelfConstructionCommandAliases();
    }
}
