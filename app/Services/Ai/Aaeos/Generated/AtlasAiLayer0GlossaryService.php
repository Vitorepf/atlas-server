<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Deterministic runtime for the Atlas AI Layer 0 Constitution and Glossary.
 *
 * The doc is the lean canonical Layer 0 constitution: it fixes identity,
 * language and the rules for promoting human/legacy material so that legacy
 * docs, prompts or providers can never dispute the operational identity of
 * Atlas AI. This service turns that fixed text into pure, testable decision
 * logic. It never touches the filesystem, git, a provider or the database; it
 * consumes already-gathered facts and emits verdicts + reasons.
 *
 * Concrete contract grounded in the doc:
 *
 *   1. Authority table ("## Autoridade"): each governance subject has exactly
 *      one canonical authority doc. resolveAuthority() routes a subject to its
 *      authority; identity/terms route to THIS doc, executable contracts route
 *      to the Kernel, etc.
 *
 *   2. Operational Constitution ("## Constituicao Operacional"): 7 numbered,
 *      load-bearing invariants (Atlas AI is continuity not a provider; surface
 *      is not core; context is compiled not dumped; quality before autonomy;
 *      skills/flows govern agents; memory is not raw history; an old phase is
 *      not present truth). evaluateConstitution() checks a proposed claim/op
 *      against every invariant and BLOCKS on any violation.
 *
 *   3. Canonical glossary ("## Glossario Canonico"): 15 terms, each with a
 *      canonical meaning and an explicit "do not confuse with" list.
 *      classifyTerm() returns the canonical record; detectConflation() catches
 *      using a term as one of its forbidden confusions (e.g. treating "Atlas
 *      AI" as a provider, or a "Surface" as the Kernel).
 *
 *   4. Legacy terms ("## Termos Legados"): 4 legacy terms, each with a
 *      documented treatment. classifyLegacyTerm() routes a legacy term to its
 *      treatment (e.g. "CLAUDE.md / AGENTS.md" => provider projection, never a
 *      primary source).
 *
 *   5. Source-material promotion rules ("## Regras De Promocao De Source
 *      Material"): only stable, small, provider-safe decisions may be promoted;
 *      personal/sensitive/aspirational/historical content must be redacted
 *      first; source material must be declared; the canonical index/README/
 *      START_HERE must be updated when authority changes.
 *      evaluatePromotion() is a fail-closed gate over those rules.
 *
 * Every method returns a strict typed array shape. Pure functions only.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-layer-0-glossary.md
 */
final class AtlasAiLayer0GlossaryService
{
    /** Stable receipt schema id for the verdicts this service emits. */
    public const SCHEMA = 'atlas.aaeos.layer0_glossary.v1';

    /** Verdicts (closed set). */
    public const VERDICT_ALLOW = 'allow';
    public const VERDICT_BLOCK = 'block';

    /**
     * The Authority table: canonicalized governance subject -> the canonical
     * authority doc that owns it. Identity/terms are owned by THIS Layer 0 doc;
     * executable contracts by the Kernel; product/roadmap by Master
     * Architecture; memory/context by Memory Core + Open Brain; the human vault
     * by the AtlasVault doc.
     *
     * @var array<string,string>
     */
    private const AUTHORITY = [
        'identity and canonical terms' => 'atlas-ai-layer-0-glossary.md + atlas-ai-canonical-architecture-index.md',
        'executable contracts envelopes receipts ledger slos' => 'atlas-ai-kernel-architecture.md',
        'product plans domains surfaces roadmap' => 'atlas-ai-master-architecture.md',
        'open brain memory and context' => 'atlas-ai-memory-context-core-open-brain.md + open-brain-context-injection.md',
        'atlasvault obsidian' => 'obsidian-atlas-vault.md',
    ];

    /**
     * The 7 numbered Operational Constitution invariants. Order matches the doc.
     * Each entry: stable id, a one-line summary, and the input flag the caller
     * must set true to assert the proposed claim/op respects that invariant.
     * The gate is fail-closed: an unspecified flag counts as a violation.
     *
     * @var list<array{id:string,number:int,summary:string,flag:string}>
     */
    private const CONSTITUTION = [
        ['id' => 'continuity_not_provider', 'number' => 1, 'flag' => 'treats_atlas_as_continuity_not_provider', 'summary' => 'Atlas AI is the persistent continuity layer, not a provider; Claude/Codex/GPT/Gemini/local/future models are engines.'],
        ['id' => 'surface_not_core', 'number' => 2, 'flag' => 'keeps_identity_in_core_not_surface', 'summary' => 'Surface is not core; app/CLI/TUI/mobile/API/MCP/automation/voice may change, identity lives in Kernel/Master/Memory/Ledger/canonical docs.'],
        ['id' => 'context_compiled_not_dumped', 'number' => 3, 'flag' => 'context_compiled_provider_safe_traceable', 'summary' => 'Context is compiled not dumped; Open Brain builds the smallest sufficient provider-safe traceable reversible context, no raw note/vault/prompt/chat reaches the provider.'],
        ['id' => 'quality_before_autonomy', 'number' => 4, 'flag' => 'autonomy_increase_has_evidence_gates_rollback', 'summary' => 'Quality before autonomy; raising permission requires evidence, gates, trace, reversibility, safety boundary and a rollback path; workflow before open autonomy.'],
        ['id' => 'skills_flows_govern_agents', 'number' => 5, 'flag' => 'behavior_defined_by_skill_flow_policy_receipt', 'summary' => 'Skills and flows govern agents; an agent is a temporary operational role, skill/domain/flow/policy/receipt define behavior before any executor acts.'],
        ['id' => 'memory_not_raw_history', 'number' => 6, 'flag' => 'memory_has_source_scope_validity_privacy_forgetting', 'summary' => 'Memory is not raw history; operational memory needs source, scope, validity, privacy class, confidence, provider-safety and a forgetting path.'],
        ['id' => 'old_phase_not_present_truth', 'number' => 7, 'flag' => 'legacy_marked_before_guiding_implementation', 'summary' => 'An old phase is not present truth; legacy material must be marked source material / merge pending / human vault only / archived before it guides current implementation.'],
    ];

    /**
     * The Canonical glossary: canonicalized term -> {canonical meaning,
     * confusions}. Each "confusions" entry is a canonicalized concept the term
     * must NOT be conflated with ("Nao confundir com").
     *
     * @var array<string,array{display:string,meaning:string,confusions:list<string>}>
     */
    private const GLOSSARY = [
        'atlas ai' => ['display' => 'Atlas AI', 'meaning' => 'Persistent, model-agnostic, multi-surface cognitive core of Atlas.', 'confusions' => ['provider', 'app', 'chat', 'cli', 'agent']],
        'provider' => ['display' => 'Provider', 'meaning' => 'A substitutable engine called by Atlas.', 'confusions' => ['atlas ai identity']],
        'surface' => ['display' => 'Surface', 'meaning' => 'A contact point: app, CLI, mobile, API, MCP, automation, future voice.', 'confusions' => ['domain', 'kernel']],
        'kernel' => ['display' => 'Kernel', 'meaning' => 'Executable contracts: envelope, receipt, ledger, manifests, SDKs, tests, SLOs.', 'confusions' => ['product roadmap']],
        'master architecture' => ['display' => 'Master Architecture', 'meaning' => 'Product architecture: plans, domains, surfaces, learning, strategy and maturity.', 'confusions' => ['specific db/api contract']],
        'domain' => ['display' => 'Domain', 'meaning' => 'A governed specialization of behavior and policy.', 'confusions' => ['screen', 'command', 'provider']],
        'flow' => ['display' => 'Flow', 'meaning' => 'An operational slice within a domain.', 'confusions' => ['loose prompt']],
        'profile' => ['display' => 'Profile', 'meaning' => 'Configuration derived from domain/flow/surface/policy for one operation.', 'confusions' => ['person/agent']],
        'operation envelope' => ['display' => 'Operation Envelope', 'meaning' => 'A typed envelope that bounds one Kernel operation.', 'confusions' => ['loose trace']],
        'decision receipt' => ['display' => 'Decision Receipt', 'meaning' => 'Record of the operational decision: policy, provider, budgets, evidence and fallback.', 'confusions' => ['model text answer']],
        'evidence ledger' => ['display' => 'Evidence Ledger', 'meaning' => 'Append-only stream of auditable runtime events.', 'confusions' => ['manual final report']],
        'context pack' => ['display' => 'Context Pack', 'meaning' => 'Context chosen, summarized and audited for one task.', 'confusions' => ['history dump']],
        'atlasvault' => ['display' => 'AtlasVault / Obsidian', 'meaning' => 'Human Knowledge Surface / Personal Knowledge Workspace.', 'confusions' => ['primary operational source']],
        'skill' => ['display' => 'Skill', 'meaning' => 'Lens/policy/contract of specialized behavior.', 'confusions' => ['agent', 'prompt']],
        'agent' => ['display' => 'Agent', 'meaning' => 'A temporary operational role used by a flow.', 'confusions' => ['independent product']],
        'atlas tool runtime' => ['display' => 'Atlas Tool Runtime', 'meaning' => 'Governed execution of tools, shell, files, git and tests.', 'confusions' => ['free provider tool use']],
    ];

    /**
     * The Legacy terms table: canonicalized legacy term -> documented treatment.
     *
     * @var array<string,string>
     */
    private const LEGACY_TERMS = [
        'atlas harness' => 'Acceptable informal alias; in canonical docs prefer "Atlas AI Harness" or "runtime" per context.',
        'atlas ai orchestrator' => 'Avoid as a separate layer; usually means Task Orchestrator or Domain Orchestrator.',
        'documento mestre' => 'Human/constitutional source material; does not replace the canonical KB.',
        'claude.md' => 'Provider/agent projection; never a primary source.',
        'agents.md' => 'Provider/agent projection; never a primary source.',
    ];

    /**
     * Source-material promotion rules as an ordered, fail-closed gate. Order is
     * load-bearing: a promotion may only proceed when every rule holds, and the
     * gate reports the FIRST unmet rule.
     *
     * Each entry: input flag the caller must set true -> stable rule key.
     *
     * @var list<array{flag:string,rule:string,reason:string}>
     */
    private const PROMOTION_RULES = [
        ['flag' => 'decision_stable_small', 'rule' => 'promote_only_stable_small_decisions', 'reason' => 'promote only stable, small decisions (not whole legacy master docs)'],
        ['flag' => 'provider_safe', 'rule' => 'must_be_provider_safe', 'reason' => 'the promoted excerpt must be provider-safe'],
        ['flag' => 'sensitive_content_redacted', 'rule' => 'redact_personal_sensitive_aspirational_historical', 'reason' => 'redact personal, sensitive, aspirational or historical content before it becomes an operational contract'],
        ['flag' => 'source_material_declared', 'rule' => 'declare_source_material', 'reason' => 'declare the source material inside the promoted doc'],
        ['flag' => 'legacy_preserved_with_redirect', 'rule' => 'preserve_legacy_with_redirect', 'reason' => 'preserve the legacy with a redirect instead of deleting it'],
        ['flag' => 'index_readme_starthere_updated', 'rule' => 'update_index_readme_starthere', 'reason' => 'update the canonical architecture index, README and START_HERE when the promotion changes authority'],
    ];

    /**
     * Resolve the canonical authority doc for a governance subject.
     * Returns null for a subject not in the Authority table.
     */
    public function authorityFor(string $subject): ?string
    {
        return self::AUTHORITY[$this->canonicalize($subject)] ?? null;
    }

    /**
     * Classify a canonical glossary term: return its canonical meaning plus the
     * concepts it must not be confused with. Unknown terms are flagged.
     *
     * @return array{
     *     schema_version:string,
     *     term:string,
     *     known:bool,
     *     canonical:?string,
     *     meaning:?string,
     *     do_not_confuse_with:list<string>
     * }
     */
    public function classifyTerm(string $term): array
    {
        $key = $this->canonicalize($term);
        $record = self::GLOSSARY[$key] ?? null;

        return [
            'schema_version' => self::SCHEMA,
            'term' => trim($term),
            'known' => $record !== null,
            'canonical' => $record['display'] ?? null,
            'meaning' => $record['meaning'] ?? null,
            'do_not_confuse_with' => $record['confusions'] ?? [],
        ];
    }

    /**
     * Detect a glossary conflation: someone is using a canonical term AS one of
     * the concepts the doc says it must not be confused with (e.g. "Atlas AI"
     * used as a "provider"). This is the core Layer 0 guard — the doc exists to
     * stop providers/legacy docs from disputing identity.
     *
     * @return array{
     *     schema_version:string,
     *     term:string,
     *     used_as:string,
     *     known_term:bool,
     *     is_conflation:bool,
     *     verdict:string,
     *     reasons:list<string>
     * }
     */
    public function detectConflation(string $term, string $usedAs): array
    {
        $key = $this->canonicalize($term);
        $usedAsKey = $this->canonicalize($usedAs);
        $record = self::GLOSSARY[$key] ?? null;
        $known = $record !== null;

        $isConflation = false;
        $reasons = [];

        if (! $known) {
            $reasons[] = 'unknown_term: not in the canonical glossary — cannot assert a documented conflation';
        } elseif (in_array($usedAsKey, $record['confusions'], true)) {
            $isConflation = true;
            $reasons[] = sprintf(
                'forbidden_conflation: "%s" must not be confused with "%s" — keep the canonical meaning: %s',
                $record['display'],
                $usedAs,
                $record['meaning'],
            );
        } else {
            $reasons[] = 'no_conflation: usage does not match any documented "do not confuse with" entry for this term';
        }

        return [
            'schema_version' => self::SCHEMA,
            'term' => trim($term),
            'used_as' => trim($usedAs),
            'known_term' => $known,
            'is_conflation' => $isConflation,
            'verdict' => $isConflation ? self::VERDICT_BLOCK : self::VERDICT_ALLOW,
            'reasons' => $reasons,
        ];
    }

    /**
     * Classify a legacy term and return its documented treatment.
     *
     * @return array{
     *     schema_version:string,
     *     term:string,
     *     is_legacy:bool,
     *     treatment:?string,
     *     is_primary_source:bool
     * }
     */
    public function classifyLegacyTerm(string $term): array
    {
        $key = $this->canonicalize($term);
        $treatment = self::LEGACY_TERMS[$key] ?? null;

        return [
            'schema_version' => self::SCHEMA,
            'term' => trim($term),
            'is_legacy' => $treatment !== null,
            'treatment' => $treatment,
            // No legacy term is ever a primary operational source per the doc.
            'is_primary_source' => false,
        ];
    }

    /**
     * Evaluate a proposed claim/operation against ALL 7 Operational Constitution
     * invariants. Fail-closed: any invariant whose flag is not asserted true is
     * a violation, and the operation is BLOCKED while any violation stands.
     *
     * @param  array<string,bool>  $claim flag => bool (any subset)
     * @return array{
     *     schema_version:string,
     *     verdict:string,
     *     compliant:bool,
     *     satisfied:list<string>,
     *     violations:list<array{id:string,number:int,summary:string}>,
     *     reasons:list<string>
     * }
     */
    public function evaluateConstitution(array $claim): array
    {
        $satisfied = [];
        $violations = [];
        $reasons = [];

        foreach (self::CONSTITUTION as $invariant) {
            $ok = ($claim[$invariant['flag']] ?? false) === true;
            if ($ok) {
                $satisfied[] = $invariant['id'];

                continue;
            }

            $violations[] = [
                'id' => $invariant['id'],
                'number' => $invariant['number'],
                'summary' => $invariant['summary'],
            ];
            $reasons[] = sprintf('constitution_%d_violated:%s', $invariant['number'], $invariant['id']);
        }

        $compliant = $violations === [];

        return [
            'schema_version' => self::SCHEMA,
            'verdict' => $compliant ? self::VERDICT_ALLOW : self::VERDICT_BLOCK,
            'compliant' => $compliant,
            'satisfied' => $satisfied,
            'violations' => $violations,
            'reasons' => $reasons === [] ? ['constitution_all_invariants_satisfied'] : $reasons,
        ];
    }

    /**
     * Evaluate a request to promote source material into operational authority,
     * applying the documented promotion rules as an ordered, fail-closed gate.
     * It walks the rules in document order and BLOCKS at the first unmet rule,
     * naming it. Only when all rules hold is the promotion allowed.
     *
     * @param  array<string,bool>  $request rule flags (any subset)
     * @return array{
     *     schema_version:string,
     *     verdict:string,
     *     allowed:bool,
     *     completed_rules:list<string>,
     *     blocking_rule:?string,
     *     pending_rules:list<string>,
     *     reasons:list<string>
     * }
     */
    public function evaluatePromotion(array $request): array
    {
        $completed = [];
        $pending = [];
        $blockingRule = null;
        $reasons = [];

        foreach (self::PROMOTION_RULES as $rule) {
            $satisfied = ($request[$rule['flag']] ?? false) === true;

            if ($blockingRule !== null) {
                $pending[] = $rule['rule'];

                continue;
            }

            if ($satisfied) {
                $completed[] = $rule['rule'];

                continue;
            }

            $blockingRule = $rule['rule'];
            $pending[] = $rule['rule'];
            $reasons[] = $rule['rule'] . ': ' . $rule['reason'];
        }

        $allowed = $blockingRule === null;

        return [
            'schema_version' => self::SCHEMA,
            'verdict' => $allowed ? self::VERDICT_ALLOW : self::VERDICT_BLOCK,
            'allowed' => $allowed,
            'completed_rules' => $completed,
            'blocking_rule' => $blockingRule,
            'pending_rules' => $pending,
            'reasons' => $allowed ? ['promotion_gate_passed: all source-material promotion rules satisfied'] : $reasons,
        ];
    }

    /**
     * The full Layer 0 constitution + glossary as canonical reference data: the
     * authority table, the 7 numbered invariants, the glossary, the legacy
     * terms and the promotion rules.
     *
     * @return array{
     *     schema_version:string,
     *     authority:list<array{subject:string,authority:string}>,
     *     constitution:list<array{id:string,number:int,summary:string}>,
     *     glossary_term_count:int,
     *     glossary:list<array{term:string,meaning:string,do_not_confuse_with:list<string>}>,
     *     legacy_terms:list<array{term:string,treatment:string}>,
     *     promotion_rules:list<string>
     * }
     */
    public function registry(): array
    {
        $authority = [];
        foreach (self::AUTHORITY as $subject => $owner) {
            $authority[] = ['subject' => $subject, 'authority' => $owner];
        }

        $constitution = [];
        foreach (self::CONSTITUTION as $invariant) {
            $constitution[] = [
                'id' => $invariant['id'],
                'number' => $invariant['number'],
                'summary' => $invariant['summary'],
            ];
        }

        $glossary = [];
        foreach (self::GLOSSARY as $record) {
            $glossary[] = [
                'term' => $record['display'],
                'meaning' => $record['meaning'],
                'do_not_confuse_with' => $record['confusions'],
            ];
        }

        $legacyTerms = [];
        foreach (self::LEGACY_TERMS as $term => $treatment) {
            $legacyTerms[] = ['term' => $term, 'treatment' => $treatment];
        }

        $rules = array_map(static fn (array $r): string => $r['rule'], self::PROMOTION_RULES);

        return [
            'schema_version' => self::SCHEMA,
            'authority' => $authority,
            'constitution' => $constitution,
            'glossary_term_count' => count($glossary),
            'glossary' => $glossary,
            'legacy_terms' => $legacyTerms,
            'promotion_rules' => $rules,
        ];
    }

    private function canonicalize(string $value): string
    {
        $collapsed = preg_replace('/\s+/', ' ', $value) ?? $value;

        return strtolower(trim($collapsed));
    }
}
