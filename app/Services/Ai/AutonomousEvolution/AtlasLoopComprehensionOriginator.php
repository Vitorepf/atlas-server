<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopComprehensionGroundingGate;
use App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter;

/**
 * §5.6 · LAYER 2 — CROSS-MODEL ORIGINATION: the "decide" phase ORIGINATING a new evolution, not merely
 * selecting among pre-built supply.
 *
 * The supply lanes mint KNOWN work (this orphan, that clone). The frontier of the brain is ORIGINATION — the
 * model reasoning over the grounded comprehension substrate to PROPOSE a high-leverage evolution the lanes
 * never enumerated. That is model-bound (judgement), so it is fenced behind a WRITER seam. But the danger of
 * a free-text proposal is hallucination — a "wire AtlasLoopFooBar" objective citing a symbol that does not
 * exist. So WRITER ≠ JUDGE by construction: the writer (a frontier model) PROPOSES {objective, cited_symbols};
 * the JUDGE is the DETERMINISTIC {@see AtlasLoopComprehensionGroundingGate::groundAgainstInventory} — every
 * cited symbol must be a MEMBER of the brain's own inventory (exact FQCN / rel-path / class-name), or the
 * proposal is REFUTED. The writer can be empirical; the gate keeps it honest.
 *
 * FAIL-CLOSED: no writer / no proposal / a proposal with no citations / ANY refuted citation ⇒ no origination.
 * An origination only exists when a model proposed it AND the deterministic inventory judge cleared every
 * symbol it rests on. Pure deterministic half (prompt + parse + the membership judge); the proposal is the
 * only empirical piece, §9-fenced behind {@see liveWriter}.
 */
final class AtlasLoopComprehensionOriginator
{
    /** @var (callable(string): ?array<string,mixed>)|null  (facts-prompt) -> {objective, cited_symbols} */
    private $writer;

    /** @param (callable(string): ?array<string,mixed>)|null $writer */
    public function __construct(?callable $writer = null, private readonly ?AtlasLoopComprehensionGroundingGate $judge = null)
    {
        $this->writer = $writer;
    }

    /**
     * Originate ONE grounded evolution from the comprehension substrate, or a refusal.
     *
     * @param  list<string>  $priorAttempts  targets that already parked / did not converge THIS campaign —
     *                                       passed to the writer as CONTEXT (informing, NEVER a veto: the
     *                                       model stays free to re-cite one with a genuinely better design).
     *                                       §5 learning realimenting comprehension WITHOUT the #4 Goodhart
     *                                       surface of autonomously suppressing non-converged work.
     * @return array{originated:bool, objective:?string, cited_symbols:list<string>, refuted:list<string>, reason:?string}
     */
    public function originate(AtlasLoopScopeComprehensionModel $model, array $priorAttempts = []): array
    {
        $writer = $this->writer ?? fn (string $p): ?array => $this->liveWriter($p);
        $proposal = $writer($this->buildPrompt($model, $priorAttempts));
        if (! is_array($proposal)) {
            return $this->refuse([], 'no_proposal'); // fail-closed: no writer / no completion
        }

        $objective = trim((string) ($proposal['objective'] ?? ''));
        $cited = array_values(array_filter(array_map(
            static fn (mixed $s): string => is_string($s) ? trim($s) : '',
            (array) ($proposal['cited_symbols'] ?? []),
        ), static fn (string $s): bool => $s !== ''));

        if ($objective === '' || $cited === []) {
            return $this->refuse($cited, 'ungrounded_proposal'); // an origination must cite ≥1 real symbol
        }

        // WRITER ≠ JUDGE — the deterministic inventory judge, independent of the writer, clears every citation.
        $verdict = ($this->judge ?? new AtlasLoopComprehensionGroundingGate)
            ->groundAgainstInventory($objective, $cited, $this->inventoryFor($model));

        if (($verdict['grounded'] ?? false) !== true) {
            return [
                'originated' => false,
                'objective' => $objective,
                'cited_symbols' => $cited,
                'refuted' => array_values((array) ($verdict['refuted'] ?? $cited)),
                'reason' => 'citations_refuted_by_inventory_judge',
            ];
        }

        // ANCHORED origination: proceed on the citations the judge RESOLVED, dropping any loose ones it
        // refuted (kept in `refuted` as provenance, never acted on). The objective is anchored in the real
        // map; the downstream target is chosen from resolved symbols, and the materializer still requires
        // that target to EXIST — so a dropped loose citation can never reach a hallucinated edit.
        $resolved = array_values(array_filter(array_map(
            static fn (mixed $s): string => is_string($s) ? trim($s) : '',
            (array) ($verdict['resolved'] ?? $cited),
        ), static fn (string $s): bool => $s !== ''));

        return [
            'originated' => true,
            'objective' => $objective,
            'cited_symbols' => $resolved !== [] ? $resolved : $cited,
            'refuted' => array_values((array) ($verdict['refuted'] ?? [])),
            'reason' => null,
        ];
    }

