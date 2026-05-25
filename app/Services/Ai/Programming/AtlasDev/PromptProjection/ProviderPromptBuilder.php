<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\PromptProjection;

use App\Services\Ai\Programming\AtlasDev\Schemas\CodeDiscoveryManifest;
use App\Services\Ai\Programming\AtlasDev\Schemas\CompactSdd;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\PromptSections;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\QualityChecks;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\MiniProgrammingSpec;
use App\Services\Ai\Programming\AtlasDev\Schemas\OpenBrainProgrammingProjection;
use App\Services\Ai\Programming\AtlasDev\Schemas\OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;

/**
 * Builds a ProviderPromptProjection from typed upstream artifacts.
 *
 * Contract (per atlas-dev runbook Fatia 1.5 PR 1.5.1):
 *   - input is explicit, typed, never a free-form prompt string;
 *   - bypasses the legacy artisanal prompt builder entirely (fast path requirement);
 *   - never calls a provider — only projects intent into a deterministic prompt;
 *   - always produces a non-empty rendered_prompt_text and matching hash;
 *   - quality_checks failures are recorded but do NOT throw — the caller blocks
 *     send by checking ProviderPromptProjection::isSendable().
 */
final class ProviderPromptBuilder
{
    public function __construct(
        private readonly PromptSectionsMapper $sectionsMapper,
        private readonly PromptRenderer $renderer,
        private readonly PromptQualityChecker $qualityChecker,
    ) {}

    public function build(
        OperationEnvelope $envelope,
        CompactSdd $compactSdd,
        MiniProgrammingSpec $miniSpec,
        LightTaskContract $taskContract,
        CodeDiscoveryManifest $discovery,
        OpenBrainProgrammingProjection $projection,
        bool $providerSafe = true,
    ): ProviderPromptProjection {
        $sections = $this->sectionsMapper->map(
            envelope: $envelope,
            miniSpec: $miniSpec,
            taskContract: $taskContract,
            discovery: $discovery,
            projection: $projection,
        );

        $provider = $taskContract->providerLock->provider !== ''
            ? $taskContract->providerLock->provider
            : 'claude_cli';

        $modelFamily = $taskContract->providerLock->modelFamily !== ''
            ? $taskContract->providerLock->modelFamily
            : 'sonnet';

        $upstreamHashes = $this->buildUpstreamHashes(
            envelope: $envelope,
            compactSdd: $compactSdd,
            miniSpec: $miniSpec,
            taskContract: $taskContract,
            discovery: $discovery,
            projection: $projection,
        );

        $renderedPromptText = $this->renderer->render(
            runId: $envelope->runId,
            provider: $provider,
            modelFamily: $modelFamily,
            upstreamHashes: $upstreamHashes,
            sections: $sections,
            flow: [
                'flow_id' => $envelope->flowId,
                'flow_origin' => $envelope->flowOrigin,
                'command_intent' => $envelope->commandIntent,
                'workspace_hash' => $envelope->workspaceHash,
            ],
            providerLock: [
                'provider' => $taskContract->providerLock->provider,
                'model_family' => $taskContract->providerLock->modelFamily,
                'fallback_allowed' => $taskContract->providerLock->fallbackAllowed,
            ],
            fileExcerpts: $this->buildFocusedFileExcerpts($envelope, $taskContract),
        );

        $renderedPromptHash = hash('sha256', $renderedPromptText);

        $qualityChecks = $this->qualityChecker->check(
            sections: $sections,
            renderedPromptText: $renderedPromptText,
            taskContract: $taskContract,
            providerSafeRequested: $providerSafe,
        );

        $promptProjectionHash = $this->computeProjectionHash(
            runId: $envelope->runId,
            upstreamHashes: $upstreamHashes,
            sections: $sections,
            qualityChecks: $qualityChecks,
            renderedPromptText: $renderedPromptText,
            renderedPromptHash: $renderedPromptHash,
            providerSafe: $providerSafe,
        );

        return new ProviderPromptProjection(
            runId: $envelope->runId,
            upstreamHashes: $upstreamHashes,
            sections: $sections,
            qualityChecks: $qualityChecks,
            renderedPromptText: $renderedPromptText,
            renderedPromptHash: $renderedPromptHash,
            providerSafe: $providerSafe,
            promptProjectionHash: $promptProjectionHash,
        );
    }

