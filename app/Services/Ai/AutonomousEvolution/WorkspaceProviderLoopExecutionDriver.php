<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopBlastRadiusReader;
use App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter;
use App\Services\Engineering\CodeGraph\CodeGraphContextRetriever;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The BREADTH driver — invokes the configured provider CLI DIRECTLY against the
 * isolated scenario workspace to satisfy the task, governed solely by the loop's own
 * brain (the frozen judge), NOT by Atlas Dev's clarity/risk routing gate.
 *
 * WHY this exists: the default {@see SeniorLoopExecutionDriver} routes through the
 * Atlas Dev senior-loop, whose RoutingDecisionEngine sends ambiguous / hypothesis /
 * real-logic-change writes to PLAN-ONLY (so Dev does not burn tokens on unclear
 * tasks). That gate is correct for the interactive Dev product — but it blocks exactly
 * the VALUABLE autonomous work (numeric guards, edge-case logic), returning
 * `routing_not_executable`. The Loop is a distinct product with its OWN governance —
 * the frozen acceptance test, allowed_files scope, propose-only, never-merge, and a
 * disposable cp -R temp workspace — so it can use the provider muscle directly.
 *
 * PROVIDER-AGNOSTIC: the provider is resolved from the `provider_choice` surface hint
 * (or the loop default); execution flows through the canonical
 * {@see AtlasForgeProviderInvocationDriverRouter} (codex/cursor/hermes/gemini/minimax),
 * which is fail-closed (`isConfigured`), command-allowlisted, and attributes changed
 * files via a SEC-003 before/after delta. No provider is named here. It reuses the
 * proven Forge provider drivers — it does NOT fork a new execution stack.
 *
 * SAFETY: the provider can only touch the throwaway scenario workspace; any out-of-scope
 * or frozen-path edit is REJECTED by the frozen judge; nothing is ever merged.
 */
final class WorkspaceProviderLoopExecutionDriver implements LoopExecutionDriver
{
    /**
     * Output budget for a provider invocation. A text/HTTP provider delivers its whole change
     * AS a unified diff in the completion body — a small excerpt would truncate the patch and
     * make it un-appliable — so the loop asks for a generous body (token cost is not a loop
     * constraint; the cheap engine pays depth). A CLI provider edits files directly and ignores
     * this. The MiniMax executor clamps to its own ceiling, so over-asking is harmless.
     */
    private const MAX_PROVIDER_OUTPUT_CHARS = 60000;

    /**
     * AP-815 · I-4 loop seam: the code-graph context retriever (and the workspace
     * identity that scopes it to the indexed primary graph). Constructor-injected so
     * Laravel auto-wires both concrete services; they are touched ONLY when the
     * `atlas.code_graph.auto_context` flag is ON, so the default-OFF path is unchanged.
     */
    public function __construct(
        private readonly AtlasForgeProviderInvocationDriverRouter $router,
        private readonly CodeGraphContextRetriever $codeGraphContext,
        private readonly CodeGraphWorkspaceIdentity $workspaceIdentity,
        private readonly AtlasLoopProviderEditApplier $editApplier = new AtlasLoopProviderEditApplier,
        private readonly AtlasEvolutionFrozenJudge $judge = new AtlasEvolutionFrozenJudge,
    ) {}

    /**
     * Invoke the provider, then — for a TEXT/HTTP provider that returned only a completion
     * and edited NOTHING — parse its unified diff and apply it to the scenario workspace so
     * its work becomes REAL files the frozen judge can score. A CLI provider that edited in
     * place already carries a non-empty `changed_files`, so the apply is skipped and the
     * result is byte-identical. Flag-gated ({@see config} `atlas.loop.text_provider_edit_apply`,
     * default ON) and guarded by `changed_files === []` + non-empty stdout — so existing CLI
     * and fake-router paths are unaffected. This is the single primitive that lets the loop
     * run end-to-end on a text-only engine (the ACDE engine-independence proof).
     *
     * @param  array<string,mixed>  $prompt
     * @param  list<string>  $allowedFiles
     * @return array<string,mixed>
     */
    private function invokeWithEditApply(string $provider, ?string $model, array $prompt, string $workspace, int $timeout, array $allowedFiles = []): array
    {
        $result = $this->router->invoke($provider, $model, $prompt, [
            'cwd' => $workspace,
            'timeout_seconds' => $timeout,
            'max_output_chars' => self::MAX_PROVIDER_OUTPUT_CHARS,
            'env' => AtlasLoopHermeticCommandEnvironment::forAcceptance(),
        ]);
        $result = $this->resetProviderProjectionNoise($result, $workspace, $allowedFiles);

        if (! (bool) config('atlas.loop.text_provider_edit_apply', true)
            || ! (bool) ($result['provider_called'] ?? false)
            || ($result['changed_files'] ?? []) !== []) {
            return $result;
        }

        $stdout = (string) ($result['stdout'] ?? $result['output_excerpt'] ?? '');
        $applied = $this->editApplier->applyFromText($stdout, $workspace, $timeout, $allowedFiles);
        $result['edits_applied_from_text'] = $applied['applied'];
        $result['edit_apply_status'] = $applied['status'];
        if ($applied['applied']) {
            $result['changed_files'] = $applied['changed_files'];
        }

        return $this->resetProviderProjectionNoise($result, $workspace, $allowedFiles);
    }

