<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Deterministic runtime for the Atlas AI Research Operating System.
 *
 * Research OS is NOT "web search plus summary". It is a factory for
 * evidence-backed knowledge where every important conclusion has source,
 * timestamp, provenance, confidence, contradiction search and review state.
 * This service turns the documented architecture into pure, testable governance
 * logic for the OS-level orchestrator (the leaf deciders — citation health,
 * source quality, research→docs promotion — live in their own services).
 *
 * It enforces three documented invariants:
 *
 *   1. The Pipeline (doc "Architecture"). A research run is an ORDERED sequence
 *      of stages: objective → scheduler → source_registry → collectors →
 *      evidence_lake → hybrid_index → multi_agent_research → claim_verification
 *      → citation_health → contradiction_search → synthesis_report →
 *      eval_harness → promotion → self_improvement. A run that presents stages
 *      out of order, repeats one, or skips a mandatory one is invalid.
 *
 *   2. The Core Rule (doc "Core Rule"): "Atlas must not publish knowledge.
 *      Atlas publishes verified claims with evidence." A synthesis report is
 *      blocked from publication when ANY of its claims is unverified, lacks an
 *      evidence link, or failed citation health.
 *
 *   3. The Implementation Phases (doc "Implementation Phases"): eight ordered
 *      phases, and "No phase may skip evidence, citation health or promotion
 *      gates." Each phase declares whether those three gates are satisfied; a
 *      phase that drops any required gate cannot be promoted to ready.
 *
 * Pure functions only: no network, no provider call, no storage, no database,
 * no side effects. Every method returns a strict typed array shape. The caller
 * collects the runtime signals (which stages ran, which claims verified, which
 * gates each phase satisfied) and these methods turn those signals into the
 * single auditable decision.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/research-operating-system.md
 */
final class AtlasResearchOperatingSystemService
{
    /** Stable receipt schema id for decisions this service emits. */
    public const SCHEMA_VERSION = 'atlas.aaeos.research_operating_system.v1';

    /**
     * The canonical pipeline (doc "Architecture"), in strict order. A research
     * run must traverse these stages in exactly this sequence.
     *
     * @var list<string>
     */
    public const PIPELINE = [
        'objective',
        'scheduler',
        'source_registry',
        'collectors',
        'evidence_lake',
        'hybrid_index',
        'multi_agent_research',
        'claim_verification',
        'citation_health',
        'contradiction_search',
        'synthesis_report',
        'eval_harness',
        'promotion',
        'self_improvement',
    ];

    /**
     * Stages that may NEVER be skipped: the doc's spine of evidence integrity.
     * "No phase may skip evidence, citation health or promotion gates." We map
     * those gates onto their pipeline stages plus the verification stages that
     * stand between raw capture and a published claim.
     *
     * @var list<string>
     */
    public const MANDATORY_STAGES = [
        'source_registry',
        'evidence_lake',
        'claim_verification',
        'citation_health',
        'synthesis_report',
        'promotion',
    ];

    /**
     * The three gates the doc forbids any phase from skipping.
     *
     * @var list<string>
     */
    public const REQUIRED_GATES = ['evidence', 'citation_health', 'promotion'];

    /**
     * The eight ordered Implementation Phases (doc "Implementation Phases").
     *
     * @var array<int,string>
     */
    public const PHASES = [
        1 => 'readonly_source_registry_and_scoring',
        2 => 'manual_evidence_lake_import',
        3 => 'claim_extraction_and_citation_health',
        4 => 'research_report_compiler',
        5 => 'self_improvement_proposal_integration',
        6 => 'scheduled_readonly_research_jobs',
        7 => 'multi_agent_parallel_research',
        8 => 'approved_low_risk_docs_promotion',
    ];

    /** Promotion-gate actions (doc Components: "Decides docs/AP/code/memory/report action"). */
    public const ACTION_PROMOTE = 'promote';
    public const ACTION_BLOCK = 'block';