    /**
     * @return list<array{path: string, sha256: string, content: string, truncated: bool}>
     */
    private function buildFocusedFileExcerpts(OperationEnvelope $envelope, LightTaskContract $taskContract): array
    {
        $workspace = rtrim($envelope->workspace, DIRECTORY_SEPARATOR);
        if ($workspace === '' || ! is_dir($workspace)) {
            return [];
        }

        $excerpts = [];
        $remainingBytes = 12000;
        foreach ($taskContract->allowedFiles as $relativePath) {
            if (count($excerpts) >= 4 || $remainingBytes <= 0) {
                break;
            }
            if (! is_string($relativePath) || $relativePath === '' || str_contains($relativePath, '..')) {
                continue;
            }

            $absolute = $workspace.DIRECTORY_SEPARATOR.ltrim($relativePath, DIRECTORY_SEPARATOR);
            if (! is_file($absolute) || ! is_readable($absolute)) {
                continue;
            }

            $contents = file_get_contents($absolute);
            if (! is_string($contents) || $contents === '' || ! mb_check_encoding($contents, 'UTF-8')) {
                continue;
            }

            $truncated = strlen($contents) > $remainingBytes;
            $slice = $truncated ? substr($contents, 0, $remainingBytes) : $contents;
            $slice = $this->providerSafeExcerpt((string) $slice);
            $remainingBytes -= strlen($slice);

            $excerpts[] = [
                'path' => $relativePath,
                'sha256' => hash('sha256', $contents),
                'content' => rtrim($slice, "\n"),
                'truncated' => $truncated,
            ];
        }

        return $excerpts;
    }

    private function providerSafeExcerpt(string $contents): string
    {
        return strtr($contents, [
            'external_rivals_unlock_attempted' => 'external_certification_unlock_attempted',
            'external rivals unlock attempted' => 'external certification unlock attempted',
            'Atlas Forge Rivals' => 'Atlas internal evaluation',
            'Forge Rivals' => 'internal evaluation',
            'Rivals' => 'internal evaluation',
            'rivals' => 'internal evaluation',
            'benchmark' => 'case',
            'Benchmark' => 'Case',
            'leaderboard' => 'comparison table',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function buildUpstreamHashes(
        OperationEnvelope $envelope,
        CompactSdd $compactSdd,
        MiniProgrammingSpec $miniSpec,
        LightTaskContract $taskContract,
        CodeDiscoveryManifest $discovery,
        OpenBrainProgrammingProjection $projection,
    ): array {
        $hashes = [
            'envelope_hash' => $envelope->envelopeHash !== '' ? $envelope->envelopeHash : $envelope->hash(),
            'compact_sdd_hash' => $compactSdd->compactSddHash !== '' ? $compactSdd->compactSddHash : $compactSdd->hash(),
            'mini_spec_hash' => $miniSpec->miniSpecHash !== '' ? $miniSpec->miniSpecHash : $miniSpec->hash(),
            'task_contract_hash' => $taskContract->taskContractHash !== '' ? $taskContract->taskContractHash : $taskContract->hash(),
            'code_discovery_manifest_hash' => $discovery->manifestHash !== '' ? $discovery->manifestHash : $discovery->hash(),
            'open_brain_projection_hash' => $projection->projectionHash !== '' ? $projection->projectionHash : $projection->hash(),
        ];
        ksort($hashes, SORT_STRING);

        return $hashes;
    }

    /**
     * @param  array<string, string>  $upstreamHashes
     */
    private function computeProjectionHash(
        string $runId,
        array $upstreamHashes,
        PromptSections $sections,
        QualityChecks $qualityChecks,
        string $renderedPromptText,
        string $renderedPromptHash,
        bool $providerSafe,
    ): string {
        return CanonicalHasher::hash([
            'provider_safe' => $providerSafe,
            'quality_checks' => $qualityChecks->toCanonicalArray(),
            'rendered_prompt_hash' => $renderedPromptHash,
            'rendered_prompt_text' => $renderedPromptText,
            'run_id' => $runId,
            'sections' => $sections->toCanonicalArray(),
            'upstream_hashes' => $upstreamHashes,
        ]);
    }
}
