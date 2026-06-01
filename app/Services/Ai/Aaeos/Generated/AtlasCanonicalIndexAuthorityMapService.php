<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas AI Canonical Authority Map decider.
 *
 * Pure, deterministic runtime for the canonical-index authority map. The doc is
 * a subject-to-document authority registry whose single hard decision (doc
 * frontmatter `decisions`) is: "Subject authority must be explicit to prevent
 * duplicate docs and duplicate flows." This service turns that decoration into
 * an enforceable lookup so an agent cannot silently invent a second owner for a
 * subject that already has one.
 *
 * Two documented mechanisms are modelled and never lie:
 *
 *  1. The authority table (doc body | Subject | Authority |): each subject maps
 *     to one authoritative document set. `authorityFor` resolves a subject to
 *     its owner doc(s); an unmapped subject is a GAP, not a guess — the map
 *     never fabricates an owner, because a guessed owner is exactly the
 *     duplicate authority surface the doc exists to prevent.
 *
 *  2. The Rule (doc "## Rule"): "If the subject is not here, find the closest
 *     owner README/doc before creating a new authority surface." `resolveSubject`
 *     applies this: a known subject returns its owner and authorizes nothing
 *     new; an unknown subject is routed to fallback discovery and is explicitly
 *     NOT cleared to create a new authority surface. `mayCreateAuthoritySurface`
 *     is the single boolean a caller needs to gate a "I will author a new
 *     authority doc for X" action.
 *
 * Non-supersession guarantees (doc body): several rows pin an explicit
 * boundary that a doc must not cross — Genesis "is not an OS and does not
 * supersede Autonomous Holding or Domain Company Runtimes"; the Domain Runtime
 * Creation Gate is a "strict extension ... no parallel registry, manifest,
 * maturity or department authority"; ACOS is the "macro authority over
 * memory/context/RAG/graph/retrieval/embedding/ranking/freshness/compounding/
 * learning" and the listed memory docs are its subsystems. `checkSupersession`
 * makes those boundaries queryable so a claim that a constrained doc supersedes
 * its parent authority is reported as a violation with the documented reason.
 *
 * Evidence gate (doc frontmatter `forbidden_changes`): "Declarar runtime,
 * maturidade ou prontidao sem evidencia verificavel e gates verdes." A subject
 * that is not explicitly mapped is treated conservatively (gap, no new surface)
 * and never silently widened into an authority claim.
 *
 * The service NEVER scans the filesystem, parses frontmatter, performs IO,
 * calls a provider, mutates storage or touches the database. The caller passes
 * a subject label or doc identifier; this decider returns the single auditable
 * verdict.
 *
 * @see docs/engineering-knowledge-base/canonical-index/authority-map.md
 */
final class AtlasCanonicalIndexAuthorityMapService
{
    /** Stable schema id for every verdict this service emits. */
    public const SCHEMA_VERSION = 'atlas.aaeos.canonical_index.authority_map.v1';

    /** Resolution outcomes (closed set). */
    public const RESOLUTION_MAPPED = 'mapped';

    public const RESOLUTION_GAP = 'gap';