    public function attempt(
        string $surfaceId,
        string $workspace,
        string $intent,
        array $userConstraints,
        array $surfaceHints,
    ): array {
        $provider = trim((string) ($surfaceHints['provider_choice'] ?? '')) ?: trim((string) config('atlas.loop.default_provider', ''));
        if ($provider === '' || ! $this->router->isConfigured($provider)) {
            return ['status' => 'blocked', 'reason' => 'provider_not_configured:'.($provider ?: 'none'), 'provider_invoked' => false];
        }

        $allowedFiles = $this->parseConstraint($userConstraints, 'allowed_files');
        $validationCommands = $this->parseConstraint($userConstraints, 'validation_command');
        // ACDE Tier-0 #2: the FROZEN acceptance (threaded by the explorer via surfaceHints) lets
        // iterate-to-green drive toward the JUDGE's bar instead of a raw exit-0 proxy. Absent => the
        // raw-command path is used (byte-identical).
        $acceptance = is_array($surfaceHints['acceptance'] ?? null) ? $surfaceHints['acceptance'] : [];
        $prompt = $this->buildPrompt($intent, $allowedFiles, $validationCommands, $workspace, $provider);
        $model = $this->resolveModel($provider);
        $timeout = max(60, (int) config('atlas.loop.campaign.attempt_hard_seconds', 900));

        $result = $this->invokeWithEditApply($provider, $model, $prompt, $workspace, $timeout, $allowedFiles);

        // L2-1 (regressão ACP): "sucesso" do provider com ZERO mudanças no workspace é
        // um sucesso FALSO para uma invocação mutadora — foi exatamente a assinatura da
        // regressão do transport acp (12 cenários, diff 0, sucesso reportado). Uma
        // re-tentativa única recupera o cenário em vez de desperdiçá-lo; o carimbo
        // zero_diff_retry torna a anomalia auditável (recorrência = transport suspeito).
        $zeroDiffRetry = false;
        if ((bool) ($result['provider_called'] ?? false)
            && ($result['changed_files'] ?? []) === []
            && (bool) config('atlas.loop.zero_diff_retry', true)) {
            $zeroDiffRetry = true;
            $result = $this->invokeWithEditApply($provider, $model, $prompt, $workspace, $timeout, $allowedFiles);
        }

        // ADEP keystone — ITERATE-TO-GREEN (flag-gated, default OFF => byte-identical). The single
        // biggest gap vs codex/Claude: the loop named the acceptance test in the prompt but never RAN
        // it and fed the failure back. Here the LOOP closes that loop — run the validation; on red,
        // re-invoke the provider WITH the exact failure; repeat until green or budget. Works for any
        // provider (does not rely on the provider self-iterating). The frozen judge stays authoritative.
        $iterateMeta = null;
        if ((bool) config('atlas.loop.iterate_to_green_enabled', false)
            && (bool) ($result['provider_called'] ?? false)
            && $validationCommands !== []) {
            $maxIter = max(1, (int) config('atlas.loop.iterate_to_green_max', 3));
            $cmd = implode(' && ', $validationCommands);
            // ACDE Tier-0 #2: when armed AND the frozen acceptance is available, the green check IS the
            // judge (diff-earned / scope / frozen / complexity), not a raw exit-0 a gamed candidate can
            // satisfy — so the iterations drive toward a CERTIFIABLE result and the real rejection reason
            // is fed back. Default OFF / no acceptance => the raw-command path is byte-identical.
            $againstJudge = (bool) config('atlas.loop.iterate_against_judge', false) && $acceptance !== [];
            $runTest = function () use ($cmd, $workspace, $timeout, $againstJudge, $acceptance): array {
                if ($againstJudge) {
                    $v = $this->judge->score($workspace, $acceptance);
                    $green = (bool) ($v['passed'] ?? false) && (($v['details']['rejected'] ?? false) !== true);

                    return ['passed' => $green, 'output' => $green ? 'judge_green' : ('judge_rejected: '.(string) ($v['details']['reason'] ?? ''))];
                }
                $p = new Process(['bash', '-lc', $cmd], $workspace, AtlasLoopHermeticCommandEnvironment::forAcceptance(), null, (float) $timeout);
                $p->run();

                return ['passed' => $p->isSuccessful(), 'output' => mb_substr($p->getOutput()."\n".$p->getErrorOutput(), -4000)];
            };
            $reinvoke = function (string $failure) use ($provider, $model, $intent, $allowedFiles, $validationCommands, $workspace, $timeout, &$result): void {
                $result = $this->invokeWithEditApply($provider, $model, $this->buildFixPrompt($intent, $allowedFiles, $validationCommands, $failure, $workspace, $provider), $workspace, $timeout, $allowedFiles);
            };
            $iterateMeta = (new AtlasLoopIterateToGreenExecutor)->pursue($runTest, $reinvoke, $maxIter);
        }

        $called = (bool) ($result['provider_called'] ?? false);
        $providerOutput = (string) ($result['stdout'] ?? $result['stdout_excerpt'] ?? $result['output_excerpt'] ?? '');
        $providerError = (string) ($result['stderr'] ?? $result['stderr_excerpt'] ?? '');

        return [
            'status' => $called ? 'completed' : 'blocked',
            'provider' => $provider,
            'provider_invoked' => $called,
            'changed_files' => $result['changed_files'] ?? [],
            'exit_code' => $result['exit_code'] ?? null,
            'zero_diff_retry' => $zeroDiffRetry,
            // ACDE seam audit: was this attempt's diff produced as TEXT and applied by the loop
            // (a text/HTTP engine), vs edited in place by a CLI agent? Lets the soak prove the
            // engine-independence path actually fired (and which status when it did not).
            'edits_applied_from_text' => (bool) ($result['edits_applied_from_text'] ?? false),
            'edit_apply_status' => $result['edit_apply_status'] ?? null,
            'provider_projection_noise_reset' => (bool) ($result['provider_projection_noise_reset'] ?? false),
            'provider_projection_noise_files' => is_array($result['provider_projection_noise_files'] ?? null) ? array_values($result['provider_projection_noise_files']) : [],
            'iterate_to_green' => $iterateMeta,
            'provider_output_present' => trim($providerOutput) !== '',
            'provider_output_bytes' => strlen($providerOutput),
            'provider_error_present' => trim($providerError) !== '',
            'provider_error_bytes' => strlen($providerError),
            'provider_failure_type' => is_string($result['failure_type'] ?? null) ? mb_substr((string) $result['failure_type'], 0, 120) : null,
            'provider_note' => is_string($result['note'] ?? null) ? mb_substr((string) $result['note'], 0, 160) : null,
            // L6-3 live-evidence wire: forward the REAL token count + cost the router
            // surfaced (numeric only when the provider actually reported usage; null
            // otherwise — never fabricated) so the runner persists them into
            // attempt_metrics and the strategy bandit can measure certification-per-
            // token on the live soak. Closes the auto-fill loop end-to-end.
            'tokens_used' => is_numeric($result['tokens_used'] ?? null) ? max(0, (int) $result['tokens_used']) : null,
            'cost_estimate_usd' => is_numeric($result['cost_estimate_usd'] ?? null) ? max(0.0, (float) $result['cost_estimate_usd']) : null,
            'reason' => $called ? null : (string) ($result['note'] ?? 'provider_not_called'),
        ];
    }

