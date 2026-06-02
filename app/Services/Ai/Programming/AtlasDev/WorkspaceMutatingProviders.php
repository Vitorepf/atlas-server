<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev;

/**
 * Canonical, single-source list of Atlas Dev providers that edit the worktree
 * DIRECTLY (in-place workspace mutation), as opposed to returning a text diff
 * that Atlas applies out-of-provider.
 *
 * For a mutating provider Atlas: (1) instructs the model — via the prompt
 * contract — to edit allowed_files in place and NOT to emit a diff or wait for
 * write permission; and (2) captures the git diff AFTER execution, never
 * applying a returned patch.
 *
 * This list MUST be the only place the membership is defined. It is consumed by
 * BOTH:
 *   - {@see PromptProjection\ProviderPromptBuilder::adaptSectionsForProvider()}
 *     (the prompt contract the provider receives), and
 *   - {@see \App\Http\Controllers\AtlasDev\Support\PipelineRunExecutor::providerMutatedWorkspace()}
 *     (whether the pipeline reads workspaceDiff instead of applying a patch).
 *
 * Keeping a single source guarantees the prompt the provider is told and the way
 * Atlas reads the result can never diverge — the divergence that previously made
 * codex/minimax/hermes be told "return diff text, never edit" while the pipeline
 * expected them to have edited (empty workspaceDiff → no_patch_needed → failure).
 *
 * `claude_cli` is intentionally ABSENT: the Claude gateway returns a text diff
 * which Atlas applies; it never mutates the worktree itself.
 */
final class WorkspaceMutatingProviders
{
    /**
     * Canonical provider keys (as used by config('atlas.ai.providers.*') and
     * AiProviderManager) that mutate the workspace directly.
     *
     * @var list<string>
     */
    public const PROVIDERS = [
        'codex_cli',
        'cursor_cli',
        'minimax_m27_cli',
        'hermes_cli',
    ];

    /**
     * True when the given provider edits the worktree directly (and therefore
     * must receive the in-place mutation prompt contract and have its result
     * read from the post-execution git diff).
     */
    public static function includes(string $provider): bool
    {
        return in_array(strtolower(trim($provider)), self::PROVIDERS, true);
    }
}