    /**
     * Component → responsibility map (doc "Components"). Surfaced so a caller can
     * resolve which component owns a stage. Read-only reference data.
     *
     * @var array<string,string>
     */
    public const COMPONENTS = [
        'scheduler' => 'Time/event based research jobs.',
        'source_registry' => 'Allowed sources, trust tier, permissions, rate limits.',
        'collectors' => 'API, RSS, GitHub, browser, PDF, transcript, dataset and repo capture.',
        'evidence_lake' => 'Raw immutable evidence, hashes, snapshots, extracted text and screenshots.',
        'hybrid_index' => 'BM25, embeddings, graph edges, temporal metadata and authority scoring.',
        'research_agents' => 'Plan, scout, inspect, verify, red-team and synthesize.',
        'claim_store' => 'Atomic claims and evidence links.',
        'citation_health' => 'URL liveness, archive, quote support and source drift.',
        'eval_harness' => 'Quality, factuality, citation, cost, latency and utility metrics.',
        'promotion_gate' => 'Decides docs/AP/code/memory/report action.',
    ];

    /**
     * Validate a research run against the ordered pipeline (invariant #1).
     *
     * The run is the list of stage identifiers actually executed, in execution
     * order. The run is valid only when it is a contiguous, in-order prefix of
     * PIPELINE (or the whole thing): no unknown stage, no out-of-order stage, no
     * repeat, and no mandatory stage missing from the executed prefix.
     *
     * @param  list<string>  $executedStages
     * @return array<string,mixed>
     */
    public function validatePipeline(array $executedStages): array
    {
        $stages = array_values(array_filter(
            array_map(static fn ($s): string => is_string($s) ? $s : '', $executedStages),
            static fn (string $s): bool => $s !== '',
        ));

        $violations = [];
        $unknown = [];
        $seen = [];
        $maxIndex = -1;
        $orderOk = true;

        foreach ($stages as $position => $stage) {
            $index = array_search($stage, self::PIPELINE, true);

            if ($index === false) {
                $unknown[] = $stage;
                $orderOk = false;

                continue;
            }

            if (isset($seen[$stage])) {
                $violations[] = 'repeated_stage:' . $stage;
                $orderOk = false;
            }
            $seen[$stage] = true;

            if ($index <= $maxIndex) {
                $violations[] = 'out_of_order_stage:' . $stage;
                $orderOk = false;
            }
            $maxIndex = max($maxIndex, $index);
        }

        foreach ($unknown as $u) {
            $violations[] = 'unknown_stage:' . $u;
        }

        // Mandatory stages must appear among the executed stages.
        $missingMandatory = array_values(array_diff(self::MANDATORY_STAGES, array_keys($seen)));
        foreach ($missingMandatory as $m) {
            $violations[] = 'skipped_mandatory_stage:' . $m;
        }

        $valid = $violations === [];

        return [
            'schema' => self::SCHEMA_VERSION,
            'valid' => $valid,
            'order_ok' => $orderOk,
            'executed' => $stages,
            'missing_mandatory' => $missingMandatory,
            'unknown_stages' => $unknown,
            'next_stage' => $this->nextStage($maxIndex, $orderOk && $unknown === []),
            'violations' => array_values(array_unique($violations)),
            'auditable' => true,
        ];
    }

