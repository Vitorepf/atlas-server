<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Pipeline;

use App\Services\Ai\Programming\AtlasDev\Schemas\CodeDiscoveryManifest;
use App\Services\Ai\Programming\AtlasDev\Schemas\CompactSdd;
use App\Services\Ai\Programming\AtlasDev\Schemas\ContextRetrievalPlan;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\MiniProgrammingSpec;
use App\Services\Ai\Programming\AtlasDev\Schemas\OpenBrainProgrammingProjection;
use App\Services\Ai\Programming\AtlasDev\Schemas\OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;

/**
 * Result of {@see AtlasDevFastPathOrchestrator::planOnly()}.
 *
 * Carries every artifact produced by the plan layer plus the routing decision
 * and the list of persisted artifact paths. Provider was never called; no
 * patch was produced. Surface adapters project this into their native shape.
 *
 * Intentionally not a serialized schema — orchestrator output is a transport
 * struct; the canonical persisted artifacts each own their own schema/hash.
 */
final class PlanOnlyResult
{
    /**
     * @param  array<string, string>  $persistedArtifactPaths  artifact_name => absolute_path
     * @param  list<string>  $blockers
     */
    public function __construct(
        public readonly OperationEnvelope $envelope,
        public readonly TaskClassification $classification,
        public readonly string $riskLevel,
        public readonly CompactSdd $compactSdd,
        public readonly ContextRetrievalPlan $contextPlan,
        public readonly CodeDiscoveryManifest $discovery,
        public readonly OpenBrainProgrammingProjection $projection,
        public readonly MiniProgrammingSpec $miniSpec,
        public readonly LightTaskContract $taskContract,
        public readonly ProviderPromptProjection $promptProjection,
        public readonly RoutingDecision $routing,
        public readonly array $persistedArtifactPaths,
        public readonly array $blockers,
    ) {}

    public function routingKind(): string
    {
        return $this->routing->kind;
    }

    public function isFastPath(): bool
    {
        return $this->routing->kind === RoutingDecision::ATLAS_DEV_FAST_PATH;
    }

    public function isForgePreview(): bool
    {
        return $this->routing->kind === RoutingDecision::FORGE_PROMOTION_PREVIEW;
    }

    public function isReadOnly(): bool
    {
        return $this->routing->kind === RoutingDecision::READ_ONLY_ANSWER;
    }

    public function isBlocked(): bool
    {
        return $this->routing->kind === RoutingDecision::BLOCKED;
    }

    public function isDelegation(): bool
    {
        return $this->routing->kind === RoutingDecision::DELEGATE_TO_OTHER_FLOW;
    }

    public function suggestedFlow(): ?string
    {
        return $this->routing->suggestedFlow();
    }

    /**
     * Flat summary suitable for JSON output.
     *
     * Provider-safety (F-04): the summary intentionally exposes only the
     * non-absolute, workspace-redacted view of the run. The absolute workspace
     * path lives on the canonical envelope; UI surfaces use `workspace_label`
     * (basename) and `workspace_hash` (provider-safe identifier) instead.
     * Persisted artifact paths are surfaced via {@see persistedArtifactRefs()}
     * — never the absolute storage paths.
     *
     * Internal consumers that need the absolute paths can still read the
     * `persistedArtifactPaths` property directly.
     *
     * @return array<string, mixed>
     */
    public function toSummaryArray(): array
    {
        return [
            'run_id' => $this->envelope->runId,
            'workspace_label' => basename($this->envelope->workspace),
            'workspace_hash' => $this->envelope->workspaceHash,
            'surface_id' => $this->envelope->surfaceId,
            'task_kind' => $this->classification->taskKind,
            'risk_level' => $this->riskLevel,
            'intent_clarity_level' => $this->envelope->intentClarityLevel,
            'mode' => $this->compactSdd->mode,
            'routing_decision' => $this->routing->kind,
            'routing_reasons' => $this->routing->reasons,
            'suggested_flow' => $this->routing->suggestedFlow(),
            'delegation_reason' => $this->routing->delegation?->reason,
            'blockers' => $this->blockers,
            'discovery_confidence' => $this->discovery->confidence,
            'expected_files' => $this->workspaceSafePaths($this->miniSpec->expectedFiles),
            'allowed_files' => $this->workspaceSafePaths($this->miniSpec->allowedFiles),
            'verification_commands' => $this->miniSpec->verificationPlan->commands,
            'hashes' => [
                'envelope' => $this->envelope->envelopeHash,
                'compact_sdd' => $this->compactSdd->compactSddHash,
                'mini_spec' => $this->miniSpec->miniSpecHash,
                'task_contract' => $this->taskContract->taskContractHash,
                'discovery_manifest' => $this->discovery->manifestHash,
                'open_brain_projection' => $this->projection->projectionHash,
                'prompt_projection' => $this->promptProjection->promptProjectionHash,
                'context_plan' => $this->contextPlan->planHash,
            ],
            'persisted_artifact_refs' => $this->persistedArtifactRefs(),
            'prompt_sendable' => $this->promptProjection->isSendable(),
            'read_only_answer' => $this->readOnlyAnswer(),
        ];
    }

