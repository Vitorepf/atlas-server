<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopIdeaDraftingRubric;
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
        private ?\App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopExternalResearchService $research = null,
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
        [$provider, $testRel, $objRel] = $this->taskGenerationInputs($options);

        return $this->generateForResolvedTarget(
            $baseWorkspace,
            $targetRelativePath,
            $options,
            $provider,
            $testRel,
            $objRel,
            $this->resolveTargetBase($baseWorkspace, $targetRelativePath),
        );
    }

    /**
     * @param  array{provider?: ?string, index?: int}  $options
     * @return array{0: string, 1: string, 2: string}
     */
    private function taskGenerationInputs(array $options): array
    {
        $index = (int) ($options['index'] ?? 0);
        $provider = trim((string) ($options['provider'] ?? config('atlas.loop.default_provider', '')));

        return [$provider, 'tests/atlas_generated_'.$index.'.php', 'GENERATED_OBJECTIVE_'.$index.'.txt'];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function generateForResolvedTarget(string $baseWorkspace, string $targetRelativePath, array $options, string $provider, string $testRel, string $objRel, string|false $base): array
    {
        if ($base === false) {
            return ['generated' => false, 'reason' => 'invalid_base_or_target'];
        }

        $intent = $this->generationIntent($targetRelativePath, $testRel, $objRel, $this->advisoryContext($options));
        $surfaceHints = $this->taskGenerationSurfaceHints($provider);

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
        $objective = $this->generatedObjective($base, $objRel);
        if (! is_file($base.'/'.$testRel) || $objective === '') {
            return ['generated' => false, 'reason' => 'provider_did_not_emit_test_or_objective'];
        }

        // HONEST GUARD: the generated test must genuinely FAIL on the current code,
        // otherwise it describes no real work (a trivial/already-satisfied target).
        if (! $this->isRed($base, $testRel)) {
            return ['generated' => false, 'reason' => 'generated_test_is_not_red (no real work / fabricated target)', 'objective' => $objective];
        }

        return $this->verifiedTask($base, $targetRelativePath, $provider, $testRel, $objRel, $objective);
    }

    private function resolveTargetBase(string $baseWorkspace, string $targetRelativePath): string|false
    {
        $base = realpath($baseWorkspace);
        $target = realpath($baseWorkspace.'/'.$targetRelativePath);
        if ($base === false || $target === false || ! is_file($target) || ! str_starts_with($target, $base.'/')) {
            return false;
        }

        return $base;
    }

    /**
     * @return array<string,string>
     */
    private function taskGenerationSurfaceHints(string $provider): array
    {
        return array_filter([
            'composer_mode' => 'programming',
            'composer_task' => 'review',
            'thread_id' => 'atlas-evolution-taskgen',
            'provider_choice' => $provider,
        ], static fn (string $value): bool => $value !== '');
    }

    private function generatedObjective(string $base, string $objRel): string
    {
        return is_file($base.'/'.$objRel) ? trim((string) file_get_contents($base.'/'.$objRel)) : '';
    }

    /**
     * ACDE U2 — when armed, additionally prove the RED is BEHAVIORAL (the test pins the claimed improvement),
     * not a STRUCTURAL defect (a non-parsing test or a wrong require path) that merely exits non-zero. Default
     * OFF => the gate never runs => byte-identical (the any-non-zero isRed remains the sole guard). The flag
     * read is fail-safe: with no Laravel container (pure unit context) it resolves OFF, never throwing.
     *
     * @return array<string,mixed>
     */
    private function verifiedTask(string $base, string $targetRelativePath, string $provider, string $testRel, string $objRel, string $objective): array
    {
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

    /**
     * ACDE U1 — N-sampled comprehension. A single open-ended generation pass commits to ONE reading of the file
     * with no second chance, so a weak engine that misreads (or emits a structural-red the U2 gate rejects)
     * yields no task at all. U1 draws up to `comprehension_samples` independent readings and keeps the FIRST
     * that produces a genuine verified-RED task (when U2 is armed, "genuine" already means behavioral, so first-
     * success is the deterministic selector). Width over a weak engine raises the yield of HONEST tasks without
     * ever lowering the bar. Default samples=1 => one pass => byte-identical to generateForTarget.
     *
     * @param  array{provider?: ?string, index?: int}  $options
     * @return array<string,mixed>
     */
    public function generateBestForTarget(string $baseWorkspace, string $targetRelativePath, array $options = []): array
    {
        $samples = max(1, (int) config('atlas.loop.comprehension_samples', 1));
        if ($samples === 1) {
            return $this->generateForTarget($baseWorkspace, $targetRelativePath, $options);
        }

        // ACDE U3 — sample-N objective DIVERGENCE. When armed, keep sampling all K readings (instead of
        // early-returning the first RED) so the SPREAD of the K proposed objectives can be measured; a wildly
        // divergent spread means the file is genuinely ambiguous and the loop ASKS the operator rather than
        // committing to one arbitrary reading. Default OFF => first-RED early-return is preserved => byte-identical.
        $divergenceEnabled = (bool) config('atlas.loop.objective_divergence_enabled', false);

        $baseIndex = max(0, (int) ($options['index'] ?? 0));
        $last = ['generated' => false, 'reason' => 'comprehension_no_red_in_samples'];
        $firstRed = null;   // U3: the first genuine RED, remembered while the remaining readings are sampled
        $objectives = [];   // U3: every reading's proposed objective text (the ambiguity signal)
        for ($k = 0; $k < $samples; $k++) {
            $opt = $options;
            $opt['index'] = $baseIndex * $samples + $k; // distinct frozen test file per independent reading
            $candidate = $this->generateForTarget($baseWorkspace, $targetRelativePath, $opt);
            $objective = $this->objectiveOf($candidate);
            if ($objective !== '') {
                $objectives[] = $objective;
            }
            if (($candidate['generated'] ?? false) === true) {
                $candidate['comprehension_sample'] = $k;
                $candidate['comprehension_samples'] = $samples;
                if (! $divergenceEnabled) {
                    return $candidate; // first genuine (U2-behavioral when armed) RED wins — deterministic
                }
                $firstRed ??= $candidate; // U3 armed: remember it, keep sampling for the divergence measure
            }
            $last = $candidate;
        }

        return $this->finishBestGeneration($divergenceEnabled, $objectives, $firstRed, $last, $samples);
    }

    /**
     * @param  list<string>  $objectives
     * @param  array<string,mixed>|null  $firstRed
     * @param  array<string,mixed>  $last
     * @return array<string,mixed>
     */
    private function finishBestGeneration(bool $divergenceEnabled, array $objectives, ?array $firstRed, array $last, int $samples): array
    {
        if ($divergenceEnabled) {
            $divergence = new AtlasLoopObjectiveDivergence;
            $threshold = (float) config('atlas.loop.objective_divergence_threshold', 0.85);
            if (count($objectives) >= 2 && $divergence->diverges($objectives, $threshold)) {
                return [
                    'generated' => false,
                    'reason' => 'objective_divergence_ambiguous',
                    'objective_divergence' => $divergence->meanPairwiseJaccardDistance($objectives),
                    'comprehension_samples' => $samples,
                ];
            }
            if ($firstRed !== null) {
                // ARBOR-GRAFT TIER 0.1 — expose the K competing readings so the refiller can materialize them
                // as sibling hypothesis nodes (the tree-producer). Present ONLY on the divergence path (>=2
                // readings sampled); absent everywhere else => byte-identical.
                if (count($objectives) >= 2) {
                    $firstRed['sampled_objectives'] = array_values($objectives);
                }

                return $firstRed; // the readings AGREE enough => the first genuine RED stands
            }
        }

        return $last; // no sample produced a genuine RED => the last rejection (honest, no fabricated task)
    }

    /**
     * The proposed objective text on a generateForTarget result, present on BOTH a success (task.objective)
     * and a rejection (objective). '' when none. Used by U3 to gather the K readings' objectives.
     *
     * @param  array<string,mixed>  $candidate
     */
    private function objectiveOf(array $candidate): string
    {
        $objective = $candidate['task']['objective'] ?? $candidate['objective'] ?? null;

        return is_string($objective) ? trim($objective) : '';
    }

    private function generationIntent(string $target, string $testRel, string $objRel, string $advisoryContext = ''): string
    {
        $lines = [
            "You are seeding an autonomous improvement task for the file `{$target}` in this workspace.",
            '',
            "1. Read `{$target}` and find ONE genuine, verifiable improvement: a real bug, a missing edge case, or a concrete behavior gap. NOT a style or naming nitpick.",
            "2. Write a SELF-CONTAINED PHP test at `{$testRel}` that asserts the IMPROVED behavior. It must run with plain `php {$testRel}` (no framework boot), `require` the target via a relative path, exit 0 only when the improved behavior holds, and — critically — it MUST currently FAIL (exit non-zero) against the UNCHANGED `{$target}` (a real RED test). It must also assert that existing behavior is preserved.",
            "3. Write the one-line improvement objective (imperative, specific) to `{$objRel}`.",
            '',
            "Edit ONLY `{$testRel}` and `{$objRel}`. Do NOT modify `{$target}`.",
        ];

        // ARBOR-GRAFT W1 — advisory ideation context (constraints-block + idea-drafting rubric) appended
        // below the task. It GUIDES the next draft only; it never changes the task instructions or the
        // frozen gate. Empty when both flags are OFF => byte-identical prompt.
        if (trim($advisoryContext) !== '') {
            $lines[] = '';
            $lines[] = '--- ADVISORY CONTEXT (guidance for choosing a strong improvement; does NOT change the task above) ---';
            $lines[] = trim($advisoryContext);
        }

        return implode("\n", $lines);
    }

    /**
     * ARBOR-GRAFT W1 — assemble the advisory ideation context for the generation prompt. Both layers are
     * flag-gated default-OFF and fail-safe (no container => OFF, never throws), so the default is empty =>
     * byte-identical generation. The constraints-block is supplied by the caller (the refiller, which has the
     * campaign) under $options['constraints_block']; the idea-drafting rubric is static.
     *
     * @param  array<string,mixed>  $options
     */
    private function advisoryContext(array $options): string
    {
        $parts = [];
        try {
            if ((bool) config('atlas.loop.idea_drafting_rubric_enabled', false)) {
                $parts[] = AtlasLoopIdeaDraftingRubric::text();
            }
        } catch (Throwable) {
            // fail-safe: OFF
        }
        try {
            if ((bool) config('atlas.loop.constraints_block_enabled', false)) {
                $block = trim((string) ($options['constraints_block'] ?? ''));
                if ($block !== '') {
                    $parts[] = $block;
                }
            }
        } catch (Throwable) {
            // fail-safe: OFF
        }

        return implode("\n\n", $this->withExternalResearchContext($parts, $options));
    }

    /**
     * @param  array<int,string>  $parts
     * @param  array<string,mixed>  $options
     * @return array<int,string>
     */
    private function withExternalResearchContext(array $parts, array $options): array
    {
        try {
            if ((bool) config('atlas.loop.external_research_enabled', true)) {
                $topic = trim((string) ($options['research_topic'] ?? ''));
                $repoRoot = (string) ($options['repo_root'] ?? '');
                if ($topic !== '' && $repoRoot !== '') {
                    $res = ($this->research ??= new \App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopExternalResearchService(searchToolAvailable: (bool) config('atlas.loop.external_research_tool_available', false)))->research($topic, $repoRoot);
                    if (($res['researched'] ?? false) === true && ($res['note'] ?? null) !== null) {
                        $parts[] = "EXTERNAL RESEARCH (advisory, sovereignty-filtered; guidance only, does NOT certify):\n".$res['note'];
                    }
                }
            }
        } catch (\Throwable) { /* fail-safe: advisory OFF on any error */ }

        return $parts;
    }

    private function isRed(string $base, string $testRel): bool
    {
        $process = Process::fromShellCommandline('php '.escapeshellarg($testRel), $base, null, null, 120.0);
        $process->run();

        return ($process->getExitCode() ?? 1) !== 0; // RED = non-zero
    }
}
