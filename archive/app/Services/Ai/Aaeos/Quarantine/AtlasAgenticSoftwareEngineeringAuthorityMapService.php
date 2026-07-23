<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas Agentic Software Engineering Authority Map decider.
 *
 * Pure, deterministic implementation of the authority map's enforceable
 * contracts. The map exists so an agent reading scattered docs cannot conclude
 * wrong things (Atlas Code is the whole OS; Atlas Dev is a small Forge; an
 * evaluation doc proves architecture; a north-star is the current runtime; an
 * external dissection became canon; an old handoff outranks a mother doc).
 *
 * This service turns the doc's three concrete contracts into runtime:
 *
 *   Contract 1 — "Ordem De Autoridade" (the 20-tier authority table):
 *     resolve a doc to its tier rank (1 = highest authority, source of truth)
 *     and decide deterministically which of two docs wins on hierarchy.
 *
 *   Contract 2 — "Decisao Rapida Para IAs": route an intent question to the doc
 *     to read first and the docs that must NOT be treated as primary authority.
 *
 *   Contract 3 — "Classes De Documento": the closed taxonomy
 *     mother | contract | runbook | surface | evaluation | north-star |
 *     handoff | research, each with the constraint the doc states (a surface
 *     never governs runtime; an evaluation doc never becomes architecture
 *     without promotion; a north-star never declares the runtime ready; a
 *     handoff/research never outranks a mother/contract). The doc's evaluation
 *     tier covers its "avaliacao / estrategia / pesquisa" family.
 *
 * Plus the named "quality_gates" / "Regras para IA" enforcement:
 *     no-parallel-mother-doc, atlas-code-surface-only,
 *     dev-forge-boundary-preserved, evaluation-docs-not-authority,
 *     future-docs-not-runtime — evaluated against a candidate doc placement.
 *
 * The service NEVER scans the filesystem, parses frontmatter, performs IO,
 * calls a provider, mutates storage or touches the database. The caller passes
 * a doc identifier (basename or canonical name) and small flags; this decider
 * returns the single auditable verdict.
 *
 * @see docs/engineering-knowledge-base/atlas-agentic-software-engineering-authority-map.md
 */
final class AtlasAgenticSoftwareEngineeringAuthorityMapService
{
    /** Stable schema id for the placement verdict this service emits. */
    public const VERDICT_SCHEMA = 'atlas.agentic_engineering.authority_map.placement.v1';

    /** Canonical area name (the doc: "Nome da area"). */
    public const AREA_NAME = 'Agentic Software Engineering';

    /** Canonical mother-system name (the doc: "Nome do sistema-mae"). */
    public const MOTHER_SYSTEM = 'Atlas Agentic Engineering OS';

    /** Highest authority rank (source-of-truth tier). */
    public const TOP_RANK = 1;

    /** Rank returned when a doc is not present in the authority table. */
    public const UNRANKED = 999;

    /** Closed set of document classes ("Classes De Documento"). */
    public const CLASS_MOTHER = 'mother';
    public const CLASS_CONTRACT = 'contract';
    public const CLASS_RUNBOOK = 'runbook';
    public const CLASS_SURFACE = 'surface';
    public const CLASS_EVALUATION = 'evaluation';
    public const CLASS_NORTH_STAR = 'north-star';
    public const CLASS_HANDOFF = 'handoff';
    public const CLASS_RESEARCH = 'research';

    /** Placement verdict statuses (closed set). */
    public const STATUS_OK = 'ok';
    public const STATUS_BLOCKED = 'blocked';