    /**
     * The doc authority table | Subject | Authority |, order preserved.
     *
     * Each entry pins one subject to its canonical owner document(s). Keys are
     * stable, lowercase, underscore-collapsed slugs of the doc's Subject column;
     * `aliases` carry alternative phrasings a caller might pass; `authority` is
     * the verbatim owner-doc list from the Authority column.
     *
     * @var array<string,array{subject:string,authority:array<int,string>,aliases:array<int,string>}>
     */
    public const AUTHORITY = [
        'thesis_provider_antifragility' => [
            'subject' => 'Thesis/provider antifragility',
            'authority' => ['atlas-ai-thesis-multiplier-channel.md', 'thesis/*.md'],
            'aliases' => ['thesis', 'provider antifragility', 'antifragility'],
        ],
        'session_bootstrap' => [
            'subject' => 'Session bootstrap',
            'authority' => ['atlas-ai-session-bootstrap.md'],
            'aliases' => ['bootstrap', 'session boot'],
        ],
        'documentation_governance' => [
            'subject' => 'Documentation governance',
            'authority' => ['atlas-ai-documentation-operating-system.md'],
            'aliases' => ['doc governance', 'documentation operating system'],
        ],
        'knowledge_governance' => [
            'subject' => 'Knowledge governance',
            'authority' => ['atlas-ai-knowledge-governance-system.md'],
            'aliases' => ['knowledge governance system', 'kb governance'],
        ],
        'runtime_languages' => [
            'subject' => 'Runtime languages',
            'authority' => ['atlas-ai-runtime-language-boundaries.md'],
            'aliases' => ['language boundaries', 'runtime language'],
        ],
        'kernel_contracts' => [
            'subject' => 'Kernel contracts',
            'authority' => ['atlas-ai-kernel-architecture.md'],
            'aliases' => ['kernel', 'kernel architecture'],
        ],
        'master_product_architecture' => [
            'subject' => 'Master product architecture',
            'authority' => ['atlas-ai-master-architecture.md'],
            'aliases' => ['master architecture'],
        ],
        'pipeline_and_topology' => [
            'subject' => 'Pipeline and topology',
            'authority' => ['atlas-ai-pipeline.md', 'atlas-ai-core-vs-domain.md', 'atlas-ai-operating-system.md'],
            'aliases' => ['pipeline', 'topology', 'core vs domain'],
        ],
        'model_selection_and_ap_99' => [
            'subject' => 'Model selection and AP-99',
            'authority' => ['atlas-ai-model-selection-strategy.md', 'telemetry/performance docs', 'AP-146/AP-147'],
            'aliases' => ['model selection', 'ap-99'],
        ],
        'cognition_operating_system' => [
            'subject' => 'Cognition Operating System (ACOS)',
            'authority' => ['atlas-cognition-operating-system.md'],
            'aliases' => ['acos', 'cognition operating system', 'cognition os'],
        ],
        'memory_open_brain' => [
            'subject' => 'Memory/Open Brain (ACOS subsystem)',
            'authority' => ['atlas-ai-memory-context-core-open-brain.md', 'memory/*.md'],
            'aliases' => ['memory', 'open brain', 'memory context core'],
        ],
        'memory_noise_immunity' => [
            'subject' => 'Memory noise immunity, capture quarantine and promotion gates (ACOS subsystem)',
            'authority' => ['memory/cognitive-immune-learning-kernel.md'],
            'aliases' => ['cognitive immune', 'noise immunity', 'promotion gates'],
        ],
        'external_pattern_absorption' => [
            'subject' => 'External pattern absorption roadmap (feeds ACOS)',
            'authority' => ['atlas-external-memory-pattern-absorptions-v1.md'],
            'aliases' => ['external pattern absorption', 'pattern absorption'],
        ],
        'code_intelligence' => [
            'subject' => 'Code Intelligence and external graph candidates',
            'authority' => ['code-intelligence.md', 'code-intelligence/external-graph-harness.md'],
            'aliases' => ['code intelligence', 'external graph'],
        ],
        'atlas_vault_obsidian' => [
            'subject' => 'AtlasVault/Obsidian',
            'authority' => ['obsidian-atlas-vault.md', 'vault/*.md'],
            'aliases' => ['obsidian', 'atlasvault', 'vault'],
        ],
        'mobile' => [
            'subject' => 'Mobile',
            'authority' => ['atlas-ai-mobile-surface-gateway.md'],
            'aliases' => ['mobile surface', 'mobile gateway'],
        ],
        'voice_realtime' => [
            'subject' => 'Voice realtime',
            'authority' => ['atlas-ai-voice-realtime-surface.md'],
            'aliases' => ['voice', 'realtime voice'],
        ],
        'cli_multimodal' => [
            'subject' => 'CLI multimodal',
            'authority' => ['atlas-ai-cli-multimodal.md'],
            'aliases' => ['cli', 'multimodal cli'],
        ],
        'programming' => [
            'subject' => 'Programming',
            'authority' => ['domains/programming.md'],
            'aliases' => ['programming domain'],
        ],
        'self_improvement' => [
            'subject' => 'Self-Improvement',
            'authority' => ['domains/self-improvement.md'],
            'aliases' => ['self improvement'],
        ],
        'finance' => [
            'subject' => 'Finance',
            'authority' => ['domains/finance.md'],
            'aliases' => ['finance domain'],
        ],
        'personal_development' => [
            'subject' => 'Personal Development',
            'authority' => ['domains/personal-development.md'],
            'aliases' => ['personal development'],
        ],
        'cognitive_development_plane' => [
            'subject' => 'Cognitive Development Plane',
            'authority' => ['cognitive/README.md'],
            'aliases' => ['cognitive plane', 'cognitive development'],
        ],
        'business_contexts' => [
            'subject' => 'Business contexts',
            'authority' => ['atlas-ai-business-contexts.md'],
            'aliases' => ['business context'],
        ],
        'scenario_simulation' => [
            'subject' => 'Scenario simulation',
            'authority' => ['atlas-ai-scenario-simulation-harness.md'],
            'aliases' => ['scenario sim', 'simulation harness'],
        ],
        'legacy_resolver_corpus' => [
            'subject' => 'Legacy/resolver corpus',
            'authority' => ['atlas-ai-resolver-corpus-audit.md', 'legacy-documentation-cleanup-report.md'],
            'aliases' => ['resolver corpus', 'legacy corpus'],
        ],
        'holding_to_world_action_hardening' => [
            'subject' => 'Holding To World Action Hardening Initiative',
            'authority' => ['atlas-autonomous-company-os-genesis-initiative.md'],
            'aliases' => ['genesis', 'genesis initiative', 'world action hardening'],
        ],
        'self_directed_evolution_layer' => [
            'subject' => 'Self-Directed Evolution Layer',
            'authority' => ['atlas-self-directed-evolution-layer.md'],
            'aliases' => ['self directed evolution', 'evolution layer'],
        ],
        'aaeos_implementation_reality' => [
            'subject' => 'AAEOS Implementation Reality',
            'authority' => ['atlas-agentic-engineering-os-implementation-reality.md'],
            'aliases' => ['implementation reality', 'aaeos reality'],
        ],
        'aaeos_runtime_gap_matrix' => [
            'subject' => 'AAEOS Runtime Gap Matrix',
            'authority' => ['atlas-agentic-engineering-os-runtime-gap-matrix.md'],
            'aliases' => ['runtime gap matrix', 'gap matrix'],
        ],
        'reality_outcome_gates' => [
            'subject' => 'Reality Outcome Gates',
            'authority' => ['atlas-reality-outcome-gates.md'],
            'aliases' => ['outcome gates', 'reality gates'],
        ],
        'domain_runtime_creation_gate' => [
            'subject' => 'Domain Runtime Creation Gate',
            'authority' => ['atlas-domain-runtime-creation-gate.md'],
            'aliases' => ['domain runtime gate', 'runtime creation gate'],
        ],
        'architecture_evolution_proposal_runtime' => [
            'subject' => 'Architecture Evolution Proposal Runtime',
            'authority' => ['atlas-architecture-evolution-proposal-runtime.md'],
            'aliases' => ['architecture evolution proposal', 'aepr'],
        ],
        'autonomous_software_company_night_shift' => [
            'subject' => 'Autonomous Software Company Night Shift',
            'authority' => ['atlas-autonomous-software-company-night-shift.md'],
            'aliases' => ['night shift'],
        ],
        'software_company_stewardship_stack' => [
            'subject' => 'Atlas Software Company Stewardship Stack',
            'authority' => ['atlas-software-company-stewardship-stack.md'],
            'aliases' => ['stewardship stack'],
        ],
        'night_shift_product_mode' => [
            'subject' => 'Autonomous Software Company Night Shift Product Mode',
            'authority' => ['atlas-autonomous-software-company-night-shift-product-mode.md'],
            'aliases' => ['product mode', 'night shift product mode'],
        ],
        'area_stewardship_layer' => [
            'subject' => 'Atlas Area Stewardship Layer',
            'authority' => ['atlas-area-stewardship-layer.md'],
            'aliases' => ['area stewardship'],
        ],
        'stewardship_evolution_ladder' => [
            'subject' => 'Atlas Stewardship Evolution Ladder',
            'authority' => ['atlas-stewardship-evolution-ladder.md'],
            'aliases' => ['stewardship ladder', 'evolution ladder'],
        ],
    ];