    /**
     * Enforce the Core Rule (invariant #2): "Atlas must not publish knowledge.
     * Atlas publishes verified claims with evidence."
     *
     * Given the claims that back a synthesis report, decide whether the report
     * may be published. A report is blocked when it has zero claims, or when ANY
     * claim is not verified, has no evidence link, or failed citation health.
     * Only reports whose every claim is fully evidence-backed may publish.
     *
     * Each claim shape (keys optional, default to the UNSAFE value so a missing
     * signal never silently passes):
     *   verified         bool  claim was verified against its evidence.
     *   has_evidence     bool  claim has at least one evidence link.
     *   citation_healthy bool  claim's citation passed the health gate.
     *
     * @param  list<array<string,mixed>>  $claims
     * @return array<string,mixed>
     */
    public function gateReport(array $claims): array
    {
        $total = 0;
        $verified = 0;
        $unsupported = [];

        foreach ($claims as $i => $claim) {
            if (! is_array($claim)) {
                continue;
            }
            $total++;

            $isVerified = (bool) ($claim['verified'] ?? false);
            $hasEvidence = (bool) ($claim['has_evidence'] ?? false);
            $citationHealthy = (bool) ($claim['citation_healthy'] ?? false);

            $reasons = [];
            if (! $isVerified) {
                $reasons[] = 'unverified';
            }
            if (! $hasEvidence) {
                $reasons[] = 'no_evidence_link';
            }
            if (! $citationHealthy) {
                $reasons[] = 'citation_unhealthy';
            }

            if ($reasons === []) {
                $verified++;
            } else {
                $unsupported[] = [
                    'index' => (int) $i,
                    'reasons' => $reasons,
                ];
            }
        }

        // The Core Rule: with no claims there is nothing verified to publish, and
        // any unsupported claim turns the artifact into "knowledge" rather than
        // verified claims — both are blocked.
        $mayPublish = $total > 0 && $unsupported === [];

        return [
            'schema' => self::SCHEMA_VERSION,
            'action' => $mayPublish ? self::ACTION_PROMOTE : self::ACTION_BLOCK,
            'may_publish' => $mayPublish,
            'core_rule' => 'publish_verified_claims_not_knowledge',
            'total_claims' => $total,
            'verified_claims' => $verified,
            'unsupported_claims' => $unsupported,
            'block_reason' => $mayPublish
                ? null
                : ($total === 0 ? 'no_verified_claims' : 'unsupported_claims_present'),
            'auditable' => true,
        ];
    }

    /**
     * Evaluate an Implementation Phase against the required-gates rule
     * (invariant #3): "No phase may skip evidence, citation health or promotion
     * gates."
     *
     * Input:
     *   phase   int   1..8 (doc phase number).
     *   gates   array<string,bool>  which of REQUIRED_GATES are satisfied.
     *
     * A phase is ready ONLY when the phase number is recognised AND all three
     * required gates are satisfied. Any missing gate yields not-ready plus the
     * exact list of dropped gates.
     *
     * @param  array<string,mixed>  $phase
     * @return array<string,mixed>
     */
    public function evaluatePhase(array $phase): array
    {
        $number = (int) ($phase['phase'] ?? 0);
        $known = array_key_exists($number, self::PHASES);

        $gatesInput = is_array($phase['gates'] ?? null) ? $phase['gates'] : [];

        $satisfied = [];
        $missing = [];
        foreach (self::REQUIRED_GATES as $gate) {
            if ((bool) ($gatesInput[$gate] ?? false)) {
                $satisfied[] = $gate;
            } else {
                $missing[] = $gate;
            }
        }

        $ready = $known && $missing === [];

        return [
            'schema' => self::SCHEMA_VERSION,
            'phase' => $number,
            'phase_name' => $known ? self::PHASES[$number] : null,
            'known_phase' => $known,
            'ready' => $ready,
            'satisfied_gates' => $satisfied,
            'missing_gates' => $missing,
            'action' => $ready ? self::ACTION_PROMOTE : self::ACTION_BLOCK,
            'auditable' => true,
        ];
    }

    /**
     * Resolve the responsibility string for a documented component.
     *
     * @return array<string,mixed>
     */
    public function describeComponent(string $component): array
    {
        $known = array_key_exists($component, self::COMPONENTS);

        return [
            'schema' => self::SCHEMA_VERSION,
            'component' => $component,
            'known' => $known,
            'responsibility' => $known ? self::COMPONENTS[$component] : null,
        ];
    }

    /**
     * The next pipeline stage that should run given the furthest valid index
     * reached. Null when the pipeline is complete or the run is already invalid.
     */
    private function nextStage(int $maxIndex, bool $cleanSoFar): ?string
    {
        if (! $cleanSoFar) {
            return null;
        }
        $next = $maxIndex + 1;

        return $next < count(self::PIPELINE) ? self::PIPELINE[$next] : null;
    }
}
