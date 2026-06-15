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
     * AP-815 · I-4 loop seam: the code-graph context retriever (and the workspace
     * identity that scopes it to the indexed primary graph). Constructor-injected so
     * Laravel auto-wires both concrete services; they are touched ONLY when the
     * `atlas.code_graph.auto_context` flag is ON, so the default-OFF path is unchanged.
     */
    public function __construct(
        private readonly AtlasForgeProviderInvocationDriverRouter $router,
        private readonly CodeGraphContextRetriever $codeGraphContext,
        private readonly CodeGraphWorkspaceIdentity $workspaceIdentity,
    ) {}

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
        $prompt = $this->buildPrompt($intent, $allowedFiles, $validationCommands);
        $model = $this->resolveModel($provider);
        $timeout = max(60, (int) config('atlas.loop.campaign.attempt_hard_seconds', 900));

        $result = $this->router->invoke($provider, $model, $prompt, [
            'cwd' => $workspace,
            'timeout_seconds' => $timeout,
            'max_output_chars' => 16000,
        ]);

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
            $result = $this->router->invoke($provider, $model, $prompt, [
                'cwd' => $workspace,
                'timeout_seconds' => $timeout,
                'max_output_chars' => 16000,
            ]);
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
            $runTest = function () use ($cmd, $workspace, $timeout): array {
                $p = new Process(['bash', '-lc', $cmd], $workspace, null, null, (float) $timeout);
                $p->run();

                return ['passed' => $p->isSuccessful(), 'output' => mb_substr($p->getOutput()."\n".$p->getErrorOutput(), -4000)];
            };
            $reinvoke = function (string $failure) use ($provider, $model, $intent, $allowedFiles, $validationCommands, $workspace, $timeout, &$result): void {
                $result = $this->router->invoke($provider, $model, $this->buildFixPrompt($intent, $allowedFiles, $validationCommands, $failure), [
                    'cwd' => $workspace,
                    'timeout_seconds' => $timeout,
                    'max_output_chars' => 16000,
                ]);
            };
            $iterateMeta = (new AtlasLoopIterateToGreenExecutor())->pursue($runTest, $reinvoke, $maxIter);
        }

        $called = (bool) ($result['provider_called'] ?? false);

        return [
            'status' => $called ? 'completed' : 'blocked',
            'provider' => $provider,
            'provider_invoked' => $called,
            'changed_files' => $result['changed_files'] ?? [],
            'exit_code' => $result['exit_code'] ?? null,
            'zero_diff_retry' => $zeroDiffRetry,
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
    private function buildPrompt(string $intent, array $allowedFiles, array $validationCommands): array
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
    private function buildFixPrompt(string $intent, array $allowedFiles, array $validationCommands, string $failure): array
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
        $text = implode("\n", $lines);

        return ['text' => $text, 'instruction' => $text, 'messages' => [['role' => 'user', 'content' => $text]]];
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