    /**
     * Non-supersession boundaries pinned verbatim by the doc's table rows. A
     * `child` doc/concept must NOT be claimed to supersede / outrank its `parent`
     * authority; the reason is the doc's own wording.
     *
     * @var array<string,array{child:string,parent:string,reason:string}>
     */
    public const SUPERSESSION_BOUNDARIES = [
        'genesis_not_os' => [
            'child' => 'atlas-autonomous-company-os-genesis-initiative.md',
            'parent' => 'Autonomous Holding or Domain Company Runtimes',
            'reason' => 'Genesis is not an OS and does not supersede Autonomous Holding or Domain Company Runtimes.',
        ],
        'domain_runtime_gate_no_parallel' => [
            'child' => 'atlas-domain-runtime-creation-gate.md',
            'parent' => 'existing Domain Routing Governance + Domain Runtime Contract',
            'reason' => 'strict extension; no parallel registry, manifest, maturity or department authority.',
        ],
        'reality_gates_not_promotion_authority' => [
            'child' => 'atlas-reality-outcome-gates.md',
            'parent' => 'local L7->L8 promotion authority',
            'reason' => 'not a local L7->L8 promotion authority.',
        ],
        'memory_under_acos' => [
            'child' => 'atlas-ai-memory-context-core-open-brain.md',
            'parent' => 'atlas-cognition-operating-system.md',
            'reason' => 'Memory/Open Brain is an ACOS subsystem; ACOS holds macro authority over memory/context/RAG/graph/retrieval/embedding/ranking/freshness/compounding/learning.',
        ],
    ];