    /**
     * @param  list<string>  $cited
     * @return array{originated:false, objective:null, cited_symbols:list<string>, refuted:list<string>, reason:string}
     */
    private function refuse(array $cited, string $reason): array
    {
        return ['originated' => false, 'objective' => null, 'cited_symbols' => $cited, 'refuted' => $cited, 'reason' => $reason];
    }

    /**
     * The inventory the judge resolves citations against — fqcn + rel-path per real member.
     *
     * @return list<array{rel_path:string, fqcn:string}>
     */
    private function inventoryFor(AtlasLoopScopeComprehensionModel $model): array
    {
        return array_map(static fn (array $i): array => [
            'rel_path' => (string) ($i['rel_path'] ?? ''),
            'fqcn' => (string) ($i['fqcn'] ?? ''),
        ], $model->inventory);
    }

    /** @param list<string> $priorAttempts */
    private function buildPrompt(AtlasLoopScopeComprehensionModel $model, array $priorAttempts = []): string
    {
        $orphans = implode(', ', array_slice($model->orphans, 0, 12)) ?: '(none)';
        $gaps = implode(', ', array_slice($model->docStatedGaps, 0, 12)) ?: '(none)';
        $cloneCount = count($model->cloneClusters);

        $learned = '';
        $prior = array_values(array_unique(array_filter(array_map('trim', $priorAttempts), static fn (string $s): bool => $s !== '')));
        if ($prior !== []) {
            $list = implode(', ', array_slice($prior, 0, 12));
            // INFORM, never veto — the writer may still re-cite one of these if it has a genuinely better design.
            $learned = "\n\nAlready attempted this campaign and did NOT converge: {$list}. Prefer a FRESH "
                .'evolution; only re-cite one of these if you have a genuinely better design than last time.';
        }

        // COMPREHENSION-DEEPENING seam: surface declared-but-unimplemented CONTRACTS (interfaces with ZERO
        // implementer) as a high-leverage origination axis the writer was blind to — architecture-completion,
        // not orphan-wiring. Binary/grounded signal (shared AtlasLoopContractGapScanner). Flag OFF (default) =>
        // $contractLine = '' => the prompt is byte-identical. ON => the automated writer perceives contract gaps.
        $contractLine = '';
        if ((bool) config('atlas.loop.contract_gap_origination_enabled', false)) {
            $paths = array_map(
                static fn (array $row): string => base_path((string) ($row['rel_path'] ?? '')),
                array_values($model->inventory),
            );
            $gaps = (new AtlasLoopContractGapScanner)->capabilityGaps($paths, base_path());
            $contractLine = self::contractGapPromptLine(array_map(static fn (array $g): string => (string) $g['fqcn'], $gaps));
        }

        return <<<PROMPT
            You reason over the loop's OWN comprehension model and ORIGINATE the single highest-LEVERAGE
            evolution of its scope — a real capability, a structural improvement, a removed coupling — NOT a
            cosmetic or proxy change. Facts: built-but-unwired orphans: {$orphans}. Capabilities the canonical
            docs demand but no symbol provides: {$gaps}. Structural clone clusters: {$cloneCount}.{$learned}{$contractLine}

            Propose ONE evolution. Every symbol you cite MUST be a REAL member of the scope (it is checked
            against the inventory — a cited symbol that does not exist REFUTES your whole proposal). Output
            ONLY this marker format:
            <<<OBJECTIVE>>>
            <one sentence: the evolution to originate>
            <<<CITES>>>
            <comma-separated REAL symbols/paths it rests on>
            <<<END>>>
            PROMPT;
    }

