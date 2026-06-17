<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * The TASK GENERATOR — the Ladder Sources→Ideas→Hypotheses stage, automated.
 *
 * This is what lets the loop FEED ITSELF instead of waiting for a human to hand
 * each task. For a real target file it asks the provider (through the same
 * provider-agnostic {@see LoopExecutionDriver}) to find ONE genuine, verifiable
 * improvement and write a FROZEN acceptance test that asserts the better behavior.
 *
 * The honest guard: a generated task is kept ONLY if its test genuinely FAILS
 * (is RED) against the current code. A test that is already green describes no
 * real work and is discarded. This is what keeps the self-populated queue honest
 * — the same discipline as the autoresearch metric (a real, falsifiable target),
 * applied to task GENERATION, not just task grinding.
 *
 * The generated frozen test is written INTO the task's base_workspace, so the
 * grind ({@see AtlasEvolutionScenarioExplorer}) inherits it as a frozen path the
 * loop cannot edit.
 */
final class AtlasEvolutionTaskGenerator
{
    public const SCHEMA = 'atlas.evolution.task_generation.v1';

    public function __construct(
        private readonly LoopExecutionDriver $driver,
        private readonly AtlasLoopRedReasonGate $redReasonGate = new AtlasLoopRedReasonGate,
    ) {}

    /**
     * Generate a verified metric-shaped task for a target file inside a prepared,
     * scoped base workspace (the target + a tests/ dir + composer.json). Returns
     * the task on success, or a rejection reason.
     *
     * @param  array{provider?: ?string, index?: int}  $options
     * @return array<string,mixed>  { generated: bool, task?: array, reason: string, ... }
     */
    public function generateForTarget(string $baseWorkspace, string $targetRelativePath, array $options = []): array
    {
        $index = (int) ($options['index'] ?? 0);
        $provider = trim((string) ($options['provider'] ?? config('atlas.loop.default_provider', '')));
        $testRel = 'tests/atlas_generated_'.$index.'.php';
        $objRel = 'GENERATED_OBJECTIVE_'.$index.'.txt';

        $base = realpath($baseWorkspace);
        $target = $base === false ? false : realpath($base.'/'.$targetRelativePath);
        if ($base === false || $target === false || ! is_file($target) || ! str_starts_with($target, $base.'/')) {
            return ['generated' => false, 'reason' => 'invalid_base_or_target'];
        }

        $intent = $this->generationIntent($targetRelativePath, $testRel, $objRel);
        $surfaceHints = ['composer_mode' => 'programming', 'composer_task' => 'review', 'thread_id' => 'atlas-evolution-taskgen'];
        if ($provider !== '') {
            $surfaceHints['provider_choice'] = $provider;
        }

        try {
            $this->driver->attempt(
                'atlas_evolution_taskgen',
                $baseWorkspace,
                $intent,
                ['allowed_files='.$testRel.','.$objRel],
                $surfaceHints,
            );
        } catch (Throwable $e) {
            return ['generated' => false, 'reason' => 'generation_threw: '.mb_substr($e->getMessage(), 0, 160)];
        }

        $base = rtrim($baseWorkspace, '/');
        $objective = is_file($base.'/'.$objRel) ? trim((string) file_get_contents($base.'/'.$objRel)) : '';
        if (! is_file($base.'/'.$testRel) || $objective === '') {
            return ['generated' => false, 'reason' => 'provider_did_not_emit_test_or_objective'];
        }

        // HONEST GUARD: the generated test must genuinely FAIL on the current code,
        // otherwise it describes no real work (a trivial/already-satisfied target).
        if (! $this->isRed($base, $testRel)) {
            return ['generated' => false, 'reason' => 'generated_test_is_not_red (no real work / fabricated target)', 'objective' => $objective];
        }

        // ACDE U2 — when armed, additionally prove the RED is BEHAVIORAL (the test pins the claimed improvement),
        // not a STRUCTURAL defect (a non-parsing test or a wrong require path) that merely exits non-zero. Default
        // OFF => the gate never runs => byte-identical (the any-non-zero isRed remains the sole guard). The flag
        // read is fail-safe: with no Laravel container (pure unit context) it resolves OFF, never throwing.
        $redReasonGateEnabled = false;
        try {
            $redReasonGateEnabled = (bool) config('atlas.loop.red_reason_gate_enabled', false);
        } catch (Throwable) {
            $redReasonGateEnabled = false;
        }
        if ($redReasonGateEnabled) {
            $redReason = $this->redReasonGate->evaluate($base, $testRel, $targetRelativePath);
            if (! $redReason['is_red']) {
                return ['generated' => false, 'reason' => 'red_reason_rejected: '.$redReason['reason'], 'objective' => $objective];
            }
        }

        return [
            'generated' => true,
            'reason' => 'verified_red_task',
            'task' => [
                'objective' => $objective,
                'base_workspace' => $base,
                'provider' => $provider,
                'allowed_files' => [$targetRelativePath],
                'validation_commands' => ['php '.$testRel],
                'acceptance' => [
                    'commands' => ['php '.$testRel],
                    'allowed_globs' => [dirname($targetRelativePath).'/**', $targetRelativePath],
                    'frozen_globs' => ['tests/**', $objRel, 'composer.json'],
                    'metric_kind' => AtlasEvolutionFrozenJudge::METRIC_GATE,
                ],
            ],
        ];
    }

    private function generationIntent(string $target, string $testRel, string $objRel): string
    {
        return implode("\n", [
            "You are seeding an autonomous improvement task for the file `{$target}` in this workspace.",
            '',
            "1. Read `{$target}` and find ONE genuine, verifiable improvement: a real bug, a missing edge case, or a concrete behavior gap. NOT a style or naming nitpick.",
            "2. Write a SELF-CONTAINED PHP test at `{$testRel}` that asserts the IMPROVED behavior. It must run with plain `php {$testRel}` (no framework boot), `require` the target via a relative path, exit 0 only when the improved behavior holds, and — critically — it MUST currently FAIL (exit non-zero) against the UNCHANGED `{$target}` (a real RED test). It must also assert that existing behavior is preserved.",
            "3. Write the one-line improvement objective (imperative, specific) to `{$objRel}`.",
            '',
            "Edit ONLY `{$testRel}` and `{$objRel}`. Do NOT modify `{$target}`.",
        ]);
    }

    private function isRed(string $base, string $testRel): bool
    {
        $process = Process::fromShellCommandline('php '.escapeshellarg($testRel), $base, null, null, 120.0);
        $process->run();

        return ($process->getExitCode() ?? 1) !== 0; // RED = non-zero
    }
}
