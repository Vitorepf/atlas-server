<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

/**
 * Atlas Forge Rivals · Arm Command Builder v1.
 *
 * Centralizes subprocess commands for executable provider arms. Runner
 * services pass an already-resolved arm contract and prompt; this service is
 * the only place that knows CLI flags such as Claude `--model`, Codex `-m`
 * and Gemini `--model`.
 */
final class AtlasForgeRivalsArmCommandBuilderService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.arm_command_builder.v1';

    public function __construct(
        private readonly AtlasForgeRivalsProviderModelRegistryService $models,
    ) {}

    /**
     * @param  array<string,mixed>  $contract
     * @return array{
     *   ok:bool,
     *   command:list<string>,
     *   provider:?string,
     *   model:?string,
     *   model_id:?string,
     *   command_family:?string,
     *   prompt_transport?:string,
     *   stdin_prompt_hash?:string,
     *   blockers:list<string>
     * }
     */
    public function build(array $contract, string $prompt, string $worktree): array
    {
        $provider = strtolower(trim((string) ($contract['provider'] ?? data_get($contract, 'arm.provider', ''))));
        $model = (string) ($contract['resolved_model'] ?? '');
        $modelId = (string) ($contract['resolved_model_id'] ?? $model);
        $armId = (string) data_get($contract, 'arm.arm_id', $contract['arm_id'] ?? '');

        if ($armId === 'atlas_dev') {
            return $this->ok('atlas_dev_runtime', $model, $modelId, 'atlas_dev_runtime', [
                'atlas_dev_runtime',
                'SeniorEngineerLoopExecutor',
            ], [
                'prompt_transport' => 'atlas_dev_provider_prompt_projection',
                'stdin_prompt_hash' => null,
                'stdin_prompt_bytes' => null,
                'command_shape_summary' => [
                    'schema_version' => 'atlas.forge.rivals.atlas_dev_runtime_command_shape_summary.v1',
                    'runtime_executor' => 'SeniorEngineerLoopExecutor',
                    'uses_atlas_dev_fast_path_orchestrator' => true,
                    'uses_pipeline_run_executor' => true,
                    'provider_cli_is_not_directly_spawned_by_rivals' => true,
                    'sonnet_lock_owned_by_atlas_dev_runtime' => true,
                ],
            ]);
        }

        if ($provider === '') {
            return $this->blocked($provider, $model, $modelId, ['provider_missing_for_arm:'.$armId]);
        }

        return match ($provider) {
            'claude' => $this->claudeCommand($prompt, $model, $modelId),
            'codex' => $this->codexCommand($prompt, $model, $modelId, $worktree),
            'gemini' => $this->geminiCommand($prompt, $model, $modelId),
            'cursor' => $this->cursorCommand($prompt, $model, $modelId, 'cursor_cli'),
            'composer' => $this->cursorCommand($prompt, $model, $modelId, 'composer_2_5'),
            default => $this->blocked($provider, $model, $modelId, ['provider_command_builder_missing:'.$provider]),
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function claudeCommand(string $prompt, string $model, string $modelId): array
    {
        $binary = $this->providerBinary('claude');
        if (! (bool) ($binary['ok'] ?? false)) {
            return $this->blocked('claude', $model, $modelId, (array) ($binary['blockers'] ?? []));
        }

        return $this->ok('claude', $model, $modelId, 'claude_cli', [
            (string) $binary['binary'],
            '--model',
            $modelId,
            '--permission-mode',
            'bypassPermissions',
            '--output-format',
            'stream-json',
            '--verbose',
            '-p',
            $prompt,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function codexCommand(string $prompt, string $model, string $modelId, string $worktree): array
    {
        $binary = $this->providerBinary('codex');
        if (! (bool) ($binary['ok'] ?? false)) {
            return $this->blocked('codex', $model, $modelId, (array) ($binary['blockers'] ?? []));
        }
        $args = (array) (config('atlas.ai.providers.codex_cli.args') ?: ['exec', '--skip-git-repo-check']);
        $args = array_values(array_map(static fn ($arg): string => (string) $arg, $args));
        if ($args === [] || $args[0] !== 'exec') {
            array_unshift($args, 'exec');
        }
        if (! in_array('--skip-git-repo-check', $args, true)) {
            $args[] = '--skip-git-repo-check';
        }

        return $this->ok('codex', $model, $modelId, 'codex_cli', array_values(array_merge(
            [(string) $binary['binary']],
            $args,
            ['--json', '-m', $modelId, '-C', $worktree, $prompt],
        )));
    }

    /**
     * @return array<string,mixed>
     */
    private function geminiCommand(string $prompt, string $model, string $modelId): array
    {
        $binary = $this->providerBinary('gemini');
        if (! (bool) ($binary['ok'] ?? false)) {
            return $this->blocked('gemini', $model, $modelId, (array) ($binary['blockers'] ?? []));
        }

        return $this->ok('gemini', $model, $modelId, 'gemini_cli', [
            (string) $binary['binary'],
            '--model',
            $modelId,
            '--output-format',
            'stream-json',
            '--approval-mode',
            'yolo',
            '--prompt',
            $prompt,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function cursorCommand(string $prompt, string $model, string $modelId, string $family): array
    {
        $provider = $family === 'composer_2_5' ? 'composer' : 'cursor';
        $outputFormat = strtolower(trim((string) (config('atlas.ai.providers.cursor_cli.output_format') ?: 'stream-json')));
        if ($outputFormat !== 'stream-json') {
            return $this->blocked($provider, $model, $modelId, ['cursor_cli_output_format_must_be_stream_json']);
        }
        if ((bool) config('atlas.ai.providers.cursor_cli.force', false) === true) {
            return $this->blocked($provider, $model, $modelId, ['cursor_cli_force_mode_forbidden']);
        }

        $binary = $this->providerBinary($provider);
        if (! (bool) ($binary['ok'] ?? false)) {
            return $this->blocked($provider, $model, $modelId, (array) ($binary['blockers'] ?? []));
        }

        return $this->ok($provider, $model, $modelId, $family, [
            (string) $binary['binary'],
            '--print',
            '--output-format',
            $outputFormat,
            '--model',
            $modelId,
        ], [
            'prompt_transport' => 'stdin',
            'stdin_prompt_hash' => hash('sha256', $prompt),
            'stdin_prompt_bytes' => strlen($prompt),
            'command_shape_summary' => [
                'schema_version' => 'atlas.forge.rivals.cursor_command_shape_summary.v1',
                'print_mode' => true,
                'output_format_stream_json' => true,
                'model_arg_present' => true,
                'force_absent' => true,
                'resume_absent' => true,
                'prompt_arg_absent' => true,
                'governed_cursor_cli_shape' => true,
            ],
        ]);
    }

    /**
     * @param  list<string>  $command
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function ok(string $provider, string $model, string $modelId, string $family, array $command, array $extra = []): array
    {
        return array_merge([
            'ok' => true,
            'command' => $command,
            'provider' => $provider,
            'model' => $model,
            'model_id' => $modelId,
            'command_family' => $family,
            'blockers' => [],
        ], $extra);
    }

    /**
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function blocked(string $provider, string $model, string $modelId, array $blockers): array
    {
        return [
            'ok' => false,
            'command' => [],
            'provider' => $provider !== '' ? $provider : null,
            'model' => $model !== '' ? $model : null,
            'model_id' => $modelId !== '' ? $modelId : null,
            'command_family' => null,
            'blockers' => $blockers,
        ];
    }

    /**
     * @return array{ok:bool,provider:string,binary:?string,binary_config_key:?string,blockers:list<string>}
     */
    private function providerBinary(string $provider): array
    {
        return $this->models->binaryForProvider($provider);
    }
}