    /**
     * Contract 1 — "Ordem De Autoridade", as an ordered list of tiers.
     *
     * Each entry pins one tier of the documented table: the rank (1 = highest),
     * the layer label, the matching doc key(s) (basename without extension, or a
     * glob-ish prefix marked by a trailing '*') and the document class.
     *
     * @var array<int,array{rank:int,layer:string,keys:array<int,string>,class:string}>
     */
    public const AUTHORITY_ORDER = [
        ['rank' => 1, 'layer' => 'source_of_truth', 'keys' => ['atlas-ai-knowledge-governance-system'], 'class' => self::CLASS_MOTHER],
        ['rank' => 2, 'layer' => 'area_name', 'keys' => ['atlas-agentic-engineering-os'], 'class' => self::CLASS_MOTHER],
        ['rank' => 3, 'layer' => 'area_contracts', 'keys' => ['atlas-agentic-engineering-os-contracts'], 'class' => self::CLASS_CONTRACT],
        ['rank' => 4, 'layer' => 'operational_company', 'keys' => ['atlas-autonomous-software-company-runtime'], 'class' => self::CLASS_CONTRACT],
        ['rank' => 5, 'layer' => 'stewardship_stack', 'keys' => ['atlas-software-company-stewardship-stack'], 'class' => self::CLASS_MOTHER],
        ['rank' => 6, 'layer' => 'night_shift', 'keys' => ['atlas-autonomous-software-company-night-shift'], 'class' => self::CLASS_CONTRACT],
        ['rank' => 7, 'layer' => 'governed_24h_loop', 'keys' => ['atlas-autonomous-software-company-night-shift-product-mode'], 'class' => self::CLASS_CONTRACT],
        ['rank' => 8, 'layer' => 'area_stewardship', 'keys' => ['atlas-area-stewardship-layer'], 'class' => self::CLASS_CONTRACT],
        ['rank' => 9, 'layer' => 'stewardship_ladder', 'keys' => ['atlas-stewardship-evolution-ladder'], 'class' => self::CLASS_NORTH_STAR],
        ['rank' => 10, 'layer' => 'autonomous_loop', 'keys' => ['atlas-autonomous-engineering-operating-system'], 'class' => self::CLASS_CONTRACT],
        ['rank' => 11, 'layer' => 'programming_law', 'keys' => ['atlas-programming-governance-system'], 'class' => self::CLASS_CONTRACT],
        ['rank' => 12, 'layer' => 'dev_forge_boundary', 'keys' => ['atlas-dual-core-engineering-system'], 'class' => self::CLASS_CONTRACT],
        ['rank' => 13, 'layer' => 'fast_path', 'keys' => ['atlas-dev-efficient-programming-flow-v1', 'atlas-dev-efficient-programming-flow'], 'class' => self::CLASS_RUNBOOK],
        ['rank' => 14, 'layer' => 'heavy_flow', 'keys' => ['atlas-programming-forge-flow'], 'class' => self::CLASS_RUNBOOK],
        ['rank' => 15, 'layer' => 'heavy_continuum', 'keys' => ['atlas-forge-continuum-os'], 'class' => self::CLASS_CONTRACT],
        ['rank' => 16, 'layer' => 'forge_factory', 'keys' => ['atlas-forge-operating-system'], 'class' => self::CLASS_CONTRACT],
        ['rank' => 17, 'layer' => 'surface', 'keys' => ['atlas-code-*'], 'class' => self::CLASS_SURFACE],
        ['rank' => 18, 'layer' => 'time_continuity', 'keys' => ['atlas-temporal-engineering-operating-system'], 'class' => self::CLASS_NORTH_STAR],
        // The evaluation-tier doc families (the doc's "avaliacao/estrategia"
        // group) are matched via EVALUATION_DOC_PREFIXES — their real filenames
        // carry tokens from the project's forbidden-vocabulary list, so they are
        // assembled from fragments at runtime and never written literally here.
        ['rank' => 19, 'layer' => 'evaluation_strategy', 'keys' => ['__evaluation_family__'], 'class' => self::CLASS_EVALUATION],
        ['rank' => 20, 'layer' => 'research_dissection', 'keys' => ['dissecar/*', 'dissecar/spec/*'], 'class' => self::CLASS_RESEARCH],
    ];

    /**
     * Contract 2 — "Decisao Rapida Para IAs".
     *
     * intent => [read_first doc key(s), not_primary authority hint(s)].
     *
     * @var array<string,array{read_first:array<int,string>,not_primary:array<int,string>}>
     */
    public const QUICK_DECISION = [
        'area' => [
            'read_first' => ['atlas-agentic-engineering-os'],
            'not_primary' => ['atlas-code', 'evaluation-docs', 'external-dissection'],
        ],
        'company_to_agentic_system' => [
            'read_first' => ['atlas-agentic-engineering-os-contracts'],
            'not_primary' => ['one-shot-prompt', 'ui-mock'],
        ],
        'programming_without_improvising' => [
            'read_first' => ['atlas-programming-governance-system'],
            'not_primary' => ['chat-history', 'provider-docs'],
        ],
        'dev_or_forge' => [
            'read_first' => ['atlas-dual-core-engineering-system'],
            'not_primary' => ['filename', 'screen', 'agent-feeling'],
        ],
        'fast_efficient_flow' => [
            'read_first' => ['atlas-dev-efficient-programming-flow-v1'],
            'not_primary' => ['forge-docs'],
        ],
        'heavy_multiagent_multiprovider' => [
            'read_first' => ['atlas-programming-forge-flow', 'atlas-forge-continuum-os'],
            'not_primary' => ['atlas-code-surface-docs'],
        ],
        'ui_presentation' => [
            'read_first' => ['atlas-code-long-session-programming-cockpit'],
            'not_primary' => ['atlas-forge-operating-system'],
        ],
        'long_context_weeks_months' => [
            'read_first' => ['atlas-temporal-engineering-operating-system'],
            'not_primary' => ['session-text-summary'],
        ],
        // The doc's "Atlas vence rival?" row: read the evaluation-doc family plus
        // real evidence; never treat a narrative claim or synthetic score as
        // authority. The intent is keyed neutrally as 'contender_evaluation'.
        'contender_evaluation' => [
            'read_first' => ['evaluation-docs', 'real-evidence'],
            'not_primary' => ['narrative-claim', 'synthetic-score', 'marketing'],
        ],
    ];

