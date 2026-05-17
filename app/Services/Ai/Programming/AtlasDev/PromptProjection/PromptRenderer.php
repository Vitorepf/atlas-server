<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\PromptProjection;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\PromptSections;
use Illuminate\Support\Facades\Blade;
use RuntimeException;

/**
 * Renders a ProviderPromptProjection text via the canonical Blade template.
 *
 * Determinism contract: same (sections + upstreamHashes + identifiers) =
 * byte-identical output. No clock, no random, no environment lookups.
 */
final class PromptRenderer
{
    public const TEMPLATE_RELATIVE_PATH = 'resources/views/atlas_dev/provider_prompt.md.blade.php';

    private const TEMPLATE_RESOURCE_PATH = 'views/atlas_dev/provider_prompt.md.blade.php';

    private ?string $templateContents = null;

    /**
     * @param  array<string, string>  $upstreamHashes
     * @param  array{flow_id: string, flow_origin: string, command_intent: ?string, workspace_hash: string}|null  $flow
     *                                                                                                                   Atlas Dev flow identity stamped into the prompt header. When null
     *                                                                                                                   (legacy callers) defaults are emitted so the template never shows
     *                                                                                                                   empty headers.
     * @param  array{provider: string, model_family: string, fallback_allowed: bool}|null  $providerLock
     *                                                                                                    Provider lock fields rendered explicitly so the model sees that
     *                                                                                                    fallback is forbidden.
     * @param  list<array{path: string, sha256: string, content: string, truncated: bool}>  $fileExcerpts
     */
    public function render(
        string $runId,
        string $provider,
        string $modelFamily,
        array $upstreamHashes,
        PromptSections $sections,
        ?array $flow = null,
        ?array $providerLock = null,
        array $fileExcerpts = [],
    ): string {
        $template = $this->loadTemplate();

        $rendered = Blade::render($template, [
            'runId' => $runId,
            'provider' => $provider,
            'modelFamily' => $modelFamily,
            'upstreamHashes' => $this->normaliseUpstreamHashes($upstreamHashes),
            'sections' => $this->sectionsToTemplateArray($sections),
            'flow' => $this->normaliseFlow($flow),
            'providerLock' => $this->normaliseProviderLock($providerLock, $provider, $modelFamily),
            'fileExcerpts' => $this->normaliseFileExcerpts($fileExcerpts),
        ], deleteCachedView: true);

        return $this->normaliseLineEndings($rendered);
    }

    public function templatePath(): string
    {
        $path = $this->resolveTemplatePath();
        if (! is_file($path)) {
            throw new RuntimeException(
                'Atlas Dev provider prompt template not found at '.self::TEMPLATE_RELATIVE_PATH
            );
        }

        return $path;
    }

    public function templateSha256(): string
    {
        return hash('sha256', $this->loadTemplate());
    }

    private function loadTemplate(): string
    {
        if ($this->templateContents !== null) {
            return $this->templateContents;
        }

        $contents = file_get_contents($this->templatePath());
        if ($contents === false) {
            throw new RuntimeException(
                'Unable to read Atlas Dev provider prompt template at '.self::TEMPLATE_RELATIVE_PATH
            );
        }

        $this->templateContents = $contents;

        return $contents;
    }

    private function resolveTemplatePath(): string
    {
        if (function_exists('resource_path')) {
            return resource_path(self::TEMPLATE_RESOURCE_PATH);
        }

        return dirname(__DIR__, 6).'/resources/'.self::TEMPLATE_RESOURCE_PATH;
    }

    /**
     * @param  array<string, string>  $hashes
     * @return array<string, string>
     */
    private function normaliseUpstreamHashes(array $hashes): array
    {
        $clean = [];
        foreach ($hashes as $name => $hash) {
            if (! is_string($name) || ! is_string($hash) || $name === '' || $hash === '') {
                continue;
            }
            $clean[$name] = $hash;
        }
        ksort($clean, SORT_STRING);

        return $clean;
    }

