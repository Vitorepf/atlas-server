<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * §2/§3 · LEVERAGE SELECTION — "o cérebro escolhe o MAIOR passo, por valor real, não proxy".
 *
 * The {@see \App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopComprehensionOriginationCandidates} producer
 * emits an UNRANKED list of grounded candidates — deliberately, because the anti-Goodhart spine FORBIDS a
 * deterministic structural ranker (encoding orphan/clone/caller-count into a scalar the loop climbs = the
 * cyclomatic proxy reborn one level up). So WHICH candidate is the highest-leverage evolution is the frontier
 * model's judgment. This is that seam, made SAFE by construction.
 *
 * The model is shown the grounded candidate summaries and replies with the INDEX of the single highest-leverage
 * one. The deterministic layer then VALIDATES the pick is a real, in-range index — so the model can only choose
 * AMONG the already-grounded, already-admissible candidates; it can NEVER fabricate work, smuggle a proxy
 * target, or invent a candidate the comprehension model did not produce. FAIL-CLOSED: no provider, malformed
 * output, or an out-of-range pick ⇒ null ⇒ the caller keeps the producer's deterministic order (no judgment
 * imposed). There is NO score stored anywhere — it is a one-shot pick, model-bound, fenced. The live provider
 * call is behind {@see liveCompletion}, exactly as the orphan-wiring authoring + cross-model critique seams.
 */
final class AtlasLoopLeverageSelector
{
    /** @var (callable(string,string): ?string)|null  (provider, prompt) -> raw response text */
    private $complete;

    /** @param (callable(string,string): ?string)|null $complete */
    public function __construct(?callable $complete = null)
    {
        $this->complete = $complete;
    }

    /**
     * Return the candidates REORDERED with the model's highest-leverage pick first (stable for the rest), or
     * the input UNCHANGED when no honest pick is available (fail-closed). The returned list is always a
     * permutation of the input — never adds, drops, or mutates a candidate.
     *
     * @param  list<array<string,mixed>>  $candidates
     * @return list<array<string,mixed>>
     */
    public function rank(array $candidates): array
    {
        $pick = $this->pickIndex($candidates);
        if ($pick === null) {
            return $candidates; // fail-closed: keep the producer's deterministic order
        }
        $chosen = $candidates[$pick];
        unset($candidates[$pick]);

        return array_merge([$chosen], array_values($candidates));
    }

    /**
     * Ask the model for the index of the highest-leverage candidate; return it ONLY if it is a real, in-range
     * index (else null). PUBLIC so the slice test proves the validation directly. The model can pick AMONG the
     * real set — never outside it.
     *
     * @param  list<array<string,mixed>>  $candidates
     */
    public function pickIndex(array $candidates): ?int
    {
        $n = count($candidates);
        if ($n <= 1) {
            return null; // nothing to choose (0 or 1 candidate) ⇒ no judgment needed
        }

        $provider = trim((string) config('atlas.loop.default_provider', ''));
        $prompt = $this->buildPrompt($candidates);
        $complete = $this->complete ?? fn (string $p, string $pr): ?string => $this->liveCompletion($p, $pr);

        $response = $complete($provider, $prompt);
        if (! is_string($response) || trim($response) === '') {
            return null;
        }

        if (! preg_match('/<<<PICK>>>\s*(\d+)/', $response, $m)) {
            return null; // malformed ⇒ fail-closed
        }
        $index = (int) $m[1];

        // The load-bearing validation: the pick MUST be a real, in-range candidate index. A model that names
        // anything outside [0, n) is rejected — it can never fabricate or reach past the grounded set.
        return ($index >= 0 && $index < $n) ? $index : null;
    }

    /**
     * @param  list<array<string,mixed>>  $candidates
     */
    private function buildPrompt(array $candidates): string
    {
        $lines = [];
        foreach ($candidates as $i => $c) {
            $kind = (string) ($c['kind'] ?? ($c['payload']['objective_kind'] ?? 'unknown'));
            $summary = trim((string) ($c['summary'] ?? ($c['objective'] ?? '')));
            $lines[] = "[{$i}] ({$kind}) {$summary}";
        }
        $list = implode("\n", $lines);

        return <<<PROMPT
            You are choosing the SINGLE highest-LEVERAGE evolution to do FIRST from this grounded candidate list.
            Highest leverage = the one that most increases the system's real capability / safety / autonomy — NOT
            the easiest, NOT a cosmetic or proxy win. Judge by real value to the codebase.

            Candidates:
            {$list}

            Reply with ONLY this marker and the index of your single best pick, nothing else:
            <<<PICK>>>
            <index>
            PROMPT;
    }

    /**
     * The live provider call — fenced + FAIL-CLOSED, wrapping the loop's canonical router exactly as the
     * orphan-wiring authoring + cross-model critique seams. Empty/unconfigured/empty-output ⇒ null ⇒ the
     * producer's deterministic order stands. NOT a cert and NOT a mutation — it only REORDERS a real list.
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
            'max_output_chars' => 2000,
        ]);
        if (($result['provider_called'] ?? false) !== true) {
            return null;
        }
        $stdout = (string) ($result['stdout'] ?? $result['output_excerpt'] ?? '');

        return $stdout !== '' ? $stdout : null;
    }
}