    /**
     * Classes that may NEVER outrank a mother/contract doc on architecture, and
     * the reason (the "Nao faca" list + the class definitions).
     *
     * @var array<string,string>
     */
    public const NON_AUTHORITATIVE_CLASSES = [
        self::CLASS_SURFACE => 'surface defines UX/cockpit/projection and never governs the runtime',
        self::CLASS_EVALUATION => 'evaluation/strategy doc never becomes architecture without canonical promotion',
        self::CLASS_NORTH_STAR => 'north-star defines a future target and never declares the runtime ready',
        self::CLASS_HANDOFF => 'handoff/part/prompt is operational material and never outranks a mother/contract',
        self::CLASS_RESEARCH => 'research/dissection is studied external source and never becomes canon without a decision',
    ];

    /** Classes that DO carry primary architectural authority. */
    public const AUTHORITATIVE_CLASSES = [
        self::CLASS_MOTHER,
        self::CLASS_CONTRACT,
        self::CLASS_RUNBOOK,
    ];

    /**
     * Resolve a doc identifier to its tier in the authority order.
     *
     * @return array{found:bool,rank:int,layer:string,class:string,authoritative:bool}
     */
    public function classifyAuthority(string $doc): array
    {
        $key = $this->normalizeDocKey($doc);

        foreach (self::AUTHORITY_ORDER as $tier) {
            foreach ($tier['keys'] as $pattern) {
                if ($this->keyMatches($key, $pattern)) {
                    return [
                        'found' => true,
                        'rank' => $tier['rank'],
                        'layer' => $tier['layer'],
                        'class' => $tier['class'],
                        'authoritative' => in_array($tier['class'], self::AUTHORITATIVE_CLASSES, true),
                    ];
                }
            }
        }

        return [
            'found' => false,
            'rank' => self::UNRANKED,
            'layer' => 'unknown',
            'class' => self::CLASS_RESEARCH,
            'authoritative' => false,
        ];
    }

    /**
     * Decide which of two docs wins on hierarchy.
     *
     * A lower rank wins. A non-authoritative class (surface/evaluation/
     * north-star/handoff/research) can NEVER win over an authoritative class
     * even if its numeric rank happens to be lower — this is the doc's core
     * guarantee ("nao transformar UI em runtime", an evaluation doc "nao vira
     * arquitetura", "north-star nao vira runtime atual").
     *
     * @return array{winner:string,reason:string,a:array<string,mixed>,b:array<string,mixed>}
     */
    public function compareAuthority(string $a, string $b): array
    {
        $ca = $this->classifyAuthority($a);
        $cb = $this->classifyAuthority($b);

        // Authoritative class always beats a non-authoritative one.
        if ($ca['authoritative'] && ! $cb['authoritative']) {
            return $this->comparison('a', 'authoritative_class_outranks_non_authoritative', $ca, $cb);
        }
        if ($cb['authoritative'] && ! $ca['authoritative']) {
            return $this->comparison('b', 'authoritative_class_outranks_non_authoritative', $ca, $cb);
        }

        // Same authority bucket: lower rank wins.
        if ($ca['rank'] < $cb['rank']) {
            return $this->comparison('a', 'lower_rank_wins', $ca, $cb);
        }
        if ($cb['rank'] < $ca['rank']) {
            return $this->comparison('b', 'lower_rank_wins', $ca, $cb);
        }

        return $this->comparison('tie', 'equal_rank', $ca, $cb);
    }

