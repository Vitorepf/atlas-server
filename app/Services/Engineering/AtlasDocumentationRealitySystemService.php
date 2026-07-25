<?php

declare(strict_types=1);

namespace App\Services\Engineering;

use App\Models\AtlasEngineeringCodeSymbol;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasImplementationEvidenceResolver;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasImplementationTruthService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Semantic\CanonicalDocsFrontmatterParser;
use App\Services\Engineering\DocumentationReality\DocumentationRealityClassifySupport;
use App\Services\Engineering\DocumentationReality\DocumentationRealityEvaluationsSection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

class AtlasDocumentationRealitySystemService
{
    public const SCHEMA_VERSION = 'atlas.documentation_reality_system.v1';

    /**
     * Cache-store key PREFIX under which a computed report is cached CROSS-REQUEST, keyed by the
     * resolved docs root PLUS a stat-only corpus signature, so every caller in every request that
     * sees the SAME docs corpus shares ONE computation. Service-private. A real doc add/edit/delete
     * moves the signature -> a new key -> recompute, so the cache can never serve a stale report.
     */
    private const SHARED_REPORT_KEY = 'atlas.documentation_reality_system.report';

    /**
     * Per-instance memo of the computed report, keyed by resolved docs root @ corpus signature.
     * Fast path for the common single-instance case (no cache-store round-trip); the cross-request
     * cache (SHARED_REPORT_KEY) is what shares the result across the DIFFERENT autowired instances
     * the create path builds AND across separate requests on an unchanged corpus.
     *
     * @var array<string,array<string,mixed>>
     */
    private array $reportMemo = [];

    /**
     * An ADRS block is "integrated runtime" when its backing evaluation actually RAN and
     * produced a verdict — not only the green 'ready'/'review'. The Drift & Duplication
     * Guard (block #11) is now a real doc-vs-code detector that can honestly emit
     * 'drift_detected' (real divergence found) or 'degraded' (index unavailable, verdict
     * withheld). Both mean the guard is materialized and working, so they keep the block
     * integrated; the drift itself surfaces as a top-level blocker, not as a missing block.
     *
     * @var array<int,string>
     */
    private const INTEGRATED_EVALUATION_STATUSES = ['ready', 'review', 'drift_detected', 'degraded'];

    /**
     * Honest execution tiers. The previous report hardcoded a literal ready status in ~32 of the
     * 52 evaluation nodes and rolled that up into a "52/52 integrated / excellent_integrated_runtime"
     * claim. That was an over-claim: most of those nodes return a constant array describing what the
     * block WOULD do — they do not compute their declared verb from real input. The fix tells the
     * truth: every block is classified into exactly one of three execution tiers below, and its
     * 'status'/'execution' are DERIVED from that classification, never written as a literal inside an
     * *Evaluation() method.
     *
     * EXECUTES — the evaluator computes its declared verb from REAL input (real code index /
     * real source registry / real AAEOS ledger) and DERIVES its status from that result. These 11
     * keys are the only ones eligible for L4_integrated.
     *
     * @var array<int,string>
     */
    private const EXECUTING_EVALUATION_KEYS = [
        'source_freshness_gate',
        'evidence_sufficiency_gate',
        'documentation_lifecycle_state_machine',
        'documentation_operating_system',
        'documentation_budget_governor',
        'retrieval_audit_trail',
        'acrui_operational_reality',
        'drift_duplication_guard',
        'evidence_runtime_proof_bridge',
        'auto_split_planner',
        'aurc_visual_reality',
        // Batch A promotions — each now computes its FULL declared verb from the live
        // cross-source authority audit (EngineeringDocumentationAuthorityAuditService::report)
        // and DERIVES its status from that corpus-wide result (flip-proven: mutating the real
        // corpus flips the verdict). 'authority_kernel' backs BOTH block #1 (Documentation
        // Authority Kernel) and block #2 (Canonical Source Registry) via the alias map, so this
        // single key promotes two blocks.
        'authority_kernel',
        'knowledge_governance_system',
        'contradiction_resolver',
        'vocabulary_alignment_guard',
        'orphaned_decision_finder',
        'semantic_deduplication_engine',
        // Batch B promotion — 'canonical_question_router' now computes its FULL declared verb:
        // every CANONICAL_QUESTIONS entry is RESOLVED against the live source registry to its
        // owner doc + cartography node (graph_id) + content-hash evidence, and the status is
        // DERIVED from that resolution (flip-proven: removing/renaming/un-owning a target doc or
        // stripping its graph_id flips a route unroutable and degrades the verdict). It is no
        // longer the owner-presence proxy that kept it PARTIAL.
        'canonical_question_router',
        // Batch C promotions — each now computes its FULL declared verb as a constant-free ROLL-UP
        // of sibling evaluations that themselves derive from the real corpus/ledger/code-index:
        // 'documentation_slo_alerting' compares 6 live SLO metrics (freshness/drift/orphan/context-
        // cost/cartography/coverage) to declared targets; 'owner_escalation_queue' builds a real
        // escalation queue from the orphan/contradiction/lifecycle/quarantine/drift audits. Both
        // DERIVE status (flip-proven: a real corpus defect flips ready->review/blocked).
        'documentation_slo_alerting',
        'owner_escalation_queue',
        // NOTE: visual_completeness_auditor + cartography_cognitive_load_meter were analyzed as
        // completable but are NOT promoted — their verb audits the live cartography map, which is
        // built BY this report() (map -> report), so report() cannot consume it without recursion
        // (boot-cycle). Promoting them would diverge executes from integrated (status 'blocked'),
        // a hollow over-claim. They stay honest declared specs until a non-circular feed exists.
    ];

    /**
     * PARTIAL — the evaluator runs a REAL but narrow/proxy signal over data it actually computes,
     * yet does NOT cover its full declared verb (e.g. owner-presence as a proxy for cross-source
     * authority adjudication; required-source presence as a proxy for a minimal projection; a
     * same-owner overlap histogram rather than true semantic dedup). It runs honestly but is NOT
     * integrated — it lands L3_read_only with a DERIVED status, never a literal. The
     * formerly-hardcoded-ready-over-real-data methods now DERIVE their status from the data they
     * compute; as each one's FULL declared verb landed it graduated to EXECUTING
     * (semantic_deduplication_engine, then canonical_question_router), leaving reality_diff_engine
     * and documentation_entropy_monitor still honest proxy-partials here.
     *
     * @var array<int,string>
     */
    private const PARTIAL_EVALUATION_KEYS = [
        'ai_context_projection',
        'reality_diff_engine',
        'documentation_entropy_monitor',
    ];

    /**
     * @var array<string,string>
     */
    private const CANONICAL_DOCS = [
        'adrs' => 'docs/engineering-knowledge-base/atlas-documentation-reality-system.md',
        'adrs_block_registry' => 'docs/engineering-knowledge-base/atlas-documentation-reality-block-registry.md',
        'adrib' => 'docs/engineering-knowledge-base/atlas-documentation-reality-implementation-blueprint.md',
        'adr_bum' => 'docs/engineering-knowledge-base/atlas-documentation-reality-block-upgrade-map.md',
        'acrui' => 'docs/engineering-knowledge-base/atlas-code-reality-usage-intelligence.md',
        'aurc' => 'docs/engineering-knowledge-base/atlas-universal-reality-cartography.md',
        'documentation_os' => 'docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md',
        'knowledge_governance' => 'docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md',
        'cartography_os' => 'docs/engineering-knowledge-base/atlas-cartographic-knowledge-os.md',
        'system_graph' => 'docs/engineering-knowledge-base/atlas-system-graph.md',
        'implemented_vs_scaffold' => 'docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md',
    ];

    /**
     * The canonical questions the Canonical Question Router resolves. Each is a real
     * operator/agent question whose canonical answer is a single source-registry doc (by id).
     * The router executes its FULL verb by RESOLVING every one against the live registry to its
     * owner doc + cartography node + evidence — never a constant. Every target id below is a
     * CANONICAL_DOCS key that fully resolves (exists + owner + graph_id + content hash).
     *
     * @var array<string,string>
     */
    private const CANONICAL_QUESTIONS = [
        'Qual e a documentacao mae e a fonte canonica de verdade?' => 'adrs',
        'Quais sao os blocos da realidade documental e o estado real de cada um?' => 'adrs_block_registry',
        'Esse codigo esta vivo, scaffold ou duplicado?' => 'acrui',
        'Como o sistema se projeta visualmente para humano e agente?' => 'aurc',
        'O que governa o conhecimento canonico do repositorio?' => 'knowledge_governance',
        'O que esta implementado de verdade versus scaffold?' => 'implemented_vs_scaffold',
    ];

    /**
     * @var array<string,array<int,int>>
     */
    private const PLANES = [
        'truth_authority' => [1, 2, 3, 4, 38, 42, 48, 50],
        'operational_reality' => [5, 11, 12, 14, 16, 19, 20, 21, 24, 27, 33, 34, 35, 37, 39, 40, 41, 45, 47, 49, 51, 52],
        'ai_context_efficiency' => [10, 17, 22, 23, 25, 28, 30, 31, 43, 44],
        'human_cartography' => [6, 7, 8, 9, 18, 26, 29, 32, 36, 46],
        'feedback_learning' => [15, 26, 45, 47, 50],
        'governance_lifecycle' => [13, 43, 44, 48, 49, 50],
    ];

    private readonly DocumentationRealityEvaluationsSection $evaluationsSection;

    public function __construct(
        private readonly CanonicalDocsFrontmatterParser $frontmatter,
        private readonly AtlasCodeRealityUsageIntelligenceService $codeReality,
        private readonly ?AtlasUniversalRealityCartographyService $cartography = null,
        // C3 keystone — the Drift & Duplication Guard composes the REAL AAEOS drift
        // ledger (over-claim drift) and resolves each canonical source doc's declared
        // evidence_refs against the code index (claimed-but-absent drift). Optional so
        // the existing constructor contract is preserved; resolved lazily from the
        // container when absent (and re-resolved in driftDuplicationEvaluation so a test
        // that swaps the binding after construction is honoured).
        private readonly ?AtlasImplementationTruthService $aaeosTruth = null,
        private readonly ?AtlasImplementationEvidenceResolver $evidenceResolver = null,
        // Batch A — the cross-source authority adjudicator. It scans the WHOLE canonical
        // corpus and emits a real conflict verdict (identity/runtime duplicate groups,
        // capability overlaps, owner gaps). Six formerly-partial evaluators now compute their
        // full declared verb from this signal. Light ctor (only the frontmatter parser, already
        // injected here); optional + lazily resolved from the container so the existing ctor
        // contract holds and a test can swap the binding before report() runs.
        private readonly ?EngineeringDocumentationAuthorityAuditService $authorityAudit = null,
        // Self-contained block evaluators + derived scoring, extracted verbatim to the section
        // (GOD-DEBULK split). Container-autowired in the normal path; dependency-free so a test
        // that `new`s this service without one gets a default and the delegating call sites
        // never see null.
        ?DocumentationRealityEvaluationsSection $evaluationsSection = null,
    ) {
        $this->evaluationsSection = $evaluationsSection ?? new DocumentationRealityEvaluationsSection();
    }

