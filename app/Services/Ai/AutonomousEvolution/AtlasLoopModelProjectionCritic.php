<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * §3 · ARCHITECT PHASE — the CROSS-MODEL critique seam (the canon's "projeção frontier + crítica cross-model
 * em loop"). The {@see AtlasLoopGroundedProjectionRoles} deterministic critic protects every real caller +
 * forces a behavior anchor — a sound, ungameable FLOOR. A frontier model layers DEPTH on top: obligations the
 * edges don't name (a perf bound, a contract the docblock implies, a mutation a richer test should kill).
 *
 * This is the §9 model-bound piece, made SAFE by construction. The model's proposed obligations pass through
 * the FROZEN {@see AtlasLoopProjectionEngine::obligationKey} grounding gate: an out-of-enum kind, a phantom
 * target, or a FABRICATED assertion_ref (a mutation operator that does not exist in
 * {@see AtlasLoopMutationOperators::map}) is REJECTED — the model can only ADD real, runnable obligations, it
 * can NEVER weaken the contract or fabricate coverage. FAIL-CLOSED: no provider / empty / malformed output ⇒
 * [] ⇒ the architect phase runs on the deterministic floor alone (never a fabricated obligation, never a
 * blocked projection). So even a weak engine cannot harm the gate; a strong one only deepens it.
 *
 * The deterministic half (the strict parse + the engine-validation) is fully unit-testable with an injected
 * completion double (ZERO spend); the live provider call is fenced behind {@see liveCompletion}, exactly as
 * {@see AtlasLoopOrphanWiringAuthoringEngine} fences its authoring. The exact prompt that makes a given
 * provider reliably emit the marker structure is tuned empirically over runs — but a malformed completion
 * only wastes the call, never the gate.
 */
final class AtlasLoopModelProjectionCritic
{
    /** The independent perspectives the cross-model critique reasons from (deeper than a single pass). */
    public const DEFAULT_LENSES = ['correctness', 'security', 'performance', 'maintainability'];

    /** @var (callable(string,string): ?string)|null  (provider, prompt) -> raw response text */
    private $complete;

    /** @param (callable(string,string): ?string)|null $complete */
    public function __construct(?callable $complete = null)
    {
        $this->complete = $complete;
    }

    /**
     * Ask the frontier model for ADDITIONAL grounded obligations on the target, validated by the engine's
     * grounding gate. Returns only the obligation tuples the engine accepts (ungrounded ones dropped); [] on
     * any failure (the architect phase then runs on the deterministic floor).
     *
     * @param  list<string>  $publicMethods
     * @param  list<array<string,mixed>>  $deterministicObligations  the floor the model is asked to deepen (for the prompt)
     * @return list<array{kind:string, target_symbol:string, assertion_ref:string}>
     */
    public function additionalObligations(string $relTarget, string $bindingAxis, array $publicMethods = [], array $deterministicObligations = [], string $lens = ''): array
    {
        $relTarget = trim($relTarget);
        if ($relTarget === '') {
            return [];
        }

        $provider = trim((string) config('atlas.loop.default_provider', ''));
        $prompt = $this->buildPrompt($relTarget, $bindingAxis, $publicMethods, $deterministicObligations, $lens);
        $complete = $this->complete ?? fn (string $p, string $pr): ?string => $this->liveCompletion($p, $pr);

        $response = $complete($provider, $prompt);
        if (! is_string($response) || trim($response) === '') {
            return [];
        }

        return $this->parseAndGround($response, $relTarget);
    }

    /**
     * Parse the marker-delimited obligations and keep ONLY those the frozen engine grounds (real kind, real
     * target, real assertion_ref). PUBLIC so the slice test proves a fabricated obligation is dropped.
     *
     * @return list<array{kind:string, target_symbol:string, assertion_ref:string}>
     */
    public function parseAndGround(string $response, string $relTarget): array
    {
        $engine = new AtlasLoopProjectionEngine;
        $target = ltrim(trim($relTarget), '/');
        $out = [];
        $seen = [];
        foreach (explode('<<<OBLIGATION>>>', $response) as $block) {
            $kind = $this->field($block, 'kind');
            $ref = $this->field($block, 'assertion');
            if ($kind === '' || $ref === '') {
                continue;
            }
            // The model may only deepen obligations on the EVOLUTION'S OWN target — never smuggle a change to
            // some other symbol. The target is fixed to the projected file; the model picks kind + assertion.
            $tuple = ['kind' => $kind, 'target_symbol' => $target, 'assertion_ref' => $ref];
            $key = $engine->obligationKey($tuple);
            if ($key === null || isset($seen[$key])) {
                continue; // ungrounded / fabricated / duplicate ⇒ dropped by the frozen grounding gate
            }
            $seen[$key] = true;
            $out[] = $tuple;
        }

        return $out;
    }

    /**
     * Run the critique across MULTIPLE independent LENSES — each a distinct perspective (correctness,
     * security, performance, maintainability) the frontier model reasons from — and UNION the grounded
     * obligations, deduped by the frozen engine's key. More lenses ⇒ a deeper, multi-perspective contract
     * (the canon's "crítica cross-model em loop", made multi-lens). Fail-closed per lens: a lens with no /
     * garbage completion contributes nothing; the union can only DEEPEN, never weaken (every tuple is
     * re-validated by obligationKey).
     *
     * @param  list<string>  $publicMethods
     * @param  list<array<string,mixed>>  $deterministicObligations
     * @param  list<string>  $lenses
     * @return list<array{kind:string, target_symbol:string, assertion_ref:string}>
     */
    public function lensedObligations(string $relTarget, string $bindingAxis, array $publicMethods = [], array $deterministicObligations = [], array $lenses = self::DEFAULT_LENSES): array
    {
        $engine = new AtlasLoopProjectionEngine;
        $seen = [];
        $out = [];
        foreach (($lenses === [] ? [''] : $lenses) as $lens) {
            foreach ($this->additionalObligations($relTarget, $bindingAxis, $publicMethods, $deterministicObligations, (string) $lens) as $ob) {
                $key = $engine->obligationKey($ob);
                if ($key !== null && ! isset($seen[$key])) {
                    $seen[$key] = true;
                    $out[] = $ob;
                }
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $publicMethods
     * @param  list<array<string,mixed>>  $deterministicObligations
     */
    private function buildPrompt(string $relTarget, string $bindingAxis, array $publicMethods, array $deterministicObligations, string $lens = ''): string
    {
        $kinds = implode(', ', AtlasLoopProjectionEngine::KINDS);
        $ops = implode(', ', array_keys(AtlasLoopMutationOperators::map()));
        $methods = $publicMethods === [] ? '(unknown)' : implode(', ', $publicMethods);
        $floor = implode(', ', array_map(static fn (array $o): string => (string) ($o['kind'] ?? ''), $deterministicObligations)) ?: '(none yet)';
        $lensLine = trim($lens) === '' ? '' : "\n            Reason specifically through the {$lens} lens — the obligations that lens demands.";

        return <<<PROMPT
            You are the INDEPENDENT critic in a design↔critique loop (you did NOT write the design). The
            evolution targets {$relTarget} (public methods: {$methods}); the binding system axis is
            {$bindingAxis}. The deterministic floor already covers: {$floor}. Raise ADDITIONAL obligations a
            principal engineer would require before this change is safe — ONLY ones a machine can later check.{$lensLine}

            Output ONLY this marker format, nothing else; one block per obligation:
            <<<OBLIGATION>>>
            kind=<one of: {$kinds}>
            assertion=<mutop:<id> | chartest:<tests/PathTest.php::test_x> | consumer:<symbol>>
            <<<OBLIGATION>>>
            ... (repeat) ...
            <<<END>>>

            mutop ids MUST be real: {$ops}. Anything ungrounded is rejected, so be concrete.
            PROMPT;
    }

    private function field(string $block, string $name): string
    {
        if (! preg_match('/^\s*'.preg_quote($name, '/').'\s*=\s*(.+)$/mi', $block, $m)) {
            return '';
        }

        return trim($m[1]);
    }

    /**
     * The live provider call — fenced + FAIL-CLOSED, wrapping the loop's canonical router exactly as
     * {@see AtlasLoopOrphanWiringAuthoringEngine::liveCompletion}. An unconfigured/empty provider or empty
     * output ⇒ null ⇒ the deterministic floor stands alone. NOT a cert: the engine's grounding gate still
     * validates every proposed obligation, so a garbage completion can only waste the call.
     */
    private function liveCompletion(string $provider, string $prompt): ?string
    {
        if ($provider === '') {
            return null;
        }
        $router = app(\App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter::class);
        if (! $router->isConfigured($provider)) {
            return null;
        }
        $result = $router->invoke($provider, null, [
            'text' => $prompt,
            'instruction' => $prompt,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ], [
            'timeout_seconds' => max(60, (int) config('atlas.loop.campaign.attempt_hard_seconds', 900)),
            'max_output_chars' => 8000,
        ]);
        if (($result['provider_called'] ?? false) !== true) {
            return null;
        }
        $stdout = (string) ($result['stdout'] ?? $result['output_excerpt'] ?? '');

        return $stdout !== '' ? $stdout : null;
    }
}
