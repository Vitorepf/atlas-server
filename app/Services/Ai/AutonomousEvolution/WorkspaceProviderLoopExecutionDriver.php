<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter;
use App\Services\Engineering\CodeGraph\CodeGraphContextRetriever;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
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
        ]);

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

        return $result;
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
        $prompt = $this->buildPrompt($intent, $allowedFiles, $validationCommands, $workspace);
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
                $p = new Process(['bash', '-lc', $cmd], $workspace, null, null, (float) $timeout);
                $p->run();

                return ['passed' => $p->isSuccessful(), 'output' => mb_substr($p->getOutput()."\n".$p->getErrorOutput(), -4000)];
            };
            $reinvoke = function (string $failure) use ($provider, $model, $intent, $allowedFiles, $validationCommands, $workspace, $timeout, &$result): void {
                $result = $this->invokeWithEditApply($provider, $model, $this->buildFixPrompt($intent, $allowedFiles, $validationCommands, $failure, $workspace), $workspace, $timeout, $allowedFiles);
            };
            $iterateMeta = (new AtlasLoopIterateToGreenExecutor)->pursue($runTest, $reinvoke, $maxIter);
        }

        $called = (bool) ($result['provider_called'] ?? false);

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
            'iterate_to_green' => $iterateMeta,
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
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $validationCommands
     * @return array<string,mixed>
     */
    private function buildPrompt(string $intent, array $allowedFiles, array $validationCommands, string $workspace = ''): array
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
        $lines[] = 'Do NOT modify anything under tests/ or composer.json — those are the frozen acceptance and must stay untouched.';
        if ($validationCommands !== []) {
            $lines[] = 'Your change is correct only when this passes: '.implode(' && ', $validationCommands).'.';
        }
        $lines[] = 'Preserve all existing behavior; make the smallest change that satisfies the objective.';
        foreach ($this->editProtocolLines($allowedFiles, $workspace) as $line) {
            $lines[] = $line;
        }

        // AP-815 · I-4 loop seam: when the operator flips the auto-context flag ON, pull
        // the precise, budgeted code-graph pack (proven atlas:ctx retrieval) for this
        // task and append it as a clearly-labelled section so the provider edits with the
        // relevant EXISTING code in view. Flag OFF (default) → this whole block is skipped
        // → $lines, and therefore the prompt, are byte-identical to before this seam.
        if ((bool) config('atlas.code_graph.auto_context', false)) {
            foreach ($this->codeGraphContextLines($intent, $allowedFiles) as $line) {
                $lines[] = $line;
            }
        }

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
    private function buildFixPrompt(string $intent, array $allowedFiles, array $validationCommands, string $failure, string $workspace = ''): array
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
        $lines[] = 'Do NOT modify anything under tests/ or composer.json. Make the smallest change that turns the test GREEN while preserving existing behavior.';
        foreach ($this->editProtocolLines($allowedFiles, $workspace) as $line) {
            $lines[] = $line;
        }
        $text = implode("\n", $lines);

        return ['text' => $text, 'instruction' => $text, 'messages' => [['role' => 'user', 'content' => $text]]];
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
    private function editProtocolLines(array $allowedFiles, string $workspace): array
    {
        if (! (bool) config('atlas.loop.text_provider_edit_apply', true)) {
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
            $contents = (string) @file_get_contents($full, false, null, 0, self::MAX_PROVIDER_OUTPUT_CHARS);
            $lines[] = '*** ATLAS_FILE: '.$relative.' ***';
            $lines[] = $contents;
            $lines[] = '*** ATLAS_END ***';
            $emitted = true;
        }

        return $emitted ? $lines : [];
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
                CodeGraphContextRetriever::DEFAULT_BUDGET,
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