    /**
     * The full documentation-reality analysis. Scanning the canonical corpus + resolving the
     * cross-source authority audit + composing the AAEOS drift ledger is the expensive part
     * (~9-10s), and the synchronous create pipeline reaches it on EVERY interaction (the
     * session-bootstrap gate + the feature-placement gate both call it, and one create
     * orchestration calls it multiple times). It is now computed ONCE per docs corpus and reused
     * across requests, not just within one request.
     *
     * The cache key is the resolved docs root PLUS a cheap content signature of that corpus (a
     * single stat-only walk: relative path + mtime + size of every .md file, NEVER reading or
     * parsing them). So identical inputs collapse to ONE computation (every call sees the same
     * signature), while a genuinely DIFFERENT corpus — including a mid-request mutation or a doc
     * added/edited/deleted between two HTTP requests — produces a different key and correctly
     * recomputes, never a stale result. The signature walk is orders of magnitude cheaper than the
     * report it guards (no file reads / no frontmatter parse / no authority audit / no code index),
     * so the reused calls cost milliseconds instead of ~9-10s each.
     *
     * Three reuse tiers, each keyed by the IDENTICAL root@corpusSignature:
     *   L1 — a per-instance memo: a second call on the same instance never re-touches the cache.
     *   L2 — the cross-request cache store (Cache::remember, TTL from
     *        `atlas.engineering.documentation_reality.report_cache_seconds`, default 300s): so even
     *        DIFFERENT autowired instances AND separate requests on an unchanged corpus share the
     *        SAME array — including its single generated_at + certification_hash, byte-for-byte what
     *        one computeReport() produced. The TTL is only a safety net on top of the content-keyed
     *        invalidation; <=0 disables L2 and recomputes (still memoized per instance via L1).
     *   fallback — with no container (a unit test that `new`s the service standalone) it skips L2
     *        and falls back to L1 only — still correct, still compute-once per identical corpus.
     *
     * Caching this read model changes nothing observable: the value is byte-for-byte what
     * computeReport() produces today, the computation is pure read-only (claim_policy.writes=false,
     * providers_invoked=false), and the corpus-signature key preserves the exact staleness
     * guarantee the prior request-scoped memo had (a real corpus change still flips every verdict).
     *
     * @return array<string,mixed>
     */
    public function report(?string $docsRoot = null): array
    {
        $root = $docsRoot ?? base_path('docs/engineering-knowledge-base');
        $cacheKey = $root.'@'.$this->corpusSignature($root);

        if (isset($this->reportMemo[$cacheKey])) {
            return $this->reportMemo[$cacheKey];
        }

        // No container (some unit tests `new` the service standalone): compute + memo per instance.
        if (! function_exists('app') || ! app()->bound('app')) {
            return $this->reportMemo[$cacheKey] = $this->computeReport($root);
        }

        $ttl = (int) config('atlas.engineering.documentation_reality.report_cache_seconds', 300);
        if ($ttl <= 0) {
            return $this->reportMemo[$cacheKey] = $this->computeReport($root);
        }

        return $this->reportMemo[$cacheKey] = Cache::remember(
            self::SHARED_REPORT_KEY.':'.$cacheKey,
            $ttl,
            fn (): array => $this->computeReport($root),
        );
    }

    /**
     * A cheap, stat-only content signature of the docs corpus under $root: a sha256 over each
     * .md file's relative path + mtime + size, in sorted order. It NEVER reads or parses file
     * contents, so it is orders of magnitude cheaper than the report it keys — yet it changes
     * the instant any doc the report depends on is added, removed, or edited (mtime/size move),
     * which is exactly what makes the memo safe to reuse only for a genuinely identical corpus.
     * A missing root yields a stable 'absent' marker so the absent-index report path is itself
     * memoized once.
     */
    private function corpusSignature(string $root): string
    {
        if (! File::isDirectory($root)) {
            return 'absent';
        }

        $parts = [];
        foreach (File::allFiles($root) as $file) {
            if (strtolower($file->getExtension()) !== 'md') {
                continue;
            }
            $parts[] = $file->getRelativePathname().':'.$file->getMTime().':'.$file->getSize();
        }
        sort($parts);

        return hash('sha256', implode('|', $parts));
    }

    /**
     * @return array<string,mixed>
     */
    private function computeReport(string $root): array
    {
        $sourceRegistry = $this->sourceRegistry($root);
        $blockCatalog = $this->blockCatalog($root);
        $upgradeMap = $this->upgradeMap($root);
        $evaluations = $this->evaluations($sourceRegistry, $blockCatalog, $upgradeMap, $root);
        $blocks = $this->blocks($blockCatalog, $upgradeMap, $evaluations);
        $blockAcceptanceMatrix = $this->blockAcceptanceMatrix($blocks, $evaluations);
        $blockers = $this->blockers($sourceRegistry, $blocks, $blockAcceptanceMatrix, $evaluations);
        $summary = $this->summary($sourceRegistry, $blocks, $blockers);
        $planes = $this->planes($blocks);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $blockers === [] ? 'ready' : 'blocked',
            'implementation_status' => 'integrated_read_only_runtime',
            'summary' => $summary,
            'source_registry' => $sourceRegistry,
            'evaluations' => $evaluations,
            'integration_summary' => $this->integrationSummary($blocks, $evaluations),
            'documentation_reality_score' => $this->evaluationsSection->documentationRealityScore($sourceRegistry, $blocks, $planes),
            'readiness_matrix' => $this->readinessMatrix($blocks),
            'block_acceptance_matrix' => $blockAcceptanceMatrix,
            'planes' => $planes,
            'blocks' => $blocks,
            'blockers' => $blockers,
            'claim_policy' => [
                'writes' => false,
                'providers_invoked' => false,
                'rivals_run' => false,
                // The retracted over-claim. This is now honestly FALSE: only 11 of the 52 blocks
                // actually execute and integrate. An external reader sees the retraction explicitly,
                // and the honest executes/partial/declared split is reported alongside it.
                'declares_all_52_adrs_blocks_integrated' => false,
                'executing_block_count' => $summary['executing_block_count'],
                'partial_runtime_block_count' => $summary['partial_runtime_block_count'],
                'declared_spec_block_count' => $summary['declared_spec_block_count'],
                'declares_child_systems_complete' => false,
                'scope' => 'documentation_reality_honest_executes_partial_declared_split',
            ],
            'writes' => false,
            'generated_at' => now()->toJSON(),
        ];

        $payload['certification_hash'] = $this->hash($payload);