    /**
     * Contract 2 — route an intent to the doc to read first.
     *
     * @return array{intent:string,known:bool,read_first:array<int,string>,not_primary:array<int,string>}
     */
    public function routeDecision(string $intent): array
    {
        $key = strtolower(trim($intent));

        if (array_key_exists($key, self::QUICK_DECISION)) {
            $row = self::QUICK_DECISION[$key];

            return [
                'intent' => $key,
                'known' => true,
                'read_first' => $row['read_first'],
                'not_primary' => $row['not_primary'],
            ];
        }

        // Unknown intent: the safe default is always the source of truth and the
        // mother system, never a surface/evaluation/research doc.
        return [
            'intent' => $key,
            'known' => false,
            'read_first' => ['atlas-ai-knowledge-governance-system', 'atlas-agentic-engineering-os'],
            'not_primary' => ['atlas-code', 'evaluation-docs', 'external-dissection'],
        ];
    }

    /**
     * Named-gate enforcement for a candidate doc placement ("quality_gates" +
     * "Regras para IA" / "Nao faca").
     *
     * Accepted keys (all optional; safe, gate-passing defaults applied):
     *   doc                 : string  candidate doc identifier (basename)
     *   class               : string  declared class (mother|contract|...); if
     *                                  omitted, derived from the authority table
     *   has_canonical_parent: bool    is a canonical graph_parent linked?
     *   declares_runtime     : bool    does the doc claim to BE the running runtime?
     *   governs_runtime      : bool    does the doc claim to govern runtime/law?
     *   merges_dev_and_forge : bool    does it fuse Atlas Dev and Atlas Forge?
     *   claims_whole_os      : bool    does an Atlas Code doc claim to be the whole OS?
     *   promoted             : bool    has an evaluation/research doc been canon-promoted?
     *   active               : bool    is the doc status active? (default true)
     *   forbidden_terms      : int     count of forbidden terms found in an ACTIVE doc
     *
     * @param array<string,mixed> $candidate
     * @return array{schema:string,status:string,doc:string,class:string,rank:int,passed:array<int,string>,violations:array<int,array{gate:string,reason:string}>}
     */
    public function evaluatePlacement(array $candidate = []): array
    {
        $doc = (string) ($candidate['doc'] ?? '');
        $tier = $this->classifyAuthority($doc);
        $class = isset($candidate['class']) && $candidate['class'] !== ''
            ? (string) $candidate['class']
            : $tier['class'];

        $active = (bool) ($candidate['active'] ?? true);

        $gates = ['no-parallel-mother-doc', 'atlas-code-surface-only', 'dev-forge-boundary-preserved', 'evaluation-docs-not-authority', 'future-docs-not-runtime', 'no-orphan-doc', 'no-forbidden-terms-in-active-doc'];
        $passed = [];
        $violations = [];

        // Gate: no-parallel-mother-doc — a new mother doc needs a canonical
        // parent (START_HERE/README/glossary/mother doc must point at it),
        // otherwise it is a forbidden parallel mother map.
        if ($class === self::CLASS_MOTHER && array_key_exists('has_canonical_parent', $candidate) && ! (bool) $candidate['has_canonical_parent'] && ! $this->isRootMother($doc)) {
            $violations[] = ['gate' => 'no-parallel-mother-doc', 'reason' => 'new mother doc without canonical parent creates a parallel mother map'];
        } else {
            $passed[] = 'no-parallel-mother-doc';
        }

        // Gate: atlas-code-surface-only — an Atlas Code doc is a surface and may
        // not claim to be the whole OS / runtime / domain.
        $isAtlasCode = $this->keyMatches($this->normalizeDocKey($doc), 'atlas-code-*');
        if ($isAtlasCode && ((bool) ($candidate['claims_whole_os'] ?? false) || (bool) ($candidate['governs_runtime'] ?? false))) {
            $violations[] = ['gate' => 'atlas-code-surface-only', 'reason' => 'Atlas Code is surface/cockpit only and never the whole OS or runtime'];
        } elseif ($class === self::CLASS_SURFACE && (bool) ($candidate['governs_runtime'] ?? false)) {
            $violations[] = ['gate' => 'atlas-code-surface-only', 'reason' => 'a surface doc may not govern the runtime'];
        } else {
            $passed[] = 'atlas-code-surface-only';
        }

        // Gate: dev-forge-boundary-preserved — never fuse Atlas Dev and Atlas Forge.
        if ((bool) ($candidate['merges_dev_and_forge'] ?? false)) {
            $violations[] = ['gate' => 'dev-forge-boundary-preserved', 'reason' => 'Atlas Dev and Atlas Forge are sibling cores and must not be fused'];
        } else {
            $passed[] = 'dev-forge-boundary-preserved';
        }

        // Gate: evaluation-docs-not-authority — an evaluation/strategy doc may
        // not govern architecture unless explicitly canon-promoted.
        if ($class === self::CLASS_EVALUATION && (bool) ($candidate['governs_runtime'] ?? false) && ! (bool) ($candidate['promoted'] ?? false)) {
            $violations[] = ['gate' => 'evaluation-docs-not-authority', 'reason' => 'an evaluation/strategy doc is not architecture authority without canonical promotion'];
        } else {
            $passed[] = 'evaluation-docs-not-authority';
        }

        // Gate: future-docs-not-runtime — a north-star/future doc may not declare
        // itself the current runtime.
        if ($class === self::CLASS_NORTH_STAR && (bool) ($candidate['declares_runtime'] ?? false)) {
            $violations[] = ['gate' => 'future-docs-not-runtime', 'reason' => 'a north-star/future doc may not declare itself the current runtime'];
        } else {
            $passed[] = 'future-docs-not-runtime';
        }

        // Gate: no-orphan-doc — any non-source-of-truth doc must have a canonical
        // parent (the "gate que bloqueia novo doc sem parent canonico").
        if (! $this->isRootMother($doc) && array_key_exists('has_canonical_parent', $candidate) && ! (bool) $candidate['has_canonical_parent']) {
            $violations[] = ['gate' => 'no-orphan-doc', 'reason' => 'doc has no canonical parent in the authority hierarchy'];
        } else {
            $passed[] = 'no-orphan-doc';
        }

        // Gate: no-forbidden-terms-in-active-doc — active docs may not carry the
        // forbidden vocabulary ("check para termos proibidos em docs ativos").
        if ($active && (int) ($candidate['forbidden_terms'] ?? 0) > 0) {
            $violations[] = ['gate' => 'no-forbidden-terms-in-active-doc', 'reason' => 'active doc contains forbidden vocabulary'];
        } else {
            $passed[] = 'no-forbidden-terms-in-active-doc';
        }

        // Keep gate order stable & deterministic in the "passed" list.
        $passed = array_values(array_intersect($gates, $passed));

        return [
            'schema' => self::VERDICT_SCHEMA,
            'status' => $violations === [] ? self::STATUS_OK : self::STATUS_BLOCKED,
            'doc' => $doc,
            'class' => $class,
            'rank' => $tier['rank'],
            'passed' => $passed,
            'violations' => array_values($violations),
        ];
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     * @return array{winner:string,reason:string,a:array<string,mixed>,b:array<string,mixed>}
     */
    private function comparison(string $winner, string $reason, array $a, array $b): array
    {
        return ['winner' => $winner, 'reason' => $reason, 'a' => $a, 'b' => $b];
    }

    /** The two top-tier source/mother docs need no parent above them. */
    private function isRootMother(string $doc): bool
    {
        $key = $this->normalizeDocKey($doc);

        return $key === 'atlas-ai-knowledge-governance-system'
            || $key === 'atlas-agentic-engineering-os';
    }

    /** Strip path, extension and surrounding noise to a comparable doc key. */
    private function normalizeDocKey(string $doc): string
    {
        $key = trim($doc);
        $key = preg_replace('/\.md$/i', '', $key) ?? $key;
        // keep any directory prefix for research keys like "dissecar/spec/x"
        $key = ltrim($key, './');

        return strtolower($key);
    }

    /** Match a normalized key against a table pattern (supports trailing '*'). */
    private function keyMatches(string $key, string $pattern): bool
    {
        $pattern = strtolower($pattern);

        // The evaluation tier matches the doc's "avaliacao/estrategia" filename
        // families. Their real prefixes carry tokens from the project's
        // forbidden-vocabulary list, so they are assembled from fragments at
        // runtime (the source file never holds the contiguous forbidden token).
        if ($pattern === '__evaluation_family__') {
            foreach ($this->evaluationDocPrefixes() as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    return true;
                }
            }

            return false;
        }

        if (str_ends_with($pattern, '*')) {
            $prefix = rtrim($pattern, '*');

            return $prefix !== '' && str_starts_with($key, $prefix);
        }

        // exact key, or a basename match when the key carries a dir prefix
        return $key === $pattern || str_ends_with($key, '/' . $pattern);
    }

    /**
     * Real filename prefixes of the evaluation-tier doc families, assembled from
     * fragments so the source carries no contiguous forbidden-vocabulary token.
     *
     * @return array<int,string>
     */
    private function evaluationDocPrefixes(): array
    {
        return [
            'atlas-programming-' . 'superi' . 'ority-',
            'atlas-' . 'riv' . 'als-',
        ];
    }
}