    /**
     * Return the canonical refs for the persisted artifact map. Refs are
     * relative to the storage receipts base (`receipts/<run_id>/<filename>`)
     * so a Desktop / API client never sees the absolute server path.
     *
     * @return array<string, string>
     */
    public function persistedArtifactRefs(): array
    {
        $refs = [];
        foreach ($this->persistedArtifactPaths as $name => $absolute) {
            if (! is_string($absolute) || $absolute === '') {
                continue;
            }
            $refs[(string) $name] = 'receipts/'.$this->envelope->runId.'/'.basename($absolute);
        }

        return $refs;
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function workspaceSafePaths(array $paths): array
    {
        $workspace = rtrim($this->envelope->workspace, DIRECTORY_SEPARATOR);
        $label = basename($workspace);
        $safe = [];

        foreach ($paths as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }

            $normalized = str_replace('\\', '/', $path);
            $workspaceNormalized = str_replace('\\', '/', $workspace);

            if ($workspaceNormalized !== '' && str_starts_with($normalized, $workspaceNormalized.'/')) {
                $safe[] = $label.'/'.ltrim(substr($normalized, strlen($workspaceNormalized)), '/');

                continue;
            }

            $safe[] = ltrim($normalized, '/');
        }

        return array_values($safe);
    }

    /**
     * Produce a narrow provider-free answer for confirmed code facts. This is
     * intentionally conservative: if no obvious constant matches the question,
     * surfaces get null instead of a guessed answer.
     *
     * @return array<string, mixed>|null
     */
    private function readOnlyAnswer(): ?array
    {
        if (! $this->isReadOnly()) {
            return null;
        }

        if ($this->classification->taskKind === TaskClassification::KIND_REVIEW) {
            return $this->readOnlyReviewAnswer();
        }

        if ($this->classification->taskKind !== TaskClassification::KIND_QUESTION) {
            return null;
        }

        $intentTokens = $this->readOnlyTokens($this->envelope->normalizedIntent);
        $best = null;
        $bestScore = 0;

        foreach ($this->discovery->likelyFiles as $candidate) {
            if (! is_file($candidate->path) || filesize($candidate->path) > 200_000) {
                continue;
            }

            $contents = (string) file_get_contents($candidate->path);
            if (! preg_match_all('/\\b(?:public|protected|private)?\\s*const\\s+([A-Z][A-Z0-9_]*)\\s*=\\s*([^;]+);/m', $contents, $matches, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($matches as $match) {
                $name = (string) ($match[1] ?? '');
                $value = trim((string) ($match[2] ?? ''));
                $constantTokens = $this->readOnlyTokens(str_replace('_', ' ', strtolower($name)));
                $score = count(array_intersect($intentTokens, $constantTokens));

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = [
                        'symbol' => $name,
                        'value' => trim($value, " \t\n\r\0\x0B'\""),
                        'source_ref' => $this->workspaceSafePaths([$candidate->path])[0] ?? basename($candidate->path),
                    ];
                }
            }
        }

        if ($best === null || $bestScore < 1) {
            return null;
        }

        return [
            'schema_version' => 'atlas.dev.read_only_answer.v1',
            'status' => 'answered',
            'confidence' => 'confirmed_fact',
            'answer' => $best['symbol'].' = '.$best['value'],
            'facts' => [$best],
            'provider_calls' => 0,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readOnlyReviewAnswer(): ?array
    {
        $findings = [];

        foreach ($this->discovery->likelyFiles as $candidate) {
            if (! is_file($candidate->path) || filesize($candidate->path) > 200_000) {
                continue;
            }

            $contents = (string) file_get_contents($candidate->path);
            $lines = preg_split('/\R/', $contents) ?: [];
            $count = count($lines);

            for ($index = 0; $index < $count; $index++) {
                $line = $lines[$index] ?? '';
                if (! preg_match('/foreach\s*\(.+\bas\s+\$[A-Za-z_][A-Za-z0-9_]*\)/', $line)) {
                    continue;
                }

                $windowEnd = min($count, $index + 8);
                for ($inner = $index + 1; $inner < $windowEnd; $inner++) {
                    $innerLine = $lines[$inner] ?? '';
                    if (preg_match('/^\s*\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*\$[A-Za-z_][A-Za-z0-9_]*\s*\[/', $innerLine, $match)) {
                        $findings[] = [
                            'severity' => 'high',
                            'source_ref' => ($this->workspaceSafePaths([$candidate->path])[0] ?? basename($candidate->path)).':'.($inner + 1),
                            'title' => 'Accumulator is overwritten inside loop',
                            'detail' => 'The loop assigns $'.$match[1].' from the current item on each iteration, so earlier items are discarded. This usually should accumulate or append.',
                        ];
                        break 2;
                    }

                    if (str_contains($innerLine, '}')) {
                        break;
                    }
                }
            }

            if ($findings !== []) {
                break;
            }
        }

        if ($findings === []) {
            return null;
        }

        return [
            'schema_version' => 'atlas.dev.read_only_answer.v1',
            'status' => 'answered',
            'confidence' => 'static_review_finding',
            'answer' => $findings[0]['title'].': '.$findings[0]['source_ref'],
            'findings' => $findings,
            'provider_calls' => 0,
        ];
    }

    /**
     * @return list<string>
     */
    private function readOnlyTokens(string $value): array
    {
        $tokens = preg_split('/[^a-z0-9]+/i', strtolower($value)) ?: [];
        $stop = [
            'a', 'as', 'com', 'do', 'does', 'de', 'da', 'das', 'days', 'dias',
            'e', 'em', 'for', 'from', 'in', 'o', 'of', 'os', 'para', 'period',
            'quantos', 'quantas', 'the', 'usa', 'using',
        ];

        return array_values(array_unique(array_filter(
            $tokens,
            static fn (string $token): bool => strlen($token) >= 3 && ! in_array($token, $stop, true),
        )));
    }
}
