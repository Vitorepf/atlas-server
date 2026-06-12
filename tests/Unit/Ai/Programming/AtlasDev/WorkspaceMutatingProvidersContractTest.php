<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev;

use App\Services\Ai\Programming\AtlasDev\WorkspaceMutatingProviders;
use PHPUnit\Framework\TestCase;

/**
 * O-6 (S50 — unificar execução): the "who mutates the workspace" membership must have
 * exactly ONE source of truth. The historical bug: a parallel hardcoded list told
 * codex/minimax/hermes "return a diff, never edit" while the pipeline expected them to
 * have edited (empty workspaceDiff → no_patch_needed → silent failure). This contract
 * freezes the single-source invariant so the stacks can never re-fragment.
 */
final class WorkspaceMutatingProvidersContractTest extends TestCase
{
    public function test_membership_oracle_classifies_mutating_and_non_mutating(): void
    {
        foreach (['codex_cli', 'cursor_cli', 'minimax_m27_cli', 'hermes_cli'] as $p) {
            $this->assertTrue(WorkspaceMutatingProviders::includes($p), "{$p} mutates the workspace");
            $this->assertTrue(WorkspaceMutatingProviders::includes(strtoupper($p)), 'case-insensitive');
        }
        // claude_cli returns a diff Atlas applies — it must NEVER be classified as mutating.
        $this->assertFalse(WorkspaceMutatingProviders::includes('claude_cli'));
        $this->assertFalse(WorkspaceMutatingProviders::includes('unknown_provider'));
        $this->assertFalse(WorkspaceMutatingProviders::includes(''));
    }

    public function test_both_consumers_reference_the_single_source_no_parallel_list(): void
    {
        $consumers = [
            'app/Http/Controllers/AtlasDev/Support/PipelineRunExecutor.php',
            'app/Services/Ai/Programming/AtlasDev/PromptProjection/ProviderPromptBuilder.php',
        ];

        foreach ($consumers as $rel) {
            $src = (string) file_get_contents(\dirname(__DIR__, 5).'/'.$rel);
            $this->assertStringContainsString(
                'WorkspaceMutatingProviders',
                $src,
                "{$rel} must derive mutating-provider membership from the single source, not a local list"
            );
        }
    }
}