    /**
     * Resolve a subject to its canonical authority doc(s) (doc authority table).
     * An unmapped subject returns found=false with empty authority — the map
     * never invents an owner, because a guessed owner is exactly the duplicate
     * authority surface the doc exists to prevent.
     *
     * @return array{schema_version:string,query:string,key:string,found:bool,subject:?string,authority:array<int,string>}
     */
    public function authorityFor(string $subject): array
    {
        $key = $this->normalizeKey($subject);
        $row = $this->lookup($key);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'query' => trim($subject),
            'key' => $key,
            'found' => $row !== null,
            'subject' => $row['subject'] ?? null,
            'authority' => $row['authority'] ?? [],
        ];
    }

    /**
     * Apply the doc "## Rule": "If the subject is not here, find the closest
     * owner README/doc before creating a new authority surface."
     *
     * A mapped subject resolves to its existing owner and authorizes nothing new
     * (the owner already exists). An unmapped subject is a gap: the caller is
     * NOT cleared to create a new authority surface and is routed to fallback
     * discovery first. `may_create_authority_surface` is therefore false in BOTH
     * cases — the doc never lets a lookup mint a brand-new authority surface; a
     * known subject already has one, an unknown subject must first find the
     * closest owner. The single legitimate "create" path is an explicit human
     * curation step outside this lookup.
     *
     * @return array{schema_version:string,query:string,key:string,resolution:string,found:bool,authority:array<int,string>,may_create_authority_surface:bool,next_action:string}
     */
    public function resolveSubject(string $subject): array
    {
        $base = $this->authorityFor($subject);
        $found = (bool) $base['found'];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'query' => $base['query'],
            'key' => $base['key'],
            'resolution' => $found ? self::RESOLUTION_MAPPED : self::RESOLUTION_GAP,
            'found' => $found,
            'authority' => $base['authority'],
            // The doc forbids minting a new authority surface from a bare lookup:
            // a mapped subject already owns one; an unmapped subject must first
            // find the closest owner README/doc.
            'may_create_authority_surface' => false,
            'next_action' => $found
                ? 'use_existing_authority'
                : 'find_closest_owner_readme_before_creating_new_authority_surface',
        ];
    }

    /**
     * Check a "child supersedes parent" claim against the doc's pinned
     * non-supersession boundaries.
     *
     * If the child is one of the documented constrained docs and the caller
     * claims it supersedes (outranks / replaces) its parent authority, that is a
     * violation and the doc's own reason is returned. Any other pairing is
     * allowed=true (this decider only enforces the boundaries the doc states; it
     * does not invent new ones).
     *
     * @return array{schema_version:string,child:string,claims_supersession:bool,allowed:bool,boundary:?string,parent:?string,reason:?string}
     */
    public function checkSupersession(string $child, bool $claimsSupersession): array
    {
        $key = $this->normalizeKey($child);

        foreach (self::SUPERSESSION_BOUNDARIES as $boundaryKey => $boundary) {
            if ($this->normalizeKey($boundary['child']) === $key) {
                $allowed = ! $claimsSupersession;

                return [
                    'schema_version' => self::SCHEMA_VERSION,
                    'child' => $boundary['child'],
                    'claims_supersession' => $claimsSupersession,
                    'allowed' => $allowed,
                    'boundary' => $boundaryKey,
                    'parent' => $boundary['parent'],
                    'reason' => $allowed ? null : $boundary['reason'],
                ];
            }
        }

        // No pinned boundary for this child: the doc states no constraint.
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'child' => trim($child),
            'claims_supersession' => $claimsSupersession,
            'allowed' => true,
            'boundary' => null,
            'parent' => null,
            'reason' => null,
        ];
    }

    /**
     * Full authority-map snapshot (doc authority table) for the CLI / read model.
     *
     * @return array{schema_version:string,subject_count:int,authority_doc_count:int,boundary_count:int,subjects:array<int,array{subject:string,authority:array<int,string>}>}
     */
    public function map(): array
    {
        $docs = [];
        $subjects = [];
        foreach (self::AUTHORITY as $row) {
            $subjects[] = ['subject' => $row['subject'], 'authority' => $row['authority']];
            foreach ($row['authority'] as $doc) {
                $docs[$doc] = true;
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'subject_count' => count(self::AUTHORITY),
            'authority_doc_count' => count($docs),
            'boundary_count' => count(self::SUPERSESSION_BOUNDARIES),
            'subjects' => $subjects,
        ];
    }

    /**
     * Resolve a normalized key against the table: exact key first, then aliases.
     *
     * @return array{subject:string,authority:array<int,string>,aliases:array<int,string>}|null
     */
    private function lookup(string $key): ?array
    {
        if ($key === '') {
            return null;
        }

        if (isset(self::AUTHORITY[$key])) {
            return self::AUTHORITY[$key];
        }

        foreach (self::AUTHORITY as $row) {
            foreach ($row['aliases'] as $alias) {
                if ($this->normalizeKey($alias) === $key) {
                    return $row;
                }
            }

            // A caller may pass an owner-doc filename; resolve it to the subject
            // it owns. Glob entries ("memory/*.md") are skipped — a wildcard is
            // not a concrete doc the caller can hold.
            foreach ($row['authority'] as $doc) {
                if (str_contains($doc, '*')) {
                    continue;
                }
                if ($this->normalizeKey($doc) === $key) {
                    return $row;
                }
            }
        }

        return null;
    }

    /**
     * Normalize a free-form subject / doc label to a stable key: lowercase, any
     * run of non-alphanumeric characters collapsed to a single underscore,
     * trimmed. The ".md" extension is dropped first so a doc basename and its
     * subject phrasing normalize comparably.
     */
    private function normalizeKey(string $value): string
    {
        $lower = strtolower(trim($value));
        $lower = (string) preg_replace('/\.md$/', '', $lower);
        $underscored = (string) preg_replace('/[^a-z0-9]+/', '_', $lower);

        return trim($underscored, '_');
    }
}
