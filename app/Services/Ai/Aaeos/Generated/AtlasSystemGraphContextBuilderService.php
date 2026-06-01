<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Context Builder (kernel pipeline step / system-graph node) — runtime.
 *
 * Turns the system-graph `context-builder` node into deterministic, pure
 * decision logic. It sits between `domain-profile-flow` and `policy-profile`:
 * it compiles the curated context pack the AI receives BEFORE policy + decision.
 * It enforces exactly the doc's concrete contract:
 *
 *  - Contract: Input = dominio, Obra, intent and AUTHORIZED sources. Output =
 *    a context pack with sources, summary and limits.
 *  - INVARIANT (hard): "toda fonte deve ser rastreavel" — every source must be
 *    traceable. A source with no origin/reference is NEVER admitted; it is
 *    rejected, not silently dropped into the pack.
 *  - Escopo Proibido: "esconder origem ou misturar fonte nao autorizada" — a
 *    source whose origin is not in the authorized set is excluded as
 *    unauthorized. Origin is always carried (never hidden) on admitted sources.
 *  - Decision (frontmatter): "Contexto deve ser compilado e rastreavel; nao
 *    deve ser despejo bruto de docs." The builder ranks, summarizes and caps —
 *    it does not emit a raw dump.
 *  - Riscos: "Contexto demais reduzir precisao." Permitido: ranking, resumo,
 *    compactacao. The pack is capped at a budget; lowest-ranked admitted sources
 *    are compacted out (recorded as trimmed) so precision is protected.
 *  - Regras para IA: an implementation decision must cite/preserve context
 *    references. The pack therefore exposes a citation set; if it is empty the
 *    pack is flagged not-ready-for-implementation.
 *
 * Stateless and DB-free: every method is a pure function of its arguments.
 *
 * @see docs/engineering-knowledge-base/system-graph/context-builder.md
 */
final class AtlasSystemGraphContextBuilderService
{
    public const SCHEMA_VERSION = 'atlas.kernel.context_builder.v1';

    /**
     * Default cap on how many sources may enter the compiled pack. Beyond this
     * the lowest-ranked admitted sources are compacted out to protect precision
     * ("contexto demais reduzir precisao").
     */
    public const DEFAULT_BUDGET = 5;

    /** Reasons a candidate source is refused admission to the pack. */
    public const REJECT_UNTRACEABLE = 'untraceable_source';

    public const REJECT_UNAUTHORIZED = 'unauthorized_source';

    /** Reason an admitted source is dropped from the final pack by the budget. */
    public const TRIM_BUDGET = 'compacted_for_budget';

    /**
     * Primary entry point. Compile the curated context pack for a request.
     *
     * Expected request shape (safe defaults applied):
     *   [
     *     'domain'  => 'programming',
     *     'obra'    => 'refactor-x',                 // the Obra / work unit
     *     'intent'  => 'implement',
     *     'authorized_origins' => ['repo_docs', 'memory_core'], // allow-list
     *     'budget'  => 5,                            // optional, default 5
     *     'candidates' => [
     *        ['id' => 'doc-1', 'origin' => 'repo_docs', 'title' => '...',
     *         'summary' => '...', 'relevance' => 0.9, 'reference' => 'path/x.md'],
     *        ...
     *     ],
     *   ]
     *
     * A candidate is ADMITTED only if it is traceable (has a non-empty origin
     * AND reference) AND its origin is in `authorized_origins`. Admitted sources
     * are ranked by relevance, then capped at the budget; the overflow is
     * compacted out and recorded. The summary is compiled from admitted sources.
     *
     * @param  array<string,mixed>  $request
     * @return array{
     *   schema_version:string,
     *   domain:string,
     *   obra:string,
     *   intent:string,
     *   budget:int,
     *   sources:list<array{id:string,origin:string,title:string,reference:string,relevance:float,rank:int}>,
     *   summary:string,
     *   limits:array{budget:int,admitted:int,trimmed:int,rejected:int},
     *   rejected:list<array{id:string,origin:string,reason:string}>,
     *   trimmed:list<array{id:string,reason:string}>,
     *   citations:list<string>,
     *   traceable:bool,
     *   ready_for_implementation:bool,
     *   reasons:list<string>
     * }
     */
    public function compile(array $request): array
    {
        $domain = strtolower(trim((string) ($request['domain'] ?? '')));
        $obra = trim((string) ($request['obra'] ?? ''));
        $intent = strtolower(trim((string) ($request['intent'] ?? '')));
        $budget = $this->normalizeBudget($request['budget'] ?? self::DEFAULT_BUDGET);

        $authorized = $this->normalizeOrigins($request['authorized_origins'] ?? []);
        $candidates = is_array($request['candidates'] ?? null) ? $request['candidates'] : [];

        $admitted = [];
        $rejected = [];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $id = trim((string) ($candidate['id'] ?? ''));
            $origin = strtolower(trim((string) ($candidate['origin'] ?? '')));
            $reference = trim((string) ($candidate['reference'] ?? ''));

            // INVARIANT: every source must be traceable (origin + reference).
            if (! $this->isTraceable($origin, $reference)) {
                $rejected[] = ['id' => $id, 'origin' => $origin, 'reason' => self::REJECT_UNTRACEABLE];

                continue;
            }
            // Proibido: misturar fonte nao autorizada. Origin must be allowed.
            if (! in_array($origin, $authorized, true)) {
                $rejected[] = ['id' => $id, 'origin' => $origin, 'reason' => self::REJECT_UNAUTHORIZED];

                continue;
            }

            $admitted[] = [
                'id' => $id,
                'origin' => $origin,
                'title' => trim((string) ($candidate['title'] ?? $id)),
                'reference' => $reference,
                'relevance' => $this->clampRelevance($candidate['relevance'] ?? 0.0),
            ];
        }

