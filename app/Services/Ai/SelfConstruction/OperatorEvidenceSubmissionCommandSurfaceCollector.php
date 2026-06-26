<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\OperatorEvidence\OperatorCommandSurfaceIntegrityAnalyzer;

/**
 * OPERATOR-COMMAND-SURFACE helper cluster for the submission-readiness surface,
 * extracted from the god-class
 * {@see AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService}.
 *
 * Owns the 5 helpers (collectOperatorCommands, extractCommandOptions,
 * selfConstructionCommandOptions, legacySelfConstructionCommandAliases) plus
 * the integrity rollup operatorCommandSurfaceIntegrity. The corpus lives in
 * {@see OperatorCommandSurfaceIntegrityAnalyzer} as static methods; this
 * collector is the object-oriented seam the orchestrator now delegates to.
 */
final class OperatorEvidenceSubmissionCommandSurfaceCollector
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function operatorCommandSurfaceIntegrity(array $payload): array
    {
        return OperatorCommandSurfaceIntegrityAnalyzer::integrity($payload);
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<string, string>
     */
    public function collectOperatorCommands(array $value, string $path = 'payload'): array
    {
        return OperatorCommandSurfaceIntegrityAnalyzer::collectOperatorCommands($value, $path);
    }

    /**
     * @return list<string>
     */
    public function extractCommandOptions(string $command): array
    {
        return OperatorCommandSurfaceIntegrityAnalyzer::extractCommandOptions($command);
    }

    /**
     * @return list<string>
     */
    public function selfConstructionCommandOptions(): array
    {
        return OperatorCommandSurfaceIntegrityAnalyzer::selfConstructionCommandOptions();
    }

    /**
     * @return list<string>
     */
    public function legacySelfConstructionCommandAliases(): array
    {
        return OperatorCommandSurfaceIntegrityAnalyzer::legacySelfConstructionCommandAliases();
    }
}
