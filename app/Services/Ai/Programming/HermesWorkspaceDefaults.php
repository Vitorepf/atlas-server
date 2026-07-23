<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;


/**
 * Single source of truth for the two settings every Atlas path that runs Hermes
 * as an autonomous WORKSPACE EDITOR must get right — and which previously each
 * call site got wrong independently (the same bug fixed three times):
 *
 *   1. {@see self::model()} — Hermes is a meta-provider that routes its own
 *      sub-model (gpt-5.5/codex by default). It must receive the Hermes default
 *      sentinel (a model id ending in `_default`, which
 *      {@see \App\Services\Ai\HermesCliProvider::invocationModel()} maps to
 *      "omit --model"), NEVER an Atlas-Decide/Dev model family such as `sonnet`
 *      or `minimax-m3`, which the Hermes CLI rejects with cli_error.
 *
 *   2. {@see self::toolPermissions()} — `mode: 'danger'` so HermesCliProvider
 *      passes `--yolo` and Hermes edits the workspace AUTONOMOUSLY (a
 *      non-interactive run has no TTY to approve writes). With `mode: 'write'`
 *      Hermes answers but never mutates. The workspace is pinned into the exact
 *      key {@see \App\Services\Ai\Concerns\RunsCliProcesses::workdirForJob()}
 *      reads, so Hermes runs IN the governed workspace.
 *
 * Consumed by the Dev pipeline
 * ({@see \App\Http\Controllers\AtlasDev\Support\PipelineRunExecutor::executeHermesProvider()}),
 * the Forge driver
 * ({@see AtlasForgeHermesCliInvocationDriver::invoke()}) and the Dev provider-lock
 * resolver ({@see AtlasDev\Pipeline\SpecComposer::resolveModelFamily()}).
 */
final class HermesWorkspaceDefaults
{
    /**
     * The Hermes self-select model sentinel. Robust without a bound config
     * container (returns the fallback), so it is safe to call from pure unit code.
     */
    public static function model(): string
    {
        try {
            $configured = function_exists('config')
                ? config('atlas.ai.providers.hermes_cli.model', 'hermes_cli_default')
                : null;
        } catch (\Throwable) {
            $configured = null;
        }

        return is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : 'hermes_cli_default';
    }

    /**
     * tool_permissions payload that makes Hermes edit $workspace autonomously.
     *
     * @return array{workspace: string, mode: string}
     */
    public static function toolPermissions(string $workspace): array
    {
        return [
            'workspace' => $workspace,
            'mode' => 'danger',
        ];
    }
}