    /**
     * Pure: render the contract-gap fact line for the writer prompt. EMPTY list => '' (so the OFF path is
     * byte-identical). Public+static so the byte-identical-OFF + ON-injection contract is directly testable.
     *
     * @param  list<string>  $fqcns
     */
    public static function contractGapPromptLine(array $fqcns): string
    {
        $fqcns = array_values(array_filter(array_map('trim', $fqcns), static fn (string $s): bool => $s !== ''));
        if ($fqcns === []) {
            return '';
        }
        $list = implode(', ', array_slice($fqcns, 0, 8));

        return "\n\nDeclared-but-UNIMPLEMENTED contracts (an interface in scope with ZERO implementer — building "
            ."a concrete implementation is a high-leverage capability completion, NOT orphan-wiring): {$list}.";
    }

    /**
     * The live writer — fenced + FAIL-CLOSED, wrapping the loop's canonical router (same shape as the other
     * §9 seams). Returns the parsed {objective, cited_symbols}, or null on any failure ⇒ no origination.
     *
     * @return array<string,mixed>|null
     */
    private function liveWriter(string $prompt): ?array
    {
        $provider = trim((string) config('atlas.provider_defaults.brain_default', config('atlas.loop.default_provider', '')));
        if ($provider === '') {
            return null;
        }
        $router = app(AtlasForgeProviderInvocationDriverRouter::class);
        if (! $router->isConfigured($provider)) {
            return null;
        }
        $result = $router->invoke($provider, null, ['text' => $prompt, 'instruction' => $prompt, 'messages' => [['role' => 'user', 'content' => $prompt]]], [
            'timeout_seconds' => max(5, (int) config('atlas.brain.writer_timeout_seconds', 30)),
            'max_output_chars' => 4000,
        ]);
        if (($result['provider_called'] ?? false) !== true) {
            return null;
        }

        return $this->parse((string) ($result['stdout'] ?? $result['output_excerpt'] ?? ''));
    }

    /**
     * Strict marker parse of a writer response. PUBLIC so the slice can prove parse + the membership judge.
     *
     * @return array{objective:string, cited_symbols:list<string>}|null
     */
    public function parse(string $response): ?array
    {
        $objective = $this->between($response, '<<<OBJECTIVE>>>', '<<<CITES>>>');
        $citesRaw = $this->between($response, '<<<CITES>>>', '<<<END>>>');
        if ($objective === null || $citesRaw === null) {
            return null;
        }
        $cited = array_values(array_filter(array_map('trim', explode(',', $citesRaw)), static fn (string $s): bool => $s !== ''));
        if (trim($objective) === '' || $cited === []) {
            return null;
        }

        return ['objective' => trim($objective), 'cited_symbols' => $cited];
    }

    private function between(string $haystack, string $open, string $close): ?string
    {
        $start = strpos($haystack, $open);
        if ($start === false) {
            return null;
        }
        $start += strlen($open);
        $end = strpos($haystack, $close, $start);

        return $end === false ? null : substr($haystack, $start, $end - $start);
    }
}