        // Rank by relevance desc; stable tie-break on id so output is deterministic.
        usort($admitted, static function (array $a, array $b): int {
            return $b['relevance'] <=> $a['relevance']
                ?: strcmp($a['id'], $b['id']);
        });

        // Compaction: keep the budget's worth of top-ranked sources; trim the rest.
        $kept = array_slice($admitted, 0, $budget);
        $overflow = array_slice($admitted, $budget);

        $sources = [];
        $rank = 1;
        foreach ($kept as $source) {
            $source['rank'] = $rank++;
            $sources[] = $source;
        }

        $trimmed = array_map(
            static fn (array $s): array => ['id' => $s['id'], 'reason' => self::TRIM_BUDGET],
            $overflow
        );

        $citations = array_values(array_map(
            static fn (array $s): string => $s['reference'],
            $sources
        ));

        $reasons = [];
        if ($rejected !== []) {
            $reasons[] = 'rejected_sources_excluded:'.count($rejected);
        }
        if ($trimmed !== []) {
            $reasons[] = 'compacted_for_precision:'.count($trimmed);
        }

        // Regras para IA: a decision must cite context. No citation -> not ready.
        $readyForImplementation = $sources !== [];
        if (! $readyForImplementation) {
            $reasons[] = 'no_traceable_context_admitted';
        } elseif ($reasons === []) {
            $reasons[] = 'compiled_traceable_context';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'domain' => $domain,
            'obra' => $obra,
            'intent' => $intent,
            'budget' => $budget,
            'sources' => $sources,
            'summary' => $this->compileSummary($obra, $intent, $sources),
            'limits' => [
                'budget' => $budget,
                'admitted' => count($sources),
                'trimmed' => count($trimmed),
                'rejected' => count($rejected),
            ],
            'rejected' => array_values($rejected),
            'trimmed' => array_values($trimmed),
            'citations' => $citations,
            // Invariant holds when nothing untraceable slipped in. By construction
            // every admitted source is traceable, so this is always true here, but
            // it is asserted explicitly as the pack's traceability guarantee.
            'traceable' => $this->allTraceable($sources),
            'ready_for_implementation' => $readyForImplementation,
            'reasons' => $reasons,
        ];
    }

    /**
     * A source is traceable iff it carries BOTH a non-empty origin and a
     * non-empty reference (path/id pointing at the origin). Either missing makes
     * the source untraceable and inadmissible.
     */
    public function isTraceable(string $origin, string $reference): bool
    {
        return trim($origin) !== '' && trim($reference) !== '';
    }

    /**
     * Whether a single candidate (raw shape) would be admitted given an
     * authorized-origin allow-list. Pure helper for callers that want to test a
     * source before assembling a full request.
     *
     * @param  array<string,mixed>  $candidate
     * @param  list<string>  $authorizedOrigins
     * @return array{admit:bool,reason:?string}
     */
    public function admits(array $candidate, array $authorizedOrigins): array
    {
        $origin = strtolower(trim((string) ($candidate['origin'] ?? '')));
        $reference = trim((string) ($candidate['reference'] ?? ''));
        $authorized = $this->normalizeOrigins($authorizedOrigins);

        if (! $this->isTraceable($origin, $reference)) {
            return ['admit' => false, 'reason' => self::REJECT_UNTRACEABLE];
        }
        if (! in_array($origin, $authorized, true)) {
            return ['admit' => false, 'reason' => self::REJECT_UNAUTHORIZED];
        }

        return ['admit' => true, 'reason' => null];
    }

    /**
     * @param  list<array{reference:string}>  $sources
     */
    private function allTraceable(array $sources): bool
    {
        foreach ($sources as $source) {
            if (trim((string) ($source['reference'] ?? '')) === ''
                || trim((string) ($source['origin'] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Compile a consumable summary from the admitted sources. This is the
     * "compiled, traceable" form — never a raw dump of full docs.
     *
     * @param  list<array{title:string}>  $sources
     */
    private function compileSummary(string $obra, string $intent, array $sources): string
    {
        if ($sources === []) {
            return 'No authorized, traceable context available for this request.';
        }
        $titles = array_map(static fn (array $s): string => $s['title'], $sources);
        $obraLabel = $obra !== '' ? $obra : 'request';
        $intentLabel = $intent !== '' ? $intent : 'work';

        return sprintf(
            'Curated context for %s (%s): %d source(s) — %s.',
            $obraLabel,
            $intentLabel,
            count($sources),
            implode(', ', $titles)
        );
    }

    /**
     * @param  list<string>|mixed  $origins
     * @return list<string>
     */
    private function normalizeOrigins(mixed $origins): array
    {
        if (! is_array($origins)) {
            return [];
        }
        $out = [];
        foreach ($origins as $origin) {
            $value = strtolower(trim((string) $origin));
            if ($value !== '') {
                $out[] = $value;
            }
        }

        return array_values(array_unique($out));
    }

    private function normalizeBudget(mixed $budget): int
    {
        $value = (int) $budget;

        return $value > 0 ? $value : self::DEFAULT_BUDGET;
    }

    private function clampRelevance(mixed $relevance): float
    {
        $value = (float) $relevance;
        if ($value < 0.0) {
            return 0.0;
        }
        if ($value > 1.0) {
            return 1.0;
        }

        return $value;
    }
}