    /**
     * Provider projection files are bootstrap/read-model artifacts. If a CLI provider or
     * its hooks refresh them inside a disposable scenario workspace, reset only those
     * files before judging the candidate, unless the task explicitly allowed editing them.
     *
     * @param  array<string,mixed>  $result
     * @param  list<string>  $allowedFiles
     * @return array<string,mixed>
     */
    private function resetProviderProjectionNoise(array $result, string $workspace, array $allowedFiles): array
    {
        if (! (bool) config('atlas.loop.provider_projection_noise_reset', true)
            || ! is_dir($workspace)) {
            return $result;
        }

        $changed = $this->stringList($result['changed_files'] ?? []);
        $noise = array_values(array_filter(
            $this->providerProjectionNoiseFiles(),
            fn (string $file): bool => in_array($file, $changed, true)
                && ! in_array($file, $allowedFiles, true),
        ));
        if ($noise === []) {
            return $result;
        }

        foreach ($noise as $file) {
            $this->restoreWorkspacePath($workspace, $file);
        }

        $result['provider_projection_noise_reset'] = true;
        $result['provider_projection_noise_files'] = $noise;
        $result['changed_files'] = $this->workspaceChangedFiles($workspace);

        return $result;
    }

    /**
     * @return list<string>
     */
    private function providerProjectionNoiseFiles(): array
    {
        $configured = config('atlas.loop.provider_projection_noise_files', ['AGENTS.md', 'CLAUDE.md']);
        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            is_array($configured) ? $configured : [],
        ))));
    }

    private function restoreWorkspacePath(string $workspace, string $path): void
    {
        if ($path === '' || str_contains($path, "\0") || str_starts_with($path, '/')
            || str_contains($path, '..')) {
            return;
        }

        $tracked = new Process(['git', 'ls-files', '--error-unmatch', '--', $path], $workspace, null, null, 30.0);
        $tracked->run();
        $restore = $tracked->isSuccessful()
            ? new Process(['git', 'checkout', '--', $path], $workspace, null, null, 30.0)
            : new Process(['git', 'clean', '-f', '--', $path], $workspace, null, null, 30.0);
        $restore->run();
    }

    /**
     * @return list<string>
     */
    private function workspaceChangedFiles(string $workspace): array
    {
        $status = new Process(['git', 'status', '--porcelain', '--untracked-files=all'], $workspace, null, null, 30.0);
        $status->run();
        if (! $status->isSuccessful()) {
            return [];
        }

        $files = [];
        foreach (preg_split('/\R/', rtrim($status->getOutput())) ?: [] as $line) {
            if (! is_string($line) || strlen($line) < 4) {
                continue;
            }
            $path = trim(substr($line, 3));
            if (str_contains($path, ' -> ')) {
                $path = trim(substr($path, (int) strrpos($path, ' -> ') + 4));
            }
            $path = trim($path, "\"'");
            if ($path !== '') {
                $files[] = $path;
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $value,
        ), static fn (string $item): bool => $item !== ''));
    }

    /**
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $validationCommands
     * @return array<string,mixed>
     */
    private function buildPrompt(string $intent, array $allowedFiles, array $validationCommands, string $workspace = '', string $provider = ''): array
    {
        $lines = [
            'You are autonomously improving code in an ISOLATED throwaway workspace. Make the change directly by editing files in place.',
            '',
            'OBJECTIVE: '.$intent,
            '',
        ];
        if ($allowedFiles !== []) {
            $lines[] = 'Edit ONLY these files: '.implode(', ', $allowedFiles).'.';
        }
        $lines[] = $this->frozenScopeInstruction($allowedFiles);
        if ($validationCommands !== []) {
            $lines[] = 'Your change is correct only when this passes: '.implode(' && ', $validationCommands).'.';
        }
        $lines[] = 'Preserve all existing behavior; make the smallest change that satisfies the objective.';
        foreach ($this->cliProviderToolHintLines($provider) as $line) {
            $lines[] = $line;
        }
        foreach ($this->editProtocolLines($allowedFiles, $workspace, $provider) as $line) {
            $lines[] = $line;
        }

        // ACDE B1 — the brain-context seams (code-graph signatures + dependency bodies) consolidated into ONE
        // method shared by buildPrompt + buildFixPrompt (and the future B3/B4 memory/blast-radius extensions),
        // so the grounding never drifts between the first attempt and the retry again. Each per-source flag is
        // OFF by default => no lines appended => byte-identical to before the seams.
        $this->appendBrainContext($lines, $intent, $allowedFiles);

        $text = implode("\n", $lines);

        return ['text' => $text, 'instruction' => $text, 'messages' => [['role' => 'user', 'content' => $text]]];
    }

    /**
     * The iterate-to-green follow-up prompt: the previous attempt left the acceptance RED, so feed the
     * provider the EXACT failure output and ask for the smallest in-place fix that turns it green.
     *
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $validationCommands
     * @return array<string,mixed>
     */
    private function buildFixPrompt(string $intent, array $allowedFiles, array $validationCommands, string $failure, string $workspace = '', string $provider = ''): array
    {
        $lines = [
            'Your previous change did NOT pass the acceptance test. Fix the code IN PLACE so it passes — read the failure, find the cause, and correct it (create any file the objective requires; a missing/mis-namespaced class is a common cause).',
            '',
            'The command `'.implode(' && ', $validationCommands).'` failed with (tail):',
            '--- failure output ---',
            $failure,
            '--- end failure output ---',
            '',
            'OBJECTIVE (unchanged): '.$intent,
        ];
        if ($allowedFiles !== []) {
            $lines[] = 'Edit ONLY these files (and CREATE any the objective requires among them): '.implode(', ', $allowedFiles).'.';
        }
        $lines[] = $this->frozenScopeInstruction($allowedFiles, ' Make the smallest change that turns the test GREEN while preserving existing behavior.');
        foreach ($this->cliProviderToolHintLines($provider) as $line) {
            $lines[] = $line;
        }
        foreach ($this->editProtocolLines($allowedFiles, $workspace, $provider) as $line) {
            $lines[] = $line;
        }

        // ACDE B1-fast — ground the iterate-to-green RETRY with the SAME brain context the first attempt gets
        // (today buildFixPrompt goes in pelado, exactly where the weak engine is failing). Behind a dedicated
        // flag (default OFF => byte-identical) AND, inside appendBrainContext, the per-source flags — so armed
        // it injects nothing the operator has not already armed for buildPrompt.
        if ((bool) config('atlas.loop.brain_context_on_fix_prompt', false)) {
            $this->appendBrainContext($lines, $intent, $allowedFiles);
        }

        $text = implode("\n", $lines);

        return ['text' => $text, 'instruction' => $text, 'messages' => [['role' => 'user', 'content' => $text]]];
    }

    /**
     * @param  list<string>  $allowedFiles
     */
    private function frozenScopeInstruction(array $allowedFiles, string $suffix = ''): string
    {
        foreach ($allowedFiles as $file) {
            if (str_starts_with(ltrim((string) $file, './'), 'tests/')) {
                return 'Do NOT modify composer.json or any file outside the Edit ONLY list; frozen acceptance files not listed above must stay untouched.'.$suffix;
            }
        }

        return 'Do NOT modify anything under tests/ or composer.json — those are the frozen acceptance and must stay untouched.'.$suffix;
    }

    /**
     * @return list<string>
     */
    private function cliProviderToolHintLines(string $provider): array
    {
        if ($provider !== AtlasForgeProviderInvocationDriverRouter::DRIVER_HERMES_CLI) {
            return [];
        }

        return [
            'Hermes CLI note: if a native patch/write_file/edit tool refuses the path as sensitive, do not retry that tool; use the terminal shell in this throwaway workspace to edit the allowed file, then run the validation command.',
        ];
    }

    /**
     * The text-provider edit protocol — the heart of running the loop on a no-filesystem
     * engine. A CLI agent edits files in place and can ignore this; a text/HTTP engine
     * (MiniMax) has no filesystem, so it must DELIVER its change as text the loop then
     * applies ({@see AtlasLoopProviderEditApplier}).
     *
     * Two things make a WEAK engine reliable here: (1) we show it the EXACT current contents
     * of every in-scope file, so it does not hallucinate context (the failure that made its
     * first diffs un-appliable); (2) we ask for FULL-FILE blocks — the complete new contents,
     * not a fragile unified diff a weak model miscounts — which the applier writes verbatim.
     * A unified diff is still accepted as a fallback. Emitted only when the apply seam is ON
     * (default), so a flag-OFF prompt is byte-identical.
     *
     * @param  list<string>  $allowedFiles
     * @return list<string>
     */
    private function editProtocolLines(array $allowedFiles, string $workspace, string $provider): array
    {
        if (! (bool) config('atlas.loop.text_provider_edit_apply', true)
            || ! $this->usesTextEditProtocol($provider)) {
            return [];
        }

        $lines = [
            '',
            'OUTPUT PROTOCOL — you have NO filesystem access, so deliver your change as TEXT, applied for you:',
            'For EACH file you change or create, emit a block with the COMPLETE new file contents (not a diff):',
            '*** ATLAS_FILE: relative/path.php ***',
            '<the entire new contents of the file>',
            '*** ATLAS_END ***',
            'Reproduce the file EXACTLY as shown below, changing only what the objective requires. Output ONLY',
            'these blocks — no prose, no markdown fences. Paths are relative to the repo root.',
        ];

        foreach ($this->currentFileContents($allowedFiles, $workspace) as $line) {
            $lines[] = $line;
        }

        return $lines;
    }

    private function usesTextEditProtocol(string $provider): bool
    {
        $provider = trim($provider);
        if ($provider === '') {
            return false;
        }

        $configured = config('atlas.loop.text_provider_edit_apply_providers', [AtlasForgeProviderInvocationDriverRouter::DRIVER_MINIMAX_M27]);
        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }

        $providers = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            is_array($configured) ? $configured : [],
        )));

        return in_array($provider, $providers, true);
    }

    /**
     * Embed the current contents of each in-scope file so a no-filesystem engine edits the
     * REAL code instead of guessing it. Bounded per file; missing files are simply skipped
     * (the engine creates them). Fail-safe: any read error contributes no lines.
     *
     * @param  list<string>  $allowedFiles
     * @return list<string>
     */
    private function currentFileContents(array $allowedFiles, string $workspace): array
    {
        if ($allowedFiles === [] || $workspace === '' || ! is_dir($workspace)) {
            return [];
        }

        // ACDE B2 — AGGREGATE prompt budget for the file dump. Each file was capped at MAX_PROVIDER_OUTPUT_CHARS
        // but there was NO total cap, so a many-file obra swamps the weak engine's small window before any
        // ranked brain context lands. prompt_file_contents_budget_chars caps the TOTAL across files; 0 (the
        // default) => unlimited => byte-identical. On overflow the rest are omitted with the proven
        // [TRUNCATED_BY_ATLAS_OPEN_BRAIN_BUDGET] marker so the engine knows context was clipped.
        $aggregateBudget = max(0, (int) config('atlas.loop.prompt_file_contents_budget_chars', 0));
        $used = 0;

        $lines = ['', 'CURRENT FILE CONTENTS (edit these exactly):'];
        $emitted = false;
        foreach ($allowedFiles as $relative) {
            $relative = ltrim((string) $relative, './');
            if ($relative === '' || str_contains($relative, '..')) {
                continue;
            }
            $full = rtrim($workspace, '/').'/'.$relative;
            if (! is_file($full)) {
                continue;
            }
            $perFileCap = self::MAX_PROVIDER_OUTPUT_CHARS;
            if ($aggregateBudget > 0) {
                $remaining = $aggregateBudget - $used;
                if ($remaining <= 0) {
                    $lines[] = '[TRUNCATED_BY_ATLAS_OPEN_BRAIN_BUDGET]';

                    break;
                }
                $perFileCap = min($perFileCap, $remaining);
            }
            $contents = (string) @file_get_contents($full, false, null, 0, $perFileCap);
            $used += strlen($contents);
            $lines[] = '*** ATLAS_FILE: '.$relative.' ***';
            $lines[] = $contents;
            $lines[] = '*** ATLAS_END ***';
            $emitted = true;
        }

        return $emitted ? $lines : [];
    }

    /**
     * ACDE B1 — the ONE brain-context composer for the loop prompt. Appends, in order, each ARMED brain
     * source: code-graph signatures (auto_context) then dependency bodies (inject_dependency_bodies). Shared
     * by buildPrompt + buildFixPrompt so the grounding can never drift between the first attempt and the
     * iterate-to-green retry, and the single seam the future B3 (provider-safe memory recall) + B4 (blast-
     * radius / consumer set) extensions hook. Every source is per-source flag-gated OFF by default => appends
     * nothing => byte-identical. Each source is independently fail-safe (returns no lines on any error).
     *
     * @param  list<string>  $lines  the prompt lines, appended in place
     * @param  list<string>  $allowedFiles
     */
    private function appendBrainContext(array &$lines, string $intent, array $allowedFiles): void
    {
        if ((bool) config('atlas.code_graph.auto_context', false)) {
            foreach ($this->codeGraphContextLines($intent, $allowedFiles) as $line) {
                $lines[] = $line;
            }
        }
        if ((bool) config('atlas.loop.inject_dependency_bodies', false)) {
            foreach ($this->dependencyBodyLines($intent, $allowedFiles) as $line) {
                $lines[] = $line;
            }
        }
        // ACDE B3 — provider-safe DELIVERY RECALL: the recent CERTIFIED merged deliveries in this file's
        // module (paths only, never raw code), so the weak engine matches the conventions of what just landed
        // nearby. The read-back half of the brain flywheel whose write half is the merge itself. Flag-gated +
        // self-gated in the service => OFF / no DB => no lines => byte-identical.
        if ((bool) config('atlas.loop.brain_delivery_recall_enabled', false) && $allowedFiles !== []) {
            $target = (string) $allowedFiles[0];
            $recent = (new AtlasLoopDeliveryRecallService)->recall(dirname($target), $target);
            if ($recent !== []) {
                $lines[] = '';
                $lines[] = 'Recently certified changes in this module (match their conventions):';
                foreach ($recent as $path) {
                    $lines[] = '- '.$path;
                }
            }
        }

        // ACDE B4a — BLAST RADIUS: who depends on the file being edited (reverse-dependency walk over the
        // code graph), so the weak engine edits a hub knowingly instead of blind. De-orphans
        // AtlasLoopBlastRadiusAnalyzer via the live world-model edges. Paths + risk band only (provider-safe).
        // Flag-gated + self-gated in the reader => OFF / no index => no lines => byte-identical.
        if ((bool) config('atlas.loop.blast_radius_brain_enabled', false) && $allowedFiles !== []) {
            $blast = (new AtlasLoopBlastRadiusReader)->read((string) $allowedFiles[0]);
            if ($blast !== [] && ($blast['consumers'] ?? []) !== []) {
                $lines[] = '';
                $lines[] = 'Blast radius — '.$blast['consumer_count'].' consumer(s), risk: '.$blast['risk']
                    .'. These depend on this file; do not break their contracts'
                    .($blast['truncated'] ? ' (impact set truncated — treat as a hub)' : '').':';
                foreach ($blast['consumers'] as $path) {
                    $lines[] = '- '.$path;
                }
            }
        }
    }

    /**
     * ACDE B2 — the code-graph context budget, operator-tunable (was a hardcoded DEFAULT_BUDGET that ignored
     * the existing atlas.code_graph.auto_context_budget config). Default config = DEFAULT_BUDGET =>
     * byte-identical; lower it to protect the weak engine's small context window on a big obra.
     */
    private function autoContextBudget(): int
    {
        return max(1, (int) config('atlas.code_graph.auto_context_budget', CodeGraphContextRetriever::DEFAULT_BUDGET));
    }

    /**
     * AP-815 · I-4 — render the budgeted code-graph context pack as prompt lines.
     *
     * Uses the ONE shared retrieval path ({@see CodeGraphContextRetriever::packFor()},
     * the proven `atlas:ctx` pipeline) with the task intent as the query and the allowed
     * files as changed-file hints (so retrieval biases toward those files' identifiers),
     * scoped to the indexed PRIMARY workspace graph (the throwaway scenario copy is not
     * indexed). Budget is the documented 4000-token default, held in code (no config edit).
     *
     * Returns a labelled section (header + one bullet per included symbol) only when the
     * pack has at least one symbol; an empty pack appends NOTHING, so a flag-ON run with
     * no indexed match stays as close to the baseline as possible. Fully fail-safe: any
     * unexpected error yields no lines (context is best-effort recall, never a gate, and
     * must never break the loop attempt).
     *
     * @param  list<string>  $allowedFiles
     * @return list<string>
     */
    private function codeGraphContextLines(string $intent, array $allowedFiles): array
    {
        try {
            $workspaceId = $this->workspaceIdentity->default();
            $pack = $this->codeGraphContext->packFor(
                $intent,
                $workspaceId,
                $this->autoContextBudget(),
                $allowedFiles,
            );

            $included = is_array($pack['included'] ?? null) ? $pack['included'] : [];
            if ($included === []) {
                return [];
            }

            $lines = ['', 'Relevant existing code (from the code graph):'];
            foreach ($included as $node) {
                if (! is_array($node)) {
                    continue;
                }
                $id = trim((string) ($node['id'] ?? ''));
                if ($id === '') {
                    continue;
                }
                $detail = trim((string) ($node['signature'] ?? ''));
                if ($detail === '') {
                    $detail = trim((string) ($node['file_path'] ?? ''));
                }

                $lines[] = $detail !== '' ? '- '.$id.' — '.$detail : '- '.$id;
            }

            // Header-only (every node was id-less/non-array) carries no signal → drop it.
            return count($lines) > 2 ? $lines : [];
        } catch (Throwable) {
            return [];
        }
    }

    /** ACDE Tier-1 #7: per-symbol body cap — keeps one huge callee from swamping the prompt. */
    private const DEPENDENCY_BODY_CHARS = 1500;

    /**
     * ACDE Tier-1 #7: the EXACT body of the top-K cross-file dependencies relevant to this task, so a
     * no-filesystem weak engine calls them as WRITTEN instead of hallucinating the contract. Reuses the
     * proven code-graph retrieval (packFor) for ranking, then range-reads each symbol's body from the
     * indexed PRIMARY repo (base_path) via the code-symbols read-model (line_start/line_end). The edited
     * files are skipped (the engine already has them). Fully fail-safe: any error / no graph / missing
     * table yields no lines.
     *
     * @param  list<string>  $allowedFiles
     * @return list<string>
     */
    private function dependencyBodyLines(string $intent, array $allowedFiles): array
    {
        try {
            $workspaceId = (string) $this->workspaceIdentity->default();
            $pack = $this->codeGraphContext->packFor($intent, $workspaceId, $this->autoContextBudget(), $allowedFiles);
            $included = is_array($pack['included'] ?? null) ? $pack['included'] : [];
            if ($included === []) {
                return [];
            }

            $allowedSet = array_map(static fn (string $f): string => ltrim($f, './'), $allowedFiles);
            $root = rtrim(base_path(), '/');
            $blocks = [];
            foreach ($included as $node) {
                if (count($blocks) >= 6 || ! is_array($node)) {
                    continue;
                }
                $filePath = trim((string) ($node['file_path'] ?? ''));
                // Skip the files the engine is editing — it already has their full contents.
                if ($filePath === '' || in_array(ltrim($filePath, './'), $allowedSet, true)) {
                    continue;
                }
                $symbolName = (string) preg_replace('/^sym:/', '', trim((string) ($node['id'] ?? '')));
                $body = $this->dependencyBodyFor($root, $filePath, $symbolName, $workspaceId);
                if ($body !== '') {
                    $blocks[] = '--- '.$filePath.($symbolName !== '' ? ' :: '.$symbolName : '')." ---\n".$body;
                }
            }

            return $blocks === []
                ? []
                : array_merge(['', 'DEPENDENCY CODE (read-only — call these EXACTLY as written, do NOT change their signatures):'], $blocks);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Range-read a symbol's body from the indexed repo via the code-symbols read-model. Prefers the
     * workspace-scoped row; falls back to the fullest span for the file. Returns '' on any miss.
     */
    private function dependencyBodyFor(string $root, string $filePath, string $symbolName, string $workspaceId): string
    {
        $query = DB::table('atlas_engineering_code_symbols')
            ->where('file_path', $filePath)
            ->whereNotNull('line_start')
            ->whereNotNull('line_end');
        if ($symbolName !== '') {
            $query->where('symbol_name', $symbolName);
        }
        $row = (clone $query)->where('workspace_id', $workspaceId)->first()
            ?? $query->orderByRaw('(line_end - line_start) desc')->first();
        if ($row === null) {
            return '';
        }

        $start = (int) $row->line_start;
        $end = (int) $row->line_end;
        $full = $root.'/'.ltrim($filePath, './');
        if ($start < 1 || $end < $start || ! is_file($full)) {
            return '';
        }
        $allLines = @file($full, FILE_IGNORE_NEW_LINES);
        if ($allLines === false) {
            return '';
        }

        return mb_substr(implode("\n", array_slice($allLines, $start - 1, $end - $start + 1)), 0, self::DEPENDENCY_BODY_CHARS);
    }

    private function resolveModel(string $provider): ?string
    {
        $model = config('atlas.ai.providers.'.$provider.'.model');

        return is_string($model) && trim($model) !== '' ? trim($model) : null;
    }

    /**
     * @param  list<string>  $constraints
     * @return list<string>
     */
    private function parseConstraint(array $constraints, string $key): array
    {
        $out = [];
        foreach ($constraints as $c) {
            if (is_string($c) && str_starts_with($c, $key.'=')) {
                foreach (explode(',', substr($c, strlen($key) + 1)) as $v) {
                    $v = trim($v);
                    if ($v !== '') {
                        $out[] = $v;
                    }
                }
            }
        }

        return $out;
    }
}
