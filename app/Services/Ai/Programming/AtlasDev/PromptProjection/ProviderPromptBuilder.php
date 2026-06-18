<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\PromptProjection;

use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\CodeDiscoveryManifest;
use App\Services\Ai\Programming\AtlasDev\Schemas\CompactSdd;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\PromptSections;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\QualityChecks;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\MiniProgrammingSpec;
use App\Services\Ai\Programming\AtlasDev\Schemas\OpenBrainProgrammingProjection;
use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\WorkspaceMutatingProviders;

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
        array $knownFailureModes = [],
    ): ProviderPromptProjection {
        $sections = $this->sectionsMapper->map(
            envelope: $envelope,
            miniSpec: $miniSpec,
            taskContract: $taskContract,
            discovery: $discovery,
            projection: $projection,
            knownFailureModes: $knownFailureModes,
        );

        $provider = $taskContract->providerLock->provider !== ''
            ? $taskContract->providerLock->provider
            : 'claude_cli';

        $modelFamily = $taskContract->providerLock->modelFamily !== ''
            ? $taskContract->providerLock->modelFamily
            : 'sonnet';

        $sections = $this->adaptSectionsForProvider($sections, $provider);

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

    private function adaptSectionsForProvider(PromptSections $sections, string $provider): PromptSections
    {
        // Single source of truth: only providers that edit the worktree directly
        // receive the in-place mutation contract. Every other provider (the Claude
        // gateway) keeps the default text-diff contract from PromptSectionsMapper.
        // This list mirrors PipelineRunExecutor::providerMutatedWorkspace() exactly
        // so the prompt the provider is told can never diverge from how Atlas reads
        // the result. {@see WorkspaceMutatingProviders}
        if (! WorkspaceMutatingProviders::includes($provider)) {
            return $sections;
        }

        $label = $this->mutatingProviderLabel($provider);

        return new PromptSections(
            objective: $sections->objective,
            operatingRules: [
                'Esta corrida tem exatamente uma chamada principal ao provider.',
                $label.' deve editar diretamente apenas arquivos listados em allowed_files no worktree isolado.',
                'Caminhos em forbidden_files nunca podem ser tocados, nem para leitura sensivel.',
                'Atlas captura o git diff apos a execucao do '.$label.' e aplica validacao fora do provider.',
                'Se os acceptance criteria nao forem executaveis no estado atual, responda blocked com a causa verificavel; no_patch_needed so e valido quando o codigo/teste existente ja prova o objetivo.',
                'Se houver ambiguidade que impeca o avanco, responda blocked com a pergunta exata necessaria para destravar.',
                'Nao expanda o escopo: nada de refator oportunista, dependencia nova ou flag de configuracao.',
                'Patches pequenos sao preferidos a refactors amplos; quebre em diff minimo.',
                'Preserve as mudancas preexistentes do usuario no worktree; nao reverta arquivos fora do diff.',
                'Use context_refs como leitura primaria; nao invente paths nem cite arquivos fora da lista.',
                'Rode apenas verificacoes diretamente relacionadas quando necessario e reporte evidence_path ou comando executado.',
                'A resposta deve caber exatamente nas secoes do output_contract; sem narrativa solta.',
            ],
            miniSpecRef: $sections->miniSpecRef,
            taskContractRef: $sections->taskContractRef,
            contextRefs: $sections->contextRefs,
            codeDiscoveryRef: $sections->codeDiscoveryRef,
            allowedFiles: $sections->allowedFiles,
            forbiddenFiles: $sections->forbiddenFiles,
            expectedTests: $sections->expectedTests,
            acceptanceCriteria: $sections->acceptanceCriteria,
            stopConditions: $sections->stopConditions,
            escalationConditions: $sections->escalationConditions,
            outputContract: [
                'workspace_mutation feita diretamente pelo '.$label.' apenas em allowed_files',
                'lista de changed_files (paths relativos ao workspace) capturados pelo Atlas apos a execucao',
                'testes/verificacoes executados ou motivo verificavel para nao executar',
                'no_patch_needed=true somente quando o codigo/teste existente ja prova este objetivo especifico',
                'acceptance_criteria com status (pass|fail|untested) e evidence_path (log/teste) quando aplicavel',
                'blocked=true + pergunta unica quando uma ambiguidade impedir progresso',
            ],
            providerSafe: $sections->providerSafe,
            nonGoals: $sections->nonGoals,
            knownFailureModes: $sections->knownFailureModes,
            definitionOfDone: $sections->definitionOfDone,
        );
    }

    /**
     * Human-facing label for a workspace-mutating provider, used verbatim in the
     * in-place mutation contract so the model knows which CLI it is. cursor_cli
     * keeps its historical "Cursor CLI" wording; the others name themselves.
     */
    private function mutatingProviderLabel(string $provider): string
    {
        return match (strtolower(trim($provider))) {
            'cursor_cli' => 'Cursor CLI',
            'codex_cli' => 'Codex CLI',
            'minimax_m27_cli' => 'MiniMax CLI',
            'hermes_cli' => 'Hermes',
            default => 'o provider de execucao',
        };
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
        $safe = strtr($contents, [
            "'.env'" => "'REDACTED_ENV_FILE'",
            '".env"' => '"REDACTED_ENV_FILE"',
            '.env' => 'REDACTED_ENV_FILE',
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

        $patterns = [
            '/\b[A-Z0-9_]*API[_-]?KEY[A-Z0-9_]*\b/i' => 'REDACTED_PROVIDER_TOKEN_NAME',
            '/\bAWS_SECRET_ACCESS_KEY\b/i' => 'REDACTED_PROVIDER_TOKEN_NAME',
            '/authorization:\s*bearer\s+[A-Za-z0-9._\-]+/i' => 'authorization: bearer REDACTED',
            '/bearer\s+ey[A-Za-z0-9._\-]+/i' => 'bearer REDACTED',
            '/sk-ant-[A-Za-z0-9._\-]+/i' => 'sk-ant-REDACTED',
            '/password\s*=\s*[^\\s,;]+/i' => 'password=REDACTED',
            '/secret\s*=\s*[^\\s,;]+/i' => 'secret=REDACTED',
            '/private_key/i' => 'REDACTED_PRIVATE_KEY_LABEL',
        ];
        foreach ($patterns as $pattern => $replacement) {
            $safe = (string) preg_replace($pattern, $replacement, $safe);
        }

        return $safe;
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