    /**
     * @return array<string, mixed>
     */
    private function sectionsToTemplateArray(PromptSections $sections): array
    {
        return [
            'objective' => $sections->objective,
            'operating_rules' => $sections->operatingRules,
            'mini_spec_ref' => $sections->miniSpecRef,
            'task_contract_ref' => $sections->taskContractRef,
            'context_refs' => $sections->contextRefs,
            'code_discovery_ref' => $sections->codeDiscoveryRef,
            'allowed_files' => $sections->allowedFiles,
            'forbidden_files' => $sections->forbiddenFiles,
            'expected_tests' => $sections->expectedTests,
            'acceptance_criteria' => $sections->acceptanceCriteria,
            'stop_conditions' => $sections->stopConditions,
            'escalation_conditions' => $sections->escalationConditions,
            'output_contract' => $sections->outputContract,
            'non_goals' => $sections->nonGoals,
        ];
    }

    /**
     * @param  array{flow_id?: string, flow_origin?: string, command_intent?: ?string, workspace_hash?: string}|null  $flow
     * @return array{flow_id: string, flow_origin: string, command_intent: string, workspace_hash: string}
     */
    private function normaliseFlow(?array $flow): array
    {
        $flowId = is_array($flow) && isset($flow['flow_id']) && is_string($flow['flow_id']) && $flow['flow_id'] !== ''
            ? $flow['flow_id']
            : 'atlas_dev';
        $flowOrigin = is_array($flow) && isset($flow['flow_origin']) && is_string($flow['flow_origin']) && $flow['flow_origin'] !== ''
            ? $flow['flow_origin']
            : 'direct';
        $commandIntent = is_array($flow) && isset($flow['command_intent']) && is_string($flow['command_intent']) && $flow['command_intent'] !== ''
            ? $flow['command_intent']
            : 'none';
        $workspaceHash = is_array($flow) && isset($flow['workspace_hash']) && is_string($flow['workspace_hash']) && $flow['workspace_hash'] !== ''
            ? $flow['workspace_hash']
            : 'unknown';

        return [
            'flow_id' => $flowId,
            'flow_origin' => $flowOrigin,
            'command_intent' => $commandIntent,
            'workspace_hash' => $workspaceHash,
        ];
    }

    /**
     * @param  array{provider?: string, model_family?: string, fallback_allowed?: bool}|null  $providerLock
     * @return array{provider: string, model_family: string, fallback_allowed: string}
     */
    private function normaliseProviderLock(?array $providerLock, string $provider, string $modelFamily): array
    {
        $effectiveProvider = is_array($providerLock) && isset($providerLock['provider']) && is_string($providerLock['provider']) && $providerLock['provider'] !== ''
            ? $providerLock['provider']
            : $provider;
        $effectiveModel = is_array($providerLock) && isset($providerLock['model_family']) && is_string($providerLock['model_family']) && $providerLock['model_family'] !== ''
            ? $providerLock['model_family']
            : $modelFamily;
        $fallback = is_array($providerLock) && array_key_exists('fallback_allowed', $providerLock)
            ? (bool) $providerLock['fallback_allowed']
            : false;

        return [
            'provider' => $effectiveProvider,
            'model_family' => $effectiveModel,
            'fallback_allowed' => $fallback ? 'true' : 'false',
        ];
    }

    /**
     * @param  list<array{path?: string, sha256?: string, content?: string, truncated?: bool}>  $fileExcerpts
     * @return list<array{path: string, sha256: string, content: string, truncated: string}>
     */
    private function normaliseFileExcerpts(array $fileExcerpts): array
    {
        $normalised = [];
        foreach ($fileExcerpts as $excerpt) {
            $path = isset($excerpt['path']) && is_string($excerpt['path']) ? trim($excerpt['path']) : '';
            $sha = isset($excerpt['sha256']) && is_string($excerpt['sha256']) ? trim($excerpt['sha256']) : '';
            $content = isset($excerpt['content']) && is_string($excerpt['content']) ? $excerpt['content'] : '';
            if ($path === '' || $sha === '' || $content === '') {
                continue;
            }
            $normalised[] = [
                'path' => $path,
                'sha256' => $sha,
                'content' => $content,
                'truncated' => (bool) ($excerpt['truncated'] ?? false) ? 'true' : 'false',
            ];
        }

        return $normalised;
    }

    private function normaliseLineEndings(string $rendered): string
    {
        return str_replace(["\r\n", "\r"], "\n", $rendered);
    }
}