        return $payload;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function sourceRegistry(string $root): array
    {
        return collect(self::CANONICAL_DOCS)
            ->map(function (string $path, string $id) use ($root): array {
                $absolute = $this->absolutePath($root, $path);
                $exists = File::exists($absolute);
                $markdown = $exists ? File::get($absolute) : '';
                $parsed = $exists ? $this->frontmatter->parse($markdown) : ['frontmatter' => []];
                $frontmatter = is_array($parsed['frontmatter'] ?? null) ? $parsed['frontmatter'] : [];

                return [
                    'id' => $id,
                    'path' => $path,
                    'exists' => $exists,
                    'authority_tier' => $this->authorityTier($id),
                    'owner' => (string) ($frontmatter['owner'] ?? 'unknown'),
                    'status' => (string) ($frontmatter['status'] ?? 'missing'),
                    'doc_schema' => (string) ($frontmatter['doc_schema'] ?? 'missing'),
                    'line_count' => $exists ? substr_count($markdown, "\n") + 1 : 0,
                    'content_hash' => $exists ? hash('sha256', $markdown) : null,
                    'freshness' => $exists ? 'hash_available' : 'missing',
                    'read_policy' => 'read_only',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function blockCatalog(string $root): array
    {
        $path = $this->absolutePath($root, self::CANONICAL_DOCS['adrs']);
        if (! File::exists($path)) {
            return [];
        }

        $blocks = [];
        foreach (preg_split('/\r?\n/', File::get($path)) ?: [] as $line) {
            if (! preg_match('/^\|\s*(\d+)\s*\|\s*([^|]+?)\s*\|\s*([^|]+?)\s*\|\s*([^|]+?)\s*\|$/', $line, $matches)) {
                continue;
            }

            $number = (int) $matches[1];
            if ($number < 1 || $number > 52) {
                continue;
            }

            $blocks[$number] = [
                'number' => $number,
                'name' => trim($matches[2]),
                'function' => trim($matches[3]),
                'output' => trim($matches[4]),
            ];
        }

        ksort($blocks);

        return array_values($blocks);
    }

    /**
     * @return array<string,array<string,string>>
     */
    private function upgradeMap(string $root): array
    {
        $path = $this->absolutePath($root, self::CANONICAL_DOCS['adr_bum']);
        if (! File::exists($path)) {
            return [];
        }

        $upgrades = [];
        foreach (preg_split('/\r?\n/', File::get($path)) ?: [] as $line) {
            if (! preg_match('/^\|\s*([^|]+?)\s*\|\s*([^|]+?)\s*\|\s*([^|]+?)\s*\|$/', $line, $matches)) {
                continue;
            }

            $name = trim($matches[1]);
            if ($name === 'Bloco' || $name === '---') {
                continue;
            }

            $upgrades[$name] = [
                'upgrade' => trim($matches[2]),
                'proof' => trim($matches[3]),
            ];
        }

        return $upgrades;
    }

    /**
     * @param  array<int,array<string,mixed>>  $catalog
     * @param  array<string,array<string,string>>  $upgradeMap
     * @param  array<string,array<string,mixed>>  $evaluations
     * @return array<int,array<string,mixed>>
     */
    private function blocks(array $catalog, array $upgradeMap, array $evaluations): array
    {
        return array_map(function (array $block) use ($upgradeMap, $evaluations): array {
            $name = (string) $block['name'];
            $upgrade = $upgradeMap[$name] ?? null;
            $evaluationRef = $this->evaluationRefForBlock($name);
            $integrated = $this->isIntegratedRuntimeBlock($name, $evaluations);
            $execution = $this->executionFor($evaluationRef);

            return [
                'number' => $block['number'],
                'name' => $name,
                'planes' => $this->blockPlanes((int) $block['number']),
                'function' => $block['function'],
                'output' => $block['output'],
                'upgrade' => $upgrade['upgrade'] ?? null,
                'proof' => $upgrade['proof'] ?? null,
                // Honest readiness ladder: only an executes block that passes its real check is
                // L4_integrated; a partial block (real-but-narrow signal) is L3_read_only; a declared
                // spec falls to L2_testable (if it has an upgrade) or L1_specified.
                'readiness_level' => $integrated
                    ? 'L4_integrated'
                    : ($execution === 'partial'
                        ? 'L3_read_only'
                        : ($upgrade ? 'L2_testable' : 'L1_specified')),
                'runtime_status' => $integrated
                    ? 'integrated_read_only_runtime'
                    : ($execution === 'partial'
                        ? 'partial_real_signal_not_full_verb'
                        : 'specified_not_runtime_complete'),
                'execution' => $execution,
                'block_status' => $evaluationRef !== null ? ($evaluations[$evaluationRef]['status'] ?? 'missing') : 'missing',
                'readiness_checks' => $this->readinessChecks($block, $upgrade),
                'readiness_score' => $this->readinessScore($this->readinessChecks($block, $upgrade), $integrated),
                'evaluation_ref' => $evaluationRef,
                'integration_evidence' => $integrated ? [
                    'service' => 'App\Services\Engineering\AtlasDocumentationRealitySystemService',
                    'command' => 'php artisan atlas:documentation-reality evaluations --strict --json',
                    'test' => 'tests/Feature/Engineering/AtlasDocumentationRealitySystemServiceTest.php',
                    'evaluation_ref' => $evaluationRef,
                ] : null,
                'evidence_sufficiency' => $upgrade ? 'sufficient_for_specification' : 'insufficient',
                'evidence_refs' => array_values(array_filter([
                    self::CANONICAL_DOCS['adrs'],
                    $upgrade ? self::CANONICAL_DOCS['adr_bum'] : null,
                    self::CANONICAL_DOCS['adrib'],
                ])),
            ];
        }, $catalog);
    }

    /**
     * @param  array<int,array<string,mixed>>  $sources
     * @param  array<int,array<string,mixed>>  $catalog
     * @param  array<string,array<string,string>>  $upgradeMap
     * @return array<string,array<string,mixed>>
     */
    private function evaluations(array $sources, array $catalog, array $upgradeMap, string $root): array
    {
        // ONE corpus-wide authority adjudication, shared by every block whose verb is "resolve
        // authority/conflict across the whole canonical corpus". Scanning the ~700-doc corpus is
        // the expensive part, so it runs exactly once here and the result is threaded into the
        // six executing evaluators below — they DERIVE their status from this real signal.
        $authorityReport = $this->resolveAuthorityAudit()->report($root);

        // ONE cartography map('universe') for the whole report — shared by every block whose verb
        // reads the live visual reality (aurc_visual_reality, visual_completeness_auditor,
        // cartography_cognitive_load_meter). NULL when the cartography is not injected (the report()
        // boot-cycle guard), so those blocks degrade honestly instead of recursing. map() is
        // index-signature cached, so this single call is the only structural derivation in report().
        $cartographyMap = $this->cartography?->map('universe');

        $evaluations = [
            'authority_kernel' => $this->authorityKernelEvaluation($sources, $authorityReport),
            'source_freshness_gate' => $this->evaluationsSection->sourceFreshnessEvaluation($sources),
            'evidence_sufficiency_gate' => $this->evaluationsSection->evidenceSufficiencyEvaluation($catalog, $upgradeMap),
            'contradiction_resolver' => $this->evaluationsSection->contradictionResolverEvaluation($sources, $authorityReport),
            'implementation_readiness_matrix' => [
                'schema_version' => 'atlas.documentation_reality.implementation_readiness.v1',
                'status' => 'spec',
                'decision' => 'readiness_matrix_emitted',
                'evidence_refs' => [self::CANONICAL_DOCS['adrs'], self::CANONICAL_DOCS['adrib'], self::CANONICAL_DOCS['adr_bum']],
            ],
            'documentation_lifecycle_state_machine' => $this->evaluationsSection->lifecycleEvaluation($sources),
            'documentation_operating_system' => $this->evaluationsSection->documentationOperatingSystemEvaluation($sources),
            'knowledge_governance_system' => $this->evaluationsSection->knowledgeGovernanceEvaluation($sources, $authorityReport),
            'vocabulary_alignment_guard' => $this->evaluationsSection->vocabularyAlignmentEvaluation($sources, $authorityReport),
            'documentation_budget_governor' => $this->evaluationsSection->documentationBudgetEvaluation($sources),
            'ai_context_projection' => $this->evaluationsSection->aiContextProjectionEvaluation($sources),
            'retrieval_audit_trail' => $this->evaluationsSection->retrievalAuditTrailEvaluation($sources),
            'documentation_compression_tiers' => $this->evaluationsSection->documentationCompressionTiersEvaluation($sources),
            'provider_misread_defense' => $this->evaluationsSection->providerMisreadDefenseEvaluation(),
            'privacy_redaction_gate' => $this->evaluationsSection->privacyRedactionEvaluation($sources),
            'access_policy_resolver' => $this->evaluationsSection->accessPolicyEvaluation($sources),
            'acrui_operational_reality' => $this->acruiOperationalRealityEvaluation($sources, $catalog),
            'drift_duplication_guard' => $this->driftDuplicationEvaluation($sources, $root),
            'legacy_quarantine_governance' => $this->evaluationsSection->legacyQuarantineEvaluation(),
            'evidence_runtime_proof_bridge' => $this->evaluationsSection->evidenceRuntimeProofBridgeEvaluation($sources),
            'semantic_deduplication_engine' => $this->evaluationsSection->semanticDeduplicationEvaluation($sources, $authorityReport),
            'auto_split_planner' => $this->evaluationsSection->autoSplitPlannerEvaluation($sources),
            'obsolete_knowledge_simulator' => $this->evaluationsSection->obsoleteKnowledgeSimulatorEvaluation(),
            'reality_diff_engine' => $this->evaluationsSection->realityDiffEvaluation($sources),
            'orphaned_decision_finder' => $this->evaluationsSection->orphanedDecisionEvaluation($sources, $authorityReport),
            'documentation_entropy_monitor' => $this->evaluationsSection->documentationEntropyEvaluation($sources),
            'aurc_visual_reality' => $this->evaluationsSection->aurcVisualRealityEvaluation($sources, $cartographyMap),
            'human_modal_contract' => $this->evaluationsSection->humanModalContractEvaluation(),
            'semantic_zoom_contract' => $this->evaluationsSection->semanticZoomContractEvaluation(),
            'visual_grammar_nomenclature' => $this->evaluationsSection->visualGrammarEvaluation(),
            'cross_organization_boundary' => $this->evaluationsSection->crossOrganizationBoundaryEvaluation($sources),
            'human_correction_loop' => $this->evaluationsSection->humanCorrectionLoopEvaluation(),
            'visual_completeness_auditor' => $this->evaluationsSection->visualCompletenessEvaluation(),
            'multi_agent_handoff_projection' => $this->evaluationsSection->multiAgentHandoffProjectionEvaluation(),
            'reality_change_journal' => $this->evaluationsSection->realityChangeJournalEvaluation(),
            'human_attention_heatmap' => $this->evaluationsSection->humanAttentionHeatmapEvaluation(),
            'cartography_task_simulator' => $this->evaluationsSection->cartographyTaskSimulatorEvaluation(),
            'documentation_working_set_cache' => $this->evaluationsSection->documentationWorkingSetCacheEvaluation(),
            'cross_modal_consistency_gate' => $this->evaluationsSection->crossModalConsistencyEvaluation(),
            'context_pack_regression_test' => $this->evaluationsSection->contextPackRegressionEvaluation(),
            'cartography_cognitive_load_meter' => $this->evaluationsSection->cartographyCognitiveLoadEvaluation(),
            'canonical_question_router' => $this->canonicalQuestionRouterEvaluation($sources),
            'documentation_adoption_meter' => $this->evaluationsSection->documentationAdoptionEvaluation(),
            'surface_coverage_matrix' => $this->evaluationsSection->surfaceCoverageEvaluation(),
            'learning_to_doc_promotion_gate' => $this->evaluationsSection->learningToDocPromotionEvaluation(),
            'synthetic_reader_tests' => $this->evaluationsSection->syntheticReaderEvaluation(),
            'canonical_example_corpus' => $this->evaluationsSection->canonicalExampleCorpusEvaluation(),
        ];

        // Batch C promotions: these two verbs ROLL UP sibling evaluations computed above, so they
        // are assigned after the array is built (and before the central honesty stamp) — never a
        // hand-written status, always DERIVED from the real sibling audits (orphan/drift/freshness/
        // budget/cartography/contradiction/lifecycle).
        $evaluations['documentation_slo_alerting'] = $this->documentationSloEvaluation($sources, $evaluations);
        $evaluations['owner_escalation_queue'] = $this->ownerEscalationEvaluation($sources, $evaluations);

        // CENTRAL HONESTY STAMP. The execution tier is assigned here, never hand-written per method,
        // so a declared stub cannot self-promote to 'executes'/'partial'. For declared keys we ALSO
        // force status to 'spec' — belt-and-suspenders: even if a stub body re-introduced a literal
        // ready value, this overrides it to the honest 'spec'. The 21 executes+partial methods keep their
        // own DERIVED (literal-free) status.
        foreach ($evaluations as $key => &$evaluation) {
            $execution = $this->executionFor($key);
            $evaluation['execution'] = $execution;
            if ($execution === 'declared') {
                $evaluation['status'] = 'spec';
            }
        }
        unset($evaluation);

        return $evaluations;
    }

    /**
     * Block #1 (Documentation Authority Kernel) + block #2 (Canonical Source Registry).
     *
     * FULL VERB: resolve a conflict between docs competing for the same authority slot and
     * return a winner + reason. The real signal is the corpus-wide authority audit: each
     * identity-duplicate (id / graph_id) or runtime-duplicate (technical_runtime) GROUP is a
     * genuine authority collision — >=2 canonical docs claiming the same identity/runtime slot.
     * For each collision we adjudicate the winner by the tier order this kernel owns
     * (registered canonical source tier > any-tier-with-owner > first stable path), breaking
     * ties by status-freshness (active/implemented over scaffold/planned) and owner presence,
     * and emit {winner_path, loser_paths, winning_tier, reason}. Status is DERIVED:
     *   - 'blocked'  when a blocker-grade collision exists (an unadjudicable authority clash);
     *   - 'review'   when the corpus is collision-free but the audit still has weak conflicts
     *                (owner gaps / capability overlaps) or a registered source lacks tier+owner
     *                — a weak winner that needs a human decision;
     *   - 'ready'    only when the audit is clean AND every registered source carries a known
     *                tier + owner.
     * Mutating the real corpus (plant a duplicate graph_id) flips ready/review -> blocked.
     *
     * @param  array<int,array<string,mixed>>  $sources
     * @param  array<string,mixed>  $authorityReport
     * @return array<string,mixed>
     */
    private function authorityKernelEvaluation(array $sources, array $authorityReport): array
    {
        $tiers = EngineeringStringListNormalizer::uniqueStringCasts(array_column($sources, 'authority_tier'));
        $missingOwners = array_values(array_filter($sources, static fn (array $source): bool => ($source['owner'] ?? 'unknown') === 'unknown'));
        $unknownTierSources = array_values(array_filter($sources, static fn (array $source): bool => ($source['authority_tier'] ?? '') === 'tier_unknown'));

        $conflicts = $this->adjudicateAuthorityConflicts($authorityReport);
        $blockerCount = (int) data_get($authorityReport, 'summary.blocker_count', 0);
        $reviewItemCount = (int) data_get($authorityReport, 'summary.review_item_count', 0);
        $auditStatus = (string) data_get($authorityReport, 'status', 'review');

        // DERIVED status, never a literal: a real collision is unadjudicable -> blocked; a clean
        // corpus with only weak conflicts/owner-gaps (or a registered source missing tier/owner)
        // is a weak winner -> review; ready only when nothing is contested and the ladder is whole.
        if ($conflicts !== [] || $blockerCount > 0) {
            $status = 'blocked';
        } elseif ($auditStatus === 'review' || $reviewItemCount > 0 || $missingOwners !== [] || $unknownTierSources !== []) {
            $status = 'review';
        } else {
            $status = 'ready';
        }

        // Confidence is high only when every adjudicated winner is tier-unique (no co-tier rival)
        // AND the corpus is clean; a contested or weak-winner corpus is at most medium/low.
        $allWinnersTierUnique = $conflicts === [] || array_reduce(
            $conflicts,
            static fn (bool $carry, array $conflict): bool => $carry && ($conflict['winner_tier_unique'] ?? false) === true,
            true,
        );
        $confidence = match (true) {
            $status === 'blocked' => 'low',
            $status === 'ready' && $allWinnersTierUnique => 'high',
            default => 'medium',
        };

        return [
            'schema_version' => 'atlas.documentation_reality.authority_kernel.v1',
            'status' => $status,
            'decision' => 'repo_canonical_docs_win_over_read_models_chat_and_projections',
            'authority_tiers' => $tiers,
            'missing_owner_count' => count($missingOwners),
            'unknown_tier_source_count' => count($unknownTierSources),
            // The adjudicated authority verdict — the real "veredito de autoridade".
            'conflict_count' => count($conflicts),
            'adjudicated_conflicts' => $conflicts,
            'authority_audit_status' => $auditStatus,
            'authority_blocker_count' => $blockerCount,
            'conflict_resolution_order' => [
                'tier_1_mother_contract',
                'tier_1_canonical_child',
                'tier_2_supporting_canonical',
                'read_model',
                'provider_projection',
                'chat_memory',
            ],
            'authority_confidence' => $confidence,
            'confidence' => $confidence,
        ];
    }

    /**
     * Adjudicate every authority-slot collision the corpus audit found. An identity collision
     * (same id / graph_id) or a runtime collision (same technical_runtime) between >=2 canonical
     * docs is a real conflict; we pick the winner by the kernel's tier order and report the rest
     * as losers with a reason. Empty on a clean corpus — the rows only materialize on a real clash.
     *
     * @param  array<string,mixed>  $authorityReport
     * @return array<int,array<string,mixed>>
     */
    private function adjudicateAuthorityConflicts(array $authorityReport): array
    {
        $groups = [];
        foreach ([
            'identity_id' => data_get($authorityReport, 'identity_duplicates.id', []),
            'identity_graph_id' => data_get($authorityReport, 'identity_duplicates.graph_id', []),
            'runtime_technical_runtime' => data_get($authorityReport, 'runtime_duplicates.technical_runtime', []),
        ] as $kind => $groupList) {
            foreach ((array) $groupList as $group) {
                $paths = array_values(array_filter((array) ($group['paths'] ?? [])));
                if (count($paths) < 2) {
                    continue;
                }

                $owners = array_values(array_filter((array) ($group['owners'] ?? [])));
                $ranked = $this->rankAuthorityCandidates($paths);
                $winner = $ranked[0];
                $losers = array_values(array_map(static fn (array $candidate): string => $candidate['path'], array_slice($ranked, 1)));
                $winnerTierUnique = ! collect(array_slice($ranked, 1))
                    ->contains(static fn (array $candidate): bool => $candidate['tier_rank'] === $winner['tier_rank']);

                $groups[] = [
                    'kind' => $kind,
                    'key' => (string) ($group['key'] ?? ''),
                    'winner_path' => $winner['path'],
                    'loser_paths' => $losers,
                    'winning_tier' => $winner['tier'],
                    'winner_tier_unique' => $winnerTierUnique,
                    'owners' => $owners,
                    'reason' => $winnerTierUnique
                        ? 'winner_holds_strictly_higher_authority_tier'
                        : 'co_tier_collision_requires_owner_supersede_decision',
                ];
            }
        }

        return $groups;
    }

    /**
     * Rank candidate doc paths for an authority collision by the kernel's own ladder:
     * a registered canonical source (known tier) outranks an unregistered corpus doc; among
     * registered sources the tier order (mother > child > supporting) decides; a stable path
     * sort breaks remaining ties so the adjudication is deterministic.
     *
     * @param  array<int,string>  $paths
     * @return array<int,array<string,mixed>>
     */
    private function rankAuthorityCandidates(array $paths): array
    {
        $tierRank = [
            'tier_1_mother_contract' => 3,
            'tier_1_canonical_child' => 2,
            'tier_2_supporting_canonical' => 1,
            'tier_unknown' => 0,
        ];

        $registeredByPath = [];
        foreach (self::CANONICAL_DOCS as $id => $path) {
            $registeredByPath[$path] = $this->authorityTier($id);
        }

        $candidates = array_map(function (string $path) use ($tierRank, $registeredByPath): array {
            $tier = $registeredByPath[$path] ?? 'tier_unknown';

            return [
                'path' => $path,
                'tier' => $tier,
                'tier_rank' => $tierRank[$tier] ?? 0,
            ];
        }, array_values($paths));

        usort($candidates, static function (array $a, array $b): int {
            return [$b['tier_rank'], $a['path']] <=> [$a['tier_rank'], $b['path']];
        });

        return $candidates;
    }

    /**
     * @param  array<int,array<string,mixed>>  $sources
     * @param  array<int,array<string,mixed>>  $catalog
     * @return array<string,mixed>
     */
    private function acruiOperationalRealityEvaluation(array $sources, array $catalog): array
    {
        $runtime = $this->codeReality->classify('app/Services/Engineering/AtlasCodeRealityUsageIntelligenceService.php');
        $command = $this->codeReality->classify('app/Console/Commands/AtlasCodeRealityCommand.php');
        $audit = $this->codeReality->realityAudit();

        return [
            'schema_version' => 'atlas.documentation_reality.acrui_operational_reality.v1',
            'status' => count($sources) > 0
                && count($catalog) === 52
                && $runtime['status'] === 'ready'
                && $command['status'] === 'ready'
                && $audit['status'] === 'ready'
                && ($audit['unknown_or_unused_count'] ?? 1) === 0
                    ? 'ready'
                    : 'blocked',
            'classification_policy' => 'conservative_read_only_no_delete',
            'supported_statuses' => [
                'active_doc',
                'specified_not_runtime_complete',
                'read_only_foundation',
                'unknown_requires_review',
                'quarantine_candidate',
            ],
            'source_count' => count($sources),
            'block_count' => count($catalog),
            'runtime_evidence' => [
                'service' => [
                    'path' => 'app/Services/Engineering/AtlasCodeRealityUsageIntelligenceService.php',
                    'classification' => $runtime['classification'] ?? 'unknown',
                    'status' => $runtime['status'],
                ],
                'command' => [
                    'path' => 'app/Console/Commands/AtlasCodeRealityCommand.php',
                    'classification' => $command['classification'] ?? 'unknown',
                    'status' => $command['status'],
                ],
                'test' => 'tests/Feature/Engineering/AtlasCodeRealityUsageIntelligenceServiceTest.php',
                'reality_audit' => [
                    'schema_version' => $audit['schema_version'],
                    'target_count' => $audit['target_count'],
                    'unknown_or_unused_count' => $audit['unknown_or_unused_count'],
                    'weak_reachability_count' => $audit['weak_reachability_count'],
                    'command' => 'php artisan atlas:code-reality reality-audit --json',
                ],
            ],
        ];
    }

    /**
     * Block #11 — Drift & Duplication Guard. The mother-doc verb is "detecta divergencia
     * entre doc, codigo, routes, commands, tests e Cartografia -> blockers de drift".
     *
     * The old body was tautologically empty: it only checked for duplicate source ids /
     * paths, but ids are the CANONICAL_DOCS array keys (unique by construction) and paths
     * are literal constants — so a duplicate could NEVER appear and the guard could NEVER
     * fire. This is now a REAL doc-vs-code drift detector that composes two live signals
     * and FIRES on real divergence. It is read-only; it never writes or mutates.
     *
     * PRIMARY signal 1 — OVER-CLAIM DRIFT: compose the AAEOS capability truth ledger
     * (AtlasImplementationTruthService::ledger). A capability whose doc declares a
     * higher implementation_state than the code index can prove (rank(claimed) >
     * rank(computed)) is real drift. We surface drift_count + the drifting
     * {owner_doc, capability_id, claimed, computed} rows. We DO NOT re-derive drift — the
     * ledger is the single source (B3 made `verified` require a green run, so it is honest).
     *
     * PRIMARY signal 2 — DOC-CLAIMED-FACT DRIFT: for each canonical source doc this system
     * governs, resolve every declared evidence_ref (symbol/command/route) against the code
     * index via AtlasImplementationEvidenceResolver. A doc claiming a symbol/command/
     * route that does NOT resolve is a claimed-but-absent drift — the doc asserts a code
     * fact reality cannot back. Each unresolved ref becomes a drift row.
     *
     * SECONDARY signal — duplicate ids/paths (the old check) is kept but demoted: it can
     * contribute `review`, never `ready`, and is no longer the only/primary signal.
     *
     * VERDICT: 'ready' ONLY when zero real drift AND no duplicates. Any real drift (either
     * primary signal) => 'drift_detected' with a populated drift list. Duplicates only =>
     * 'review'. Degrade-safe: if the code index is absent/empty the doc-vs-code verdict
     * cannot be trusted (every ref would falsely look unresolved / every claim over-claimed),
     * so the guard reports 'degraded' — never a false 'ready'.
     *
     * @param  array<int,array<string,mixed>>  $sources
     * @return array<string,mixed>
     */
    private function driftDuplicationEvaluation(array $sources, string $root): array
    {
        // SECONDARY signal first (cheap, never trusts the index): the old duplicate check.
        $duplicateIds = $this->duplicates(array_column($sources, 'id'));
        $duplicatePaths = $this->duplicates(array_column($sources, 'path'));
        $hasDuplicates = $duplicateIds !== [] || $duplicatePaths !== [];

        // DEGRADE-SAFE: the two PRIMARY signals both resolve refs against the code
        // intelligence index. A present-but-empty (or absent) index makes every ref look
        // unresolved and every partial/verified claim look over-claimed — so we must NOT
        // emit a corpus-wide "everything drifts" verdict, and equally must NOT emit a false
        // 'ready'. We report 'degraded' and still surface the duplicate (index-free) signal.
        if (! $this->codeIndexHealthy()) {
            return [
                'schema_version' => 'atlas.documentation_reality.drift_duplication_guard.v1',
                'status' => 'degraded',
                'degraded' => true,
                'degraded_reason' => 'code_intelligence_index_empty_or_absent_drift_verdict_withheld',
                'drift_count' => 0,
                'drifts' => [],
                'over_claim_drift_count' => 0,
                'claimed_fact_drift_count' => 0,
                'duplicate_source_ids' => $duplicateIds,
                'duplicate_source_paths' => $duplicatePaths,
                'drift_policy' => 'real_doc_vs_code_drift_blocks_ready_index_health_required_for_a_trustworthy_verdict',
                'writes' => false,
            ];
        }

        // PRIMARY signal 1 — over-claim drift, composed from the AAEOS truth ledger.
        $ledger = $this->aaeosTruthService()->ledger();
        $ledgerRows = (array) ($ledger['capabilities'] ?? []);
        $overClaimDrifts = [];
        foreach ($ledgerRows as $row) {
            if (($row['drift'] ?? false) !== true) {
                continue;
            }
            $overClaimDrifts[] = [
                'kind' => 'over_claim',
                'owner_doc' => (string) ($row['owner_doc'] ?? ''),
                'capability_id' => (string) ($row['capability_id'] ?? ''),
                'claimed' => (string) ($row['claimed_state'] ?? ''),
                'computed' => (string) ($row['computed_state'] ?? ''),
                'detail' => 'doc claims implementation_state the code index cannot prove (over-claim)',
            ];
        }

        // PRIMARY signal 2 — claimed-but-absent fact drift over the governed source docs.
        $claimedFactDrifts = $this->claimedFactDrifts($sources, $root);

        $drifts = array_merge($overClaimDrifts, $claimedFactDrifts);
        $driftCount = count($drifts);

        $status = match (true) {
            $driftCount > 0 => 'drift_detected',
            $hasDuplicates => 'review',
            default => 'ready',
        };

        return [
            'schema_version' => 'atlas.documentation_reality.drift_duplication_guard.v1',
            'status' => $status,
            'degraded' => false,
            // Real doc-vs-code drift — the load-bearing signal. drift_count is surfaced
            // (NOT a tautological empty): live it is the AAEOS ledger's real count (0 today
            // over the live corpus), and it FIRES the instant a real over-claim or a
            // claimed-but-absent fact appears.
            'drift_count' => $driftCount,
            'over_claim_drift_count' => count($overClaimDrifts),
            'claimed_fact_drift_count' => count($claimedFactDrifts),
            'drifts' => $drifts,
            'ledger_evaluated' => (int) data_get($ledger, 'summary.evaluated', 0),
            'ledger_drift_count' => (int) data_get($ledger, 'summary.drift_count', 0),
            // Secondary (demoted) duplicate signal — contributes 'review', never 'ready',
            // and is never the sole/primary signal.
            'duplicate_source_ids' => $duplicateIds,
            'duplicate_source_paths' => $duplicatePaths,
            'drift_policy' => 'real_doc_vs_code_drift_blocks_ready_duplicates_are_a_secondary_review_signal_not_auto_fix',
            'writes' => false,
        ];
    }

    /**
     * Signal 2 — for each canonical source doc this system governs, resolve every declared
     * evidence_ref of kind symbol/command/route against the code intelligence index. A ref
     * that does NOT resolve is a claimed-but-absent drift: the doc asserts a code fact the
     * index cannot back. Returns one drift row per unresolved ref. Tests/receipts are
     * intentionally excluded here — they carry their own green-run/existence semantics in
     * the AAEOS layer (signal 1) and an existence-only test match is not a "claimed fact"
     * in the same sense as a named symbol/command/route.
     *
     * @param  array<int,array<string,mixed>>  $sources
     * @return array<int,array<string,mixed>>
     */
    private function claimedFactDrifts(array $sources, string $root): array
    {
        $resolver = $this->resolver();
        $checkedKinds = ['symbol', 'command', 'route'];
        $drifts = [];

        foreach ($sources as $source) {
            if (($source['exists'] ?? false) !== true) {
                continue; // missing source is its own blocker; not a claimed-fact drift.
            }
            $path = (string) ($source['path'] ?? '');
            $absolute = $this->absolutePath($root, $path);
            if (! File::exists($absolute)) {
                continue;
            }
            $parsed = $this->frontmatter->parse(File::get($absolute));
            $frontmatter = is_array($parsed['frontmatter'] ?? null) ? $parsed['frontmatter'] : [];
            $refs = $this->declaredEvidenceRefs($frontmatter['evidence_refs'] ?? null);

            foreach ($refs as $ref) {
                $kind = strtolower(trim($ref['kind']));
                if (! in_array($kind, $checkedKinds, true)) {
                    continue;
                }
                $resolution = $resolver->resolve($kind, $ref['ref']);
                if (($resolution['resolved'] ?? false) === true) {
                    continue;
                }
                $drifts[] = [
                    'kind' => 'claimed_fact_absent',
                    'owner_doc' => $path,
                    'capability_id' => (string) ($source['id'] ?? ''),
                    'ref_kind' => $kind,
                    'ref' => $ref['ref'],
                    'detail' => "doc declares {$kind} '{$ref['ref']}' which does not resolve in the code index (claimed-but-absent)",
                ];
            }
        }

        return $drifts;
    }

    /**
     * Normalize a doc's raw frontmatter evidence_refs into a {kind, ref} list. Accepts the
     * two authored shapes (a "kind: ref" string, or a {kind, ref} map), mirroring the AAEOS
     * truth service's own normalization so the two signals read the SAME declarations.
     *
     * @return array<int,array{kind:string, ref:string}>
     */
    private function declaredEvidenceRefs(mixed $raw): array
    {
        return DocumentationRealityClassifySupport::declaredEvidenceRefs($raw);
    }

    /**
     * The code intelligence index is "healthy enough to trust a doc-vs-code drift verdict"
     * when its symbol table holds >=1 active row. An ABSENT table is a non-production/test
     * context where no doc-vs-code claim can be evaluated at all, so the guard reports
     * degraded; a PRESENT-but-EMPTY table is the dangerous blind index (every ref would look
     * unresolved) and must also degrade — never a false 'ready'. Mirrors the repair
     * proposer's index-health gate, but treats an absent table as degraded too: this guard's
     * job is to detect divergence, and with no index there is nothing to compare against.
     */
    private function codeIndexHealthy(): bool
    {
        if (! DatabaseTableAvailability::has('atlas_engineering_code_symbols')) {
            return false;
        }

        return AtlasEngineeringCodeSymbol::query()
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->limit(1)
            ->exists();
    }

    private function aaeosTruthService(): AtlasImplementationTruthService
    {
        return $this->aaeosTruth ?? App::make(AtlasImplementationTruthService::class);
    }

    private function resolver(): AtlasImplementationEvidenceResolver
    {
        return $this->evidenceResolver ?? App::make(AtlasImplementationEvidenceResolver::class);
    }

    private function resolveAuthorityAudit(): EngineeringDocumentationAuthorityAuditService
    {
        return $this->authorityAudit ?? App::make(EngineeringDocumentationAuthorityAuditService::class);
    }

    /**
     * @param  array<int,array<string,mixed>>  $sources
     * @return array<string,mixed>
     */
    private function canonicalQuestionRouterEvaluation(array $sources): array
    {
        $byId = [];
        foreach ($sources as $source) {
            $id = is_array($source) ? (string) ($source['id'] ?? '') : '';
            if ($id !== '') {
                $byId[$id] = $source;
            }
        }

        // FULL VERB (not the old owner-presence proxy): every canonical question is RESOLVED
        // against the live source registry to its owner doc + cartography node (graph_id) +
        // content-hash evidence. All four targets are read from real data, so removing,
        // renaming or un-owning a target doc — or stripping its graph_id — flips that route to
        // unroutable and DERIVES the status down from 'ready'. There is no constant green.
        $routes = [];
        $resolved = 0;
        foreach (self::CANONICAL_QUESTIONS as $question => $answerId) {
            $source = $byId[$answerId] ?? null;
            $exists = is_array($source) && ($source['exists'] ?? false) === true;
            $owner = $exists ? trim((string) ($source['owner'] ?? '')) : '';
            $path = $exists ? trim((string) ($source['path'] ?? '')) : '';
            $contentHash = $exists ? trim((string) ($source['content_hash'] ?? '')) : '';
            $node = ($exists && $path !== '') ? $this->cartographyNodeForSource($path) : null;

            $routable = $exists && $owner !== '' && $path !== '' && $contentHash !== '' && $node !== null;
            if ($routable) {
                $resolved++;
            }

            $routes[] = [
                'question' => $question,
                'answer_source' => $answerId,
                'owner_doc' => $path !== '' ? $path : null,
                'owner' => $owner !== '' ? $owner : null,
                'cartography_node' => $node,
                'evidence_ref' => $contentHash !== '' ? 'content_hash:'.substr($contentHash, 0, 12) : null,
                'routable' => $routable,
            ];
        }

        $total = count(self::CANONICAL_QUESTIONS);

        return [
            'schema_version' => 'atlas.documentation_reality.canonical_question_router.v1',
            // DERIVED: 'ready' only when EVERY canonical question fully routes to owner doc +
            // cartography node + evidence. A single unroutable question degrades to 'review' —
            // the verb still EXECUTES (real resolution), it just reports the honest state.
            'status' => ($total > 0 && $resolved === $total) ? 'ready' : 'review',
            'routing_rule' => 'human_or_agent_question_resolves_to_owner_doc_cartography_node_and_evidence_refs',
            'canonical_question_count' => $total,
            'resolved_route_count' => $resolved,
            'routes' => $routes,
            'route_targets' => EngineeringStringListNormalizer::uniqueStringCasts(
                array_filter(array_column($routes, 'owner')),
            ),
        ];
    }

    /**
     * Resolve the cartography node id (graph_id) of a canonical source doc from its live
     * frontmatter. Returns null when the file is absent or declares no graph_id, so a route
     * whose target lost its cartography identity is honestly unroutable (flip-proof input).
     */
    private function cartographyNodeForSource(string $path): ?string
    {
        $absolute = base_path($path);
        if (! File::exists($absolute)) {
            return null;
        }

        try {
            $parsed = $this->frontmatter->parse(File::get($absolute));
        } catch (\Throwable) {
            return null;
        }

        $frontmatter = is_array($parsed['frontmatter'] ?? null) ? $parsed['frontmatter'] : [];
        $node = $frontmatter['graph_id'] ?? null;

        return (is_string($node) && trim($node) !== '') ? trim($node) : null;
    }

    /**
     * @return array<string,mixed>
     */
    private function documentationSloEvaluation(array $sources, array $evaluations): array
    {
        $sib = static fn (string $key): array => is_array($evaluations[$key] ?? null) ? $evaluations[$key] : [];
        $freshness = $sib('source_freshness_gate');
        $drift = $sib('drift_duplication_guard');
        $orphan = $sib('orphaned_decision_finder');
        $budget = $sib('documentation_budget_governor');
        $aurc = $sib('aurc_visual_reality');

        $staleCount = count((array) ($freshness['stale_sources'] ?? []));
        $driftCount = (int) ($drift['drift_count'] ?? 0);
        $driftDegraded = ($drift['degraded'] ?? false) === true;
        $orphanCount = (int) ($orphan['orphan_count'] ?? 0);
        $maxLines = (int) ($budget['max_source_lines'] ?? 0);
        $aurcStatus = (string) ($aurc['status'] ?? 'blocked');
        $sourceCount = count($sources);
        $presentCount = count(array_filter($sources, static fn (array $s): bool => ($s['exists'] ?? false) === true));

        // Each SLO is a declared target threshold (an SLO target IS, by definition, a threshold)
        // compared to the LIVE measured value pulled from the sibling evaluator that derives it
        // from the real corpus/ledger/code-index/cartography. Zero hand-written status.
        $slos = [
            ['metric' => 'freshness', 'target' => 'stale_sources == 0', 'observed' => $staleCount, 'breached' => $staleCount > 0],
            ['metric' => 'drift', 'target' => 'drift_count == 0 and not degraded', 'observed' => $driftCount, 'breached' => $driftCount > 0 || $driftDegraded],
            ['metric' => 'orphan_count', 'target' => 'orphan_count == 0', 'observed' => $orphanCount, 'breached' => $orphanCount > 0],
            ['metric' => 'context_cost', 'target' => 'max_source_lines <= 520', 'observed' => $maxLines, 'breached' => $maxLines > 520],
            ['metric' => 'cartography_visibility', 'target' => "aurc_status == 'ready'", 'observed' => $aurcStatus, 'breached' => $aurcStatus !== 'ready'],
            ['metric' => 'coverage', 'target' => 'every canonical source present', 'observed' => $presentCount.'/'.$sourceCount, 'breached' => $presentCount < $sourceCount],
        ];

        $breached = array_values(array_filter($slos, static fn (array $slo): bool => $slo['breached'] === true));

        // The block's own alert_policy: a breach becomes an owner-queue item BEFORE any runtime
        // claim. Name the real offending paths from the sibling that detected the breach.
        $alerts = array_map(static function (array $slo) use ($orphan, $freshness, $drift): array {
            $items = match ($slo['metric']) {
                'orphan_count' => array_values(array_filter(array_map(static fn ($r): ?string => is_array($r) ? ($r['path'] ?? null) : null, (array) ($orphan['orphan_queue'] ?? [])))),
                'freshness' => array_values(array_map(static fn ($s): string => is_array($s) ? (string) ($s['path'] ?? '') : (string) $s, (array) ($freshness['stale_sources'] ?? []))),
                'drift' => array_values(array_filter(array_map(static fn ($r): ?string => is_array($r) ? ($r['owner_doc'] ?? $r['path'] ?? $r['source'] ?? null) : null, (array) ($drift['drifts'] ?? [])))),
                default => [],
            };

            return ['metric' => $slo['metric'], 'observed' => $slo['observed'], 'owner_queue_items' => $items];
        }, $breached);

        return [
            'schema_version' => 'atlas.documentation_reality.slo_alerting.v1',
            // DERIVED: 'degraded' when the drift guard withheld its verdict (code index empty);
            // 'ready' iff zero SLOs breach; 'review' when any target is missed. No constant green.
            'status' => $driftDegraded ? 'degraded' : ($breached === [] ? 'ready' : 'review'),
            'slo_count' => count($slos),
            'breached_slo_count' => count($breached),
            'alert_count' => count($alerts),
            'slos' => $slos,
            'alerts' => $alerts,
            'alert_policy' => 'warnings_become_owner_queue_items_before_runtime_claims',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $sources
     * @param  array<string,array<string,mixed>>  $evaluations
     * @return array<string,mixed>
     */
    private function ownerEscalationEvaluation(array $sources, array $evaluations): array
    {
        $sib = static fn (string $key): array => is_array($evaluations[$key] ?? null) ? $evaluations[$key] : [];
        $orphan = $sib('orphaned_decision_finder');
        $contradiction = $sib('contradiction_resolver');
        $lifecycle = $sib('documentation_lifecycle_state_machine');
        $drift = $sib('drift_duplication_guard');

        $assignee = static fn (string $owner): string => ($owner !== '' && $owner !== 'unknown') ? $owner : 'documentation_governance_fallback';
        $queue = [];

        // (1) missing_owner — orphan rows whose 'missing' includes owner, plus any present source
        // with a blank/unknown owner (live authority owner-gaps audit).
        foreach ((array) ($orphan['orphan_queue'] ?? []) as $row) {
            if (is_array($row) && in_array('owner', (array) ($row['missing'] ?? []), true)) {
                $queue[] = ['reason' => 'missing_owner', 'owner_doc' => (string) ($row['path'] ?? ''), 'assignee' => 'documentation_governance_fallback'];
            }
        }
        foreach ($sources as $source) {
            $owner = trim((string) ($source['owner'] ?? ''));
            if (($source['exists'] ?? false) === true && ($owner === '' || $owner === 'unknown')) {
                $queue[] = ['reason' => 'missing_owner', 'owner_doc' => (string) ($source['path'] ?? ''), 'assignee' => 'documentation_governance_fallback'];
            }
        }

        // (2) conflicting_owner — each adjudicated contradiction (>=2 docs claim one slot).
        foreach ((array) ($contradiction['contradiction_packet'] ?? []) as $row) {
            if (is_array($row)) {
                $paths = (array) ($row['conflicting_paths'] ?? []);
                $queue[] = ['reason' => 'conflicting_owner', 'owner_doc' => (string) ($paths[0] ?? ''), 'assignee' => 'documentation_governance_fallback', 'conflicting_paths' => array_values($paths)];
            }
        }

        // (3) stale_owner_doc — a canonical source in an invalid lifecycle state.
        foreach ((array) ($lifecycle['invalid_sources'] ?? []) as $invalid) {
            $path = is_array($invalid) ? (string) ($invalid['path'] ?? '') : (string) $invalid;
            if ($path !== '') {
                $queue[] = ['reason' => 'stale_owner_doc', 'owner_doc' => $path, 'assignee' => 'documentation_governance_fallback'];
            }
        }

        // (4) unsafe_delete_candidate — a canonical source the registry expects but that is absent
        // on disk (deleting/losing it without sequence is unsafe).
        foreach ($sources as $source) {
            if (($source['exists'] ?? false) !== true) {
                $queue[] = ['reason' => 'unsafe_delete_candidate', 'owner_doc' => (string) ($source['path'] ?? ''), 'assignee' => $assignee(trim((string) ($source['owner'] ?? '')))];
            }
        }

        // (5) runtime_claim_without_evidence — each drift row (a doc claim the code index can't prove).
        foreach ((array) ($drift['drifts'] ?? []) as $row) {
            if (is_array($row)) {
                $queue[] = ['reason' => 'runtime_claim_without_evidence', 'owner_doc' => (string) ($row['owner_doc'] ?? $row['path'] ?? $row['source'] ?? ''), 'assignee' => 'documentation_governance_fallback'];
            }
        }

        $hardBlocker = array_filter($queue, static fn (array $r): bool => in_array($r['reason'], ['conflicting_owner', 'unsafe_delete_candidate'], true));

        return [
            'schema_version' => 'atlas.documentation_reality.owner_escalation.v1',
            // DERIVED (mirror orphaned_decision_finder): empty queue -> ready; a hard-blocker class
            // -> blocked; any other escalation -> review. Every row is a real audit defect computed
            // earlier in THIS report, so the verdict moves with the corpus (strip an owner -> a
            // missing_owner row appears -> ready flips to review).
            'status' => $queue === [] ? 'ready' : ($hardBlocker !== [] ? 'blocked' : 'review'),
            'escalation_count' => count($queue),
            'escalation_queue' => $queue,
            'reason_counts' => array_count_values(array_map(static fn (array $r): string => $r['reason'], $queue)),
            'assignment_policy' => 'route_to_declared_owner_or_documentation_governance_fallback',
        ];
    }

    /**
     * The single classifier that owns the executes/partial/declared decision. Every roll-up
     * (readiness_level, isIntegratedRuntimeBlock, summary counts, integration_summary, acceptance,
     * score, claim_policy) derives from this — there is no literal execution label hand-written per
     * method, so a declared stub can never self-promote to 'executes'.
     */
    private function executionFor(?string $evaluationKey): string
    {
        return DocumentationRealityClassifySupport::executionFor(
            $evaluationKey,
            self::EXECUTING_EVALUATION_KEYS,
            self::PARTIAL_EVALUATION_KEYS,
        );
    }

    /**
     * @param  array<string,array<string,mixed>>  $evaluations
     */
    private function isIntegratedRuntimeBlock(string $name, array $evaluations): bool
    {
        $map = [
            'Documentation Authority Kernel' => 'authority_kernel',
            'Canonical Source Registry' => 'authority_kernel',
            'Source Freshness Gate' => 'source_freshness_gate',
            'Evidence Sufficiency Gate' => 'evidence_sufficiency_gate',
            'Contradiction Resolver' => 'contradiction_resolver',
            'Implementation Readiness Matrix' => 'implementation_readiness_matrix',
            'Documentation Lifecycle State Machine' => 'documentation_lifecycle_state_machine',
            'Documentation Reality Score' => 'implementation_readiness_matrix',
            'Documentation Operating System' => 'documentation_operating_system',
            'Knowledge Governance System' => 'knowledge_governance_system',
            'Vocabulary Alignment Guard' => 'vocabulary_alignment_guard',
            'Documentation Budget Governor' => 'documentation_budget_governor',
            'AI Context Projection' => 'ai_context_projection',
            'Context Minimality Ledger' => 'ai_context_projection',
            'Retrieval Audit Trail' => 'retrieval_audit_trail',
            'Documentation Compression Tiers' => 'documentation_compression_tiers',
            'Provider Misread Defense' => 'provider_misread_defense',
            'Privacy & Redaction Gate' => 'privacy_redaction_gate',
            'Access Policy Resolver' => 'access_policy_resolver',
            'ACRUI Operational Reality' => 'acrui_operational_reality',
            'Drift & Duplication Guard' => 'drift_duplication_guard',
            'Legacy & Quarantine Governance' => 'legacy_quarantine_governance',
            'Evidence & Runtime Proof Bridge' => 'evidence_runtime_proof_bridge',
            'Semantic Deduplication Engine' => 'semantic_deduplication_engine',
            'Auto-Split Planner' => 'auto_split_planner',
            'Obsolete Knowledge Simulator' => 'obsolete_knowledge_simulator',
            'Reality Diff Engine' => 'reality_diff_engine',
            'Orphaned Decision Finder' => 'orphaned_decision_finder',
            'Documentation Entropy Monitor' => 'documentation_entropy_monitor',
            'AURC Visual Reality' => 'aurc_visual_reality',
            'Human Modal Contract' => 'human_modal_contract',
            'Semantic Zoom Contract' => 'semantic_zoom_contract',
            'Visual Grammar & Nomenclature' => 'visual_grammar_nomenclature',
            'Cross-Organization Boundary' => 'cross_organization_boundary',
            'Human Correction Loop' => 'human_correction_loop',
            'Visual Completeness Auditor' => 'visual_completeness_auditor',
            'Multi-Agent Handoff Projection' => 'multi_agent_handoff_projection',
            'Reality Change Journal' => 'reality_change_journal',
            'Human Attention Heatmap' => 'human_attention_heatmap',
            'Cartography Task Simulator' => 'cartography_task_simulator',
            'Documentation Working Set Cache' => 'documentation_working_set_cache',
            'Cross-Modal Consistency Gate' => 'cross_modal_consistency_gate',
            'Context Pack Regression Test' => 'context_pack_regression_test',
            'Cartography Cognitive Load Meter' => 'cartography_cognitive_load_meter',
            'Canonical Question Router' => 'canonical_question_router',
            'Documentation Adoption Meter' => 'documentation_adoption_meter',
            'Surface Coverage Matrix' => 'surface_coverage_matrix',
            'Learning-to-Doc Promotion Gate' => 'learning_to_doc_promotion_gate',
            'Documentation SLO & Alerting' => 'documentation_slo_alerting',
            'Owner Escalation Queue' => 'owner_escalation_queue',
            'Synthetic Reader Tests' => 'synthetic_reader_tests',
            'Canonical Example Corpus' => 'canonical_example_corpus',
        ];

        $key = $map[$name] ?? null;
        if ($key === null || $this->executionFor($key) !== 'executes') {
            return false;
        }

        $evaluation = $evaluations[$key] ?? null;
        $status = is_array($evaluation) ? ($evaluation['status'] ?? null) : null;

        // 'spec' must NEVER enter the integrated set; a declared block short-circuits above, but
        // belt-and-suspenders: even an executes key whose status was somehow forced to 'spec' is
        // excluded here.
        return $status !== 'spec' && in_array($status, self::INTEGRATED_EVALUATION_STATUSES, true);
    }

    private function evaluationRefForBlock(string $name): ?string
    {
        return match ($name) {
            'Documentation Authority Kernel', 'Canonical Source Registry' => 'authority_kernel',
            'Source Freshness Gate' => 'source_freshness_gate',
            'Evidence Sufficiency Gate' => 'evidence_sufficiency_gate',
            'Contradiction Resolver' => 'contradiction_resolver',
            'Implementation Readiness Matrix', 'Documentation Reality Score' => 'implementation_readiness_matrix',
            'Documentation Lifecycle State Machine' => 'documentation_lifecycle_state_machine',
            'Documentation Operating System' => 'documentation_operating_system',
            'Knowledge Governance System' => 'knowledge_governance_system',
            'Vocabulary Alignment Guard' => 'vocabulary_alignment_guard',
            'Documentation Budget Governor' => 'documentation_budget_governor',
            'AI Context Projection', 'Context Minimality Ledger' => 'ai_context_projection',
            'Retrieval Audit Trail' => 'retrieval_audit_trail',
            'Documentation Compression Tiers' => 'documentation_compression_tiers',
            'Provider Misread Defense' => 'provider_misread_defense',
            'Privacy & Redaction Gate' => 'privacy_redaction_gate',
            'Access Policy Resolver' => 'access_policy_resolver',
            'ACRUI Operational Reality' => 'acrui_operational_reality',
            'Drift & Duplication Guard' => 'drift_duplication_guard',
            'Legacy & Quarantine Governance' => 'legacy_quarantine_governance',
            'Evidence & Runtime Proof Bridge' => 'evidence_runtime_proof_bridge',
            'Semantic Deduplication Engine' => 'semantic_deduplication_engine',
            'Auto-Split Planner' => 'auto_split_planner',
            'Obsolete Knowledge Simulator' => 'obsolete_knowledge_simulator',
            'Reality Diff Engine' => 'reality_diff_engine',
            'Orphaned Decision Finder' => 'orphaned_decision_finder',
            'Documentation Entropy Monitor' => 'documentation_entropy_monitor',
            'AURC Visual Reality' => 'aurc_visual_reality',
            'Human Modal Contract' => 'human_modal_contract',
            'Semantic Zoom Contract' => 'semantic_zoom_contract',
            'Visual Grammar & Nomenclature' => 'visual_grammar_nomenclature',
            'Cross-Organization Boundary' => 'cross_organization_boundary',
            'Human Correction Loop' => 'human_correction_loop',
            'Visual Completeness Auditor' => 'visual_completeness_auditor',
            'Multi-Agent Handoff Projection' => 'multi_agent_handoff_projection',
            'Reality Change Journal' => 'reality_change_journal',
            'Human Attention Heatmap' => 'human_attention_heatmap',
            'Cartography Task Simulator' => 'cartography_task_simulator',
            'Documentation Working Set Cache' => 'documentation_working_set_cache',
            'Cross-Modal Consistency Gate' => 'cross_modal_consistency_gate',
            'Context Pack Regression Test' => 'context_pack_regression_test',
            'Cartography Cognitive Load Meter' => 'cartography_cognitive_load_meter',
            'Canonical Question Router' => 'canonical_question_router',
            'Documentation Adoption Meter' => 'documentation_adoption_meter',
            'Surface Coverage Matrix' => 'surface_coverage_matrix',
            'Learning-to-Doc Promotion Gate' => 'learning_to_doc_promotion_gate',
            'Documentation SLO & Alerting' => 'documentation_slo_alerting',
            'Owner Escalation Queue' => 'owner_escalation_queue',
            'Synthetic Reader Tests' => 'synthetic_reader_tests',
            'Canonical Example Corpus' => 'canonical_example_corpus',
            default => null,
        };
    }

    /**
     * @param  array<string,mixed>  $block
     * @param  array<string,string>|null  $upgrade
     * @return array<string,bool>
     */
    private function readinessChecks(array $block, ?array $upgrade): array
    {
        return [
            'adrs_defined' => ($block['name'] ?? '') !== '',
            'function_defined' => ($block['function'] ?? '') !== '',
            'output_defined' => ($block['output'] ?? '') !== '',
            'plane_mapped' => $this->blockPlanes((int) $block['number']) !== [],
            'upgrade_defined' => is_array($upgrade) && trim((string) ($upgrade['upgrade'] ?? '')) !== '',
            'proof_defined' => is_array($upgrade) && trim((string) ($upgrade['proof'] ?? '')) !== '',
        ];
    }

    /**
     * @param  array<string,bool>  $checks
     */
    private function readinessScore(array $checks, bool $integrated): int
    {
        $passed = count(array_filter($checks));
        $base = (int) floor(($passed / max(count($checks), 1)) * 90);

        return min(100, $base + ($integrated ? 10 : 0));
    }

    /**
     * @param  array<int,array<string,mixed>>  $blocks
     * @return array<string,array<string,mixed>>
     */
    private function planes(array $blocks): array
    {
        $byNumber = collect($blocks)->keyBy('number');

        return collect(self::PLANES)
            ->map(function (array $numbers, string $plane) use ($byNumber): array {
                $planeBlocks = collect($numbers)
                    ->map(fn (int $number): ?array => $byNumber->get($number))
                    ->filter()
                    ->values()
                    ->all();

                return [
                    'id' => $plane,
                    'block_count' => count($planeBlocks),
                    'average_readiness_score' => $planeBlocks === []
                        ? 0
                        : round(array_sum(array_column($planeBlocks, 'readiness_score')) / count($planeBlocks), 2),
                    'blocks' => array_map(static fn (array $block): string => $block['name'], $planeBlocks),
                ];
            })
            ->all();
    }

    /**
     * @param  array<int,array<string,mixed>>  $blocks
     * @return array<string,mixed>
     */
    private function readinessMatrix(array $blocks): array
    {
        $byLevel = collect($blocks)
            ->groupBy('readiness_level')
            ->map(static fn ($items): int => $items->count())
            ->all();

        $insufficient = array_values(array_filter($blocks, static fn (array $block): bool => $block['evidence_sufficiency'] !== 'sufficient_for_specification'));

        return [
            'schema_version' => 'atlas.documentation_reality.readiness_matrix.v1',
            'levels' => [
                'L0_named' => $byLevel['L0_named'] ?? 0,
                'L1_specified' => $byLevel['L1_specified'] ?? 0,
                'L2_testable' => $byLevel['L2_testable'] ?? 0,
                'L3_read_only' => $byLevel['L3_read_only'] ?? 0,
                'L4_integrated' => $byLevel['L4_integrated'] ?? 0,
                'L5_self_improving' => $byLevel['L5_self_improving'] ?? 0,
            ],
            'insufficient_blocks' => array_map(static fn (array $block): string => $block['name'], $insufficient),
            'next_upgrade_target' => $this->evaluationsSection->nextUpgradeTarget($blocks),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $blocks
     * @param  array<string,array<string,mixed>>  $evaluations
     * @return array<string,mixed>
     */
    private function integrationSummary(array $blocks, array $evaluations): array
    {
        $integrated = array_values(array_filter($blocks, static fn (array $block): bool => $block['readiness_level'] === 'L4_integrated'));
        $partial = array_values(array_filter($blocks, static fn (array $block): bool => $block['readiness_level'] === 'L3_read_only'));
        $declaredSpec = array_values(array_filter($blocks, static fn (array $block): bool => ($block['execution'] ?? null) === 'declared'));
        $missingEvaluationRefs = array_values(array_filter($blocks, static fn (array $block): bool => ($block['evaluation_ref'] ?? null) === null));
        $danglingEvaluationRefs = array_values(array_filter($blocks, static function (array $block) use ($evaluations): bool {
            $ref = $block['evaluation_ref'] ?? null;

            return is_string($ref) && ! array_key_exists($ref, $evaluations);
        }));

        // A block that claims integration without actually executing is the over-claim we are killing.
        // Honest integration_summary: nobody claims L4_integrated unless they execute, and every ref
        // resolves. Declared specs and partials are NOT integration claims, so they do not block.
        $integrationLiars = array_values(array_filter(
            $blocks,
            static fn (array $block): bool => ($block['readiness_level'] ?? null) === 'L4_integrated' && ($block['execution'] ?? null) !== 'executes',
        ));

        return [
            'schema_version' => 'atlas.documentation_reality.integration_summary.v1',
            'status' => count($blocks) === 52 && $missingEvaluationRefs === [] && $danglingEvaluationRefs === [] && $integrationLiars === []
                ? 'ready'
                : 'blocked',
            'integrated_block_count' => count($integrated),
            'partial_runtime_block_count' => count($partial),
            'declared_spec_block_count' => count($declaredSpec),
            'expected_block_count' => 52,
            'evaluation_count' => count($evaluations),
            'missing_evaluation_ref_count' => count($missingEvaluationRefs),
            'dangling_evaluation_ref_count' => count($danglingEvaluationRefs),
            'runtime_contract' => 'executing_blocks_are_materialized_by_real_signal;partial_blocks_run_a_narrow_real_check;declared_blocks_are_honest_specs_not_yet_runtime',
            'child_system_completion_claim' => 'not_claimed',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $blocks
     * @param  array<string,array<string,mixed>>  $evaluations
     * @return array<string,mixed>
     */
    private function blockAcceptanceMatrix(array $blocks, array $evaluations): array
    {
        $items = array_map(function (array $block) use ($evaluations): array {
            $evaluationRef = $block['evaluation_ref'] ?? null;
            $evaluation = is_string($evaluationRef) ? ($evaluations[$evaluationRef] ?? null) : null;
            $commands = $this->acceptanceCommandsForBlock((string) $block['name']);
            $tests = $this->acceptanceTestsForBlock((string) $block['name']);
            $execution = $this->executionFor($evaluationRef);
            $status = is_array($evaluation) ? ($evaluation['status'] ?? null) : null;

            // DERIVED 4-way acceptance, never a literal. 'accepted' is reserved for an executes block
            // that truly integrated (real check passed) and carries command+test+evidence. A declared
            // spec is honestly 'declared'; a partial block is honestly 'partial_runtime'; an executes
            // block whose real check FAILED is the only genuinely 'incomplete' outcome.
            if ($execution === 'executes'
                && ($block['readiness_level'] ?? null) === 'L4_integrated'
                && in_array($status, self::INTEGRATED_EVALUATION_STATUSES, true)
                && $commands !== []
                && $tests !== []
                && ($block['integration_evidence'] ?? null) !== null) {
                $acceptance = 'accepted';
            } elseif ($execution === 'declared' && $status === 'spec') {
                $acceptance = 'declared';
            } elseif ($execution === 'partial') {
                $acceptance = 'partial_runtime';
            } else {
                $acceptance = 'incomplete';
            }

            return [
                'block_number' => $block['number'],
                'block_name' => $block['name'],
                'status' => $acceptance,
                'execution' => $execution,
                'readiness_level' => $block['readiness_level'],
                'evaluation_ref' => $evaluationRef,
                'evaluation_status' => $status ?? 'missing',
                'owner_doc' => $this->ownerDocForBlock((string) $block['name']),
                'required_commands' => $commands,
                'required_tests' => $tests,
                'quality_floor' => [
                    'must_be_read_only' => true,
                    'must_have_source_refs' => true,
                    'must_have_evidence_refs' => true,
                    'must_not_claim_child_product_complete_without_child_cert' => true,
                    'must_fail_closed_when_evidence_missing' => true,
                ],
                'acceptance_policy' => 'accepted_only_when_executes_block_integrates_declared_specs_are_declared_partials_are_partial_runtime',
            ];
        }, $blocks);

        $accepted = array_values(array_filter($items, static fn (array $item): bool => $item['status'] === 'accepted'));
        $declared = array_values(array_filter($items, static fn (array $item): bool => $item['status'] === 'declared'));
        $partialRuntime = array_values(array_filter($items, static fn (array $item): bool => $item['status'] === 'partial_runtime'));
        $incomplete = array_values(array_filter($items, static fn (array $item): bool => $item['status'] === 'incomplete'));

        return [
            'schema_version' => 'atlas.documentation_reality.block_acceptance_matrix.v1',
            // Honest gate: zero GENUINELY-incomplete (failed-executes) blocks. Declared specs and
            // partials are honestly reported, not blockers — they do not hold the matrix back.
            'status' => count($items) === 52 && count($incomplete) === 0 ? 'ready' : 'blocked',
            'expected_block_count' => 52,
            'block_count' => count($items),
            'accepted_block_count' => count($accepted),
            'declared_block_count' => count($declared),
            'partial_runtime_block_count' => count($partialRuntime),
            'incomplete_block_count' => count($incomplete),
            'incomplete_blocks' => array_map(static fn (array $item): string => $item['block_name'], $incomplete),
            'items' => $items,
            'claim_policy' => [
                'block_acceptance_is_runtime_acceptance_not_ui_product_completion' => true,
                'child_system_completion_requires_child_certification' => true,
                'writes' => false,
            ],
        ];
    }

    /**
     * @return array<int,string>
     */
    private function acceptanceCommandsForBlock(string $name): array
    {
        $commands = ['php artisan atlas:documentation-reality acceptance --strict --json'];

        if (str_contains($name, 'ACRUI') || str_contains($name, 'Code') || str_contains($name, 'Duplicate') || str_contains($name, 'Legacy') || str_contains($name, 'Reachability')) {
            $commands[] = 'php artisan atlas:code-reality reality-audit --json';
            $commands[] = 'php artisan atlas:code-reality classify --target="<target>" --json';
            $commands[] = 'php artisan atlas:code-reality reachability --target="<target>" --json';
            $commands[] = 'php artisan atlas:code-reality deletion-preflight --target="<target>" --json';
        }

        if (str_contains($name, 'AURC') || str_contains($name, 'Visual') || str_contains($name, 'Cartography') || str_contains($name, 'Human') || str_contains($name, 'Zoom')) {
            $commands[] = 'php artisan atlas:universal-reality-cartography visual-scene --mode=implementation --strict --json';
            $commands[] = 'php artisan atlas:universal-reality-cartography navigation-slice --strict --json';
        }

        if (str_contains($name, 'Context') || str_contains($name, 'Projection') || str_contains($name, 'Provider') || str_contains($name, 'Handoff')) {
            $commands[] = 'php artisan atlas:ai:session-bootstrap --task="<task>" --json';
        }

        return EngineeringStringListNormalizer::uniqueNonEmptyStrings($commands);
    }

    /**
     * @return array<int,string>
     */
    private function acceptanceTestsForBlock(string $name): array
    {
        $tests = ['tests/Feature/Engineering/AtlasDocumentationRealitySystemServiceTest.php'];

        if (str_contains($name, 'ACRUI') || str_contains($name, 'Code') || str_contains($name, 'Duplicate') || str_contains($name, 'Legacy')) {
            $tests[] = 'tests/Feature/Engineering/AtlasCodeRealityUsageIntelligenceServiceTest.php';
        }

        if (str_contains($name, 'AURC') || str_contains($name, 'Visual') || str_contains($name, 'Cartography') || str_contains($name, 'Human') || str_contains($name, 'Zoom')) {
            $tests[] = 'tests/Feature/Engineering/AtlasUniversalRealityCartographyServiceTest.php';
        }

        if (str_contains($name, 'Context') || str_contains($name, 'Projection') || str_contains($name, 'Provider') || str_contains($name, 'Handoff')) {
            $tests[] = 'tests/Feature/Ai/AtlasAiSessionBootstrapCommandTest.php';
        }

        return EngineeringStringListNormalizer::uniqueNonEmptyStrings($tests);
    }

    private function ownerDocForBlock(string $name): string
    {
        if (str_contains($name, 'ACRUI') || str_contains($name, 'Code') || str_contains($name, 'Duplicate') || str_contains($name, 'Legacy')) {
            return self::CANONICAL_DOCS['acrui'];
        }

        if (str_contains($name, 'AURC') || str_contains($name, 'Visual') || str_contains($name, 'Cartography') || str_contains($name, 'Human') || str_contains($name, 'Zoom')) {
            return self::CANONICAL_DOCS['aurc'];
        }

        if (str_contains($name, 'Operating System')) {
            return self::CANONICAL_DOCS['documentation_os'];
        }

        if (str_contains($name, 'Governance') || str_contains($name, 'Authority')) {
            return self::CANONICAL_DOCS['knowledge_governance'];
        }

        return self::CANONICAL_DOCS['adrs'];
    }

    /**
     * @param  array<int,array<string,mixed>>  $sources
     * @param  array<int,array<string,mixed>>  $blocks
     * @param  array<string,mixed>  $blockAcceptanceMatrix
     * @param  array<string,array<string,mixed>>  $evaluations
     * @return array<int,array<string,mixed>>
     */
    private function blockers(array $sources, array $blocks, array $blockAcceptanceMatrix, array $evaluations = []): array
    {
        $blockers = [];
        foreach ($sources as $source) {
            if ($source['exists'] !== true) {
                $blockers[] = [
                    'reason' => 'missing_canonical_source',
                    'severity' => 'critical',
                    'path' => $source['path'],
                ];
            }
        }

        if (count($blocks) !== 52) {
            $blockers[] = [
                'reason' => 'adrs_block_count_not_52',
                'severity' => 'critical',
                'actual' => count($blocks),
                'expected' => 52,
            ];
        }

        foreach ($blocks as $block) {
            if (($block['upgrade'] ?? null) === null || ($block['proof'] ?? null) === null) {
                $blockers[] = [
                    'reason' => 'block_missing_upgrade_or_proof',
                    'severity' => 'high',
                    'block' => $block['name'],
                ];
            }
        }

        if (($blockAcceptanceMatrix['status'] ?? null) !== 'ready') {
            $blockers[] = [
                'reason' => 'block_acceptance_matrix_not_ready',
                'severity' => 'critical',
                'incomplete_block_count' => $blockAcceptanceMatrix['incomplete_block_count'] ?? null,
            ];
        }

        // REAL doc-vs-code drift blocks system readiness honestly. Only 'drift_detected'
        // (a real over-claim or claimed-but-absent fact) is a blocker; 'degraded' (index
        // unavailable, verdict withheld) is NOT — a missing index must not masquerade as
        // confirmed drift, and the guard already reports degraded transparently.
        $driftGuard = $evaluations['drift_duplication_guard'] ?? null;
        if (is_array($driftGuard) && ($driftGuard['status'] ?? null) === 'drift_detected') {
            $blockers[] = [
                'reason' => 'documentation_reality_drift_detected',
                'severity' => 'high',
                'drift_count' => (int) ($driftGuard['drift_count'] ?? 0),
                'over_claim_drift_count' => (int) ($driftGuard['over_claim_drift_count'] ?? 0),
                'claimed_fact_drift_count' => (int) ($driftGuard['claimed_fact_drift_count'] ?? 0),
            ];
        }

        return $blockers;
    }

    /**
     * @param  array<int,array<string,mixed>>  $sources
     * @param  array<int,array<string,mixed>>  $blocks
     * @param  array<int,array<string,mixed>>  $blockers
     * @return array<string,mixed>
     */
    private function summary(array $sources, array $blocks, array $blockers): array
    {
        // Honest three-way EXECUTION-TIER split, derived from the executes/partial/declared
        // classification. These three tier counts partition all 52 blocks and MUST sum to the block
        // count — anything else means a block landed in an impossible tier, which we surface loudly
        // rather than hide. The tier is independent of pass/fail: a block is 'executes' because it
        // computes its verb, even on a turn where its real check fails.
        $executingCount = count(array_filter(
            $blocks,
            static fn (array $block): bool => ($block['execution'] ?? null) === 'executes',
        ));
        $partialCount = count(array_filter(
            $blocks,
            static fn (array $block): bool => ($block['execution'] ?? null) === 'partial',
        ));
        $declaredSpecCount = count(array_filter(
            $blocks,
            static fn (array $block): bool => ($block['execution'] ?? null) === 'declared',
        ));

        // INTEGRATION is the honest subset of executes that actually PASSED its real check (L4). When
        // every executes block passes (the live corpus) this equals $executingCount; when an executes
        // block's real signal fails, it drops out of integration but stays in the executes tier — the
        // gap is a failed-executes (surfaced as 'incomplete' in the acceptance matrix), never hidden.
        $integratedCount = count(array_filter(
            $blocks,
            static fn (array $block): bool => ($block['execution'] ?? null) === 'executes' && ($block['readiness_level'] ?? null) === 'L4_integrated',
        ));

        // 'Honestly reported' = every block that tells the truth about itself: an executes block that
        // passes (so it is integrated), a partial block honestly marked partial, or a declared block
        // honestly marked a spec. The ONLY block that is NOT honestly reported is an executes block
        // whose real check FAILED yet which would still be expected to integrate (a failed-executes).
        $honestlyReportedCount = count(array_filter($blocks, function (array $block): bool {
            $execution = $block['execution'] ?? null;
            if ($execution === 'partial' || $execution === 'declared') {
                return true;
            }

            // executes block: honest only when it actually reached integration (its derived status passed).
            return ($block['readiness_level'] ?? null) === 'L4_integrated';
        }));

        $blockCount = count($blocks);
        if ($blockCount === 52 && $executingCount + $partialCount + $declaredSpecCount !== 52) {
            throw new \LogicException(sprintf(
                'documentation_reality summary invariant violated: executing(%d)+partial(%d)+declared_spec(%d) must equal 52, got %d.',
                $executingCount,
                $partialCount,
                $declaredSpecCount,
                $executingCount + $partialCount + $declaredSpecCount,
            ));
        }

        return [
            'source_count' => count($sources),
            'source_present_count' => count(array_filter($sources, static fn (array $source): bool => $source['exists'] === true)),
            'block_count' => $blockCount,
            'expected_block_count' => 52,
            'block_with_upgrade_count' => count(array_filter($blocks, static fn (array $block): bool => ($block['upgrade'] ?? null) !== null)),
            'read_only_foundation_block_count' => $partialCount,
            // Honest headline: only executes-and-passing blocks are integrated runtime (=11 today).
            'integrated_runtime_block_count' => $integratedCount,
            'executing_block_count' => $executingCount,
            'partial_runtime_block_count' => $partialCount,
            'declared_spec_block_count' => $declaredSpecCount,
            // SMELL FIX: renamed from the old 'accepted_block_count' (=52), which could be misread
            // as "52 blocks working". This is the count of blocks that tell the TRUTH about
            // themselves (executes-and-passing OR honestly partial OR honestly declared), surfaced
            // ALONGSIDE the three-way split so it can never hide it. It is NOT a count of working
            // blocks — the working subset is integrated_runtime_block_count. (The real
            // runtime-acceptance count lives at block_acceptance_matrix.accepted_block_count, which
            // stays = the executes-and-passing subset and is deliberately NOT renamed.)
            'honestly_classified_block_count' => $honestlyReportedCount,
            'plane_count' => count(self::PLANES),
            'blocker_count' => count($blockers),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function blockPlanes(int $blockNumber): array
    {
        $planes = [];
        foreach (self::PLANES as $plane => $numbers) {
            if (in_array($blockNumber, $numbers, true)) {
                $planes[] = $plane;
            }
        }

        return $planes;
    }

    private function authorityTier(string $id): string
    {
        return match ($id) {
            'adrs' => 'tier_1_mother_contract',
            'adrs_block_registry', 'adrib', 'adr_bum', 'acrui', 'aurc', 'documentation_os', 'knowledge_governance' => 'tier_1_canonical_child',
            'system_graph', 'implemented_vs_scaffold', 'cartography_os' => 'tier_2_supporting_canonical',
            default => 'tier_unknown',
        };
    }

    private function absolutePath(string $root, string $canonicalPath): string
    {
        $prefix = 'docs/engineering-knowledge-base/';
        if (str_starts_with($canonicalPath, $prefix)) {
            return rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.substr($canonicalPath, strlen($prefix));
        }

        return base_path($canonicalPath);
    }

    /**
     * @param  array<int,mixed>  $values
     * @return array<int,string>
     */
    private function duplicates(array $values): array
    {
        $seen = [];
        $duplicates = [];

        foreach ($values as $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }

            if (isset($seen[$value])) {
                $duplicates[$value] = true;

                continue;
            }

            $seen[$value] = true;
        }

        return array_values(array_keys($duplicates));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hash(array $payload): string
    {
        unset($payload['generated_at'], $payload['certification_hash']);

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
