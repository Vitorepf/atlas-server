<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Context\Support;

use App\Services\Ai\Context\AtlasContextRankingSystemService as Host;
use App\Services\Ai\Context\Support\ContextRankingSystemSupport as Support;
use App\Services\Ai\Mission\MissionCanonicalHash;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pure Support peel for AUCRI context ranking projectors —
 * no DB, ledger, Carbon clock, config, WorldModel DI, or provider I/O.
 *
 * Explicit path proof: host imports Support and thin-forwards the peeled pure
 * cluster (score / rank-map / coverage / feedback-impact / channel / flow).
 */
final class ContextRankingSystemSupportTest extends TestCase
{
    private const SUPPORT_PATH = 'app/Services/Ai/Context/Support/ContextRankingSystemSupport.php';

    private const HOST_PATH = 'app/Services/Ai/Context/AtlasContextRankingSystemService.php';

    /** @var list<string> */
    private const PEELED = [
        'refsForProfessionalRanker',
        'retrievalChannel',
        'rankedCandidates',
        'graphSourceScores',
        'graphBoost',
        'reasons',
        'excludedRefs',
        'requiredSourceCoverage',
        'status',
        'feedbackImpactReport',
        'rankPositionMap',
        'rankKeys',
        'rankKey',
        'rankRefsForKeys',
        'promotedRefs',
        'demotedRefs',
        'publicRankRef',
        'coverageDelta',
        'feedbackImpactReportPolicy',
        'rankingSnapshot',
        'authorityScore',
        'freshnessScore',
        'freshnessLabel',
        'inputFlow',
        'inactiveFeedbackHint',
        'feedbackHintSummary',
        'feedbackImpact',
        'hintStrings',
        'flattenScalars',
        'flow',
    ];

    /** Host residual orchestrators / I/O that must stay. */
    /** @var list<string> */
    private const HOST_RESIDUAL = [
        'rank',
        'graphRanking',
        'feedbackHint',
        'globalFeedbackHints',
        'concentrationDemoteContextRefs',
        'recordFeedbackHintSnapshot',
    ];

    #[Test]
    public function explicit_path_proof_support_and_host_files_exist_and_host_calls_support(): void
    {
        $root = dirname(__DIR__, 5);
        $supportAbs = $root.'/'.self::SUPPORT_PATH;
        $hostAbs = $root.'/'.self::HOST_PATH;

        $this->assertFileExists($supportAbs, 'Support peel must live at '.self::SUPPORT_PATH);
        $this->assertFileExists($hostAbs, 'Host must remain at '.self::HOST_PATH);

        $hostSrc = (string) file_get_contents($hostAbs);
        $this->assertStringContainsString(
            'use App\Services\Ai\Context\Support\ContextRankingSystemSupport;',
            $hostSrc,
            'Host must import ContextRankingSystemSupport',
        );

        foreach ([
            'ContextRankingSystemSupport::refsForProfessionalRanker',
            'ContextRankingSystemSupport::retrievalChannel',
            'ContextRankingSystemSupport::rankedCandidates',
            'ContextRankingSystemSupport::excludedRefs',
            'ContextRankingSystemSupport::requiredSourceCoverage',
            'ContextRankingSystemSupport::status',
            'ContextRankingSystemSupport::feedbackImpactReport',
            'ContextRankingSystemSupport::rankPositionMap',
            'ContextRankingSystemSupport::authorityScore',
            'ContextRankingSystemSupport::freshnessLabel',
            'ContextRankingSystemSupport::inactiveFeedbackHint',
            'ContextRankingSystemSupport::feedbackHintSummary',
            'ContextRankingSystemSupport::feedbackImpact',
            'ContextRankingSystemSupport::hintStrings',
            'ContextRankingSystemSupport::flow',
        ] as $needle) {
            $this->assertStringContainsString($needle, $hostSrc, "Host must call {$needle}");
        }

        $support = new ReflectionClass(Support::class);
        foreach (self::PEELED as $method) {
            $this->assertTrue($support->hasMethod($method), "Support must expose {$method}");
            $rm = $support->getMethod($method);
            $this->assertTrue($rm->isPublic() && $rm->isStatic(), "{$method} must be public static");
        }

        $host = new ReflectionClass(Host::class);
        foreach (self::PEELED as $method) {
            $this->assertTrue($host->hasMethod($method), "Host keeps thin forward {$method}");
        }

        foreach (self::HOST_RESIDUAL as $method) {
            $this->assertTrue($host->hasMethod($method), "Host residual I/O/orchestrator {$method} must remain");
        }

        $this->assertSame(Support::SCHEMA_VERSION, Host::SCHEMA_VERSION);
        $this->assertSame(Support::CONTEXT_SCORE_SCHEMA, Host::CONTEXT_SCORE_SCHEMA);
        $this->assertSame(Support::EXCLUDED_REF_SCHEMA, Host::EXCLUDED_REF_SCHEMA);
        $this->assertSame(Support::FEEDBACK_IMPACT_REPORT_SCHEMA, Host::FEEDBACK_IMPACT_REPORT_SCHEMA);
        $this->assertSame(Support::RERANK_RESULT_SCHEMA, Host::RERANK_RESULT_SCHEMA);

        $supportSrc = (string) file_get_contents($supportAbs);
        foreach (['config(', 'Carbon::', 'DatabaseTableAvailability', 'AiRagFeedbackEvent', 'AtlasEvidenceLedger', 'WorldModelGraphRanker'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $supportSrc,
                "Support must not reference residual I/O surface {$forbidden}",
            );
        }
    }

    #[Test]
    public function retrieval_channel_and_freshness_label_maps_are_deterministic(): void
    {
        $this->assertSame('aucri_hybrid_retrieval', Support::retrievalChannel(['source_type' => 'memory_signals']));
        $this->assertSame(
            'local_semantic_vector',
            Support::retrievalChannel(['source_type' => 'semantic_candidate', 'score_origin' => 'local_semantic_vector']),
        );
        $this->assertSame(
            'manifest_pending_embedding',
            Support::retrievalChannel(['source_type' => 'semantic_candidate', 'score_origin' => 'manifest']),
        );

        $this->assertSame('current', Support::freshnessLabel('implemented_ready'));
        $this->assertSame('usable', Support::freshnessLabel('candidate_manifest_only'));
        $this->assertSame('future_or_missing', Support::freshnessLabel('future_governed'));
        $this->assertSame('unknown', Support::freshnessLabel('weird'));

        $this->assertSame(0.90, Support::authorityScore('operator_request'));
        $this->assertSame(0.55, Support::authorityScore('unknown'));
        $this->assertSame(1.0, Support::freshnessScore('provided'));
        $this->assertSame(0.20, Support::freshnessScore('blocked'));
    }

    #[Test]
    public function flow_and_input_flow_resolve_programming_and_generic_domains(): void
    {
        $this->assertSame('programming.repair', Support::flow('developer', 'debug'));
        $this->assertSame('programming.review', Support::flow('programming', 'review'));
        $this->assertSame('programming.dev', Support::flow('atlas_programming', 'direct'));
        $this->assertSame('atlas.direct', Support::flow('atlas', 'direct'));

        $this->assertSame('custom.flow', Support::inputFlow(['flow_id' => ' custom.flow '], 'atlas', 'direct'));
        $this->assertSame('programming.repair', Support::inputFlow([], 'developer', 'quality_repair'));
    }

    #[Test]
    public function required_source_coverage_status_and_excluded_refs_are_pure(): void
    {
        $selected = [
            ['candidate_id' => 'c1', 'source_type' => 'memory_signals', 'source_ref_hash' => 'a', 'candidate_hash' => 'h1'],
            ['candidate_id' => 'c2', 'source_type' => 'code_intelligence', 'source_ref_hash' => 'b', 'candidate_hash' => 'h2'],
        ];
        $ranked = array_merge($selected, [
            ['candidate_id' => 'c3', 'source_type' => 'vector_retrieval', 'source_ref_hash' => 'c', 'candidate_hash' => 'h3'],
        ]);

        $coverage = Support::requiredSourceCoverage($selected, ['memory_signals', 'vector_retrieval']);
        $this->assertSame(['memory_signals' => true, 'vector_retrieval' => false], $coverage);

        $this->assertSame('ready', Support::status(['status' => 'ready'], $selected, ['memory_signals' => true]));
        $this->assertSame('degraded', Support::status(['status' => 'ready'], $selected, $coverage));
        $this->assertSame('blocked', Support::status(['status' => 'blocked'], $selected, ['memory_signals' => true]));
        $this->assertSame('blocked', Support::status(['status' => 'ready'], [], ['memory_signals' => true]));

        $excluded = Support::excludedRefs($ranked, $selected, [
            ['ref' => 'pro-ref', 'source' => 'docs', 'reason' => 'duplicate'],
        ]);
        $this->assertCount(2, $excluded);
        $this->assertSame(Support::EXCLUDED_REF_SCHEMA, $excluded[0]['schema_version']);
        $this->assertSame('budget_trimmed', $excluded[0]['reason']);
        $this->assertSame('c', $excluded[0]['source_ref_hash']);
        $this->assertSame('duplicate', $excluded[1]['reason']);
        $this->assertSame(MissionCanonicalHash::sha256('pro-ref'), $excluded[1]['source_ref_hash']);
    }

    #[Test]
    public function feedback_impact_and_report_compute_promotions_without_io(): void
    {
        $hint = Support::inactiveFeedbackHint();
        $this->assertFalse($hint['active']);

        $activeHint = [
            'active' => true,
            'source' => 'input_feedback_hint',
            'repromote_source_types' => ['code_intelligence'],
            'demote_source_types' => ['memory_signals'],
            'demote_source_hashes' => [MissionCanonicalHash::sha256('noisy-ref')],
            'demote_context_refs' => ['noisy-ref'],
        ];

        $impact = Support::feedbackImpact(
            ['source_type' => 'code_intelligence'],
            'noisy-ref',
            MissionCanonicalHash::sha256('noisy-ref'),
            $activeHint,
        );
        $this->assertContains('feedback_repromote_source_type', $impact['reasons']);
        $this->assertContains('feedback_demote_ref_hash', $impact['reasons']);
        $this->assertContains('feedback_demote_context_ref', $impact['reasons']);
        $this->assertSame(-0.2, $impact['delta']); // +0.20 -0.25 -0.15 = -0.20 after clamp

        $baselineRanked = [
            ['candidate_hash' => 'h1', 'source_ref_hash' => 's1', 'source_type' => 'memory_signals', 'score' => 0.9, 'required' => true, 'available' => true],
            ['candidate_hash' => 'h2', 'source_ref_hash' => 's2', 'source_type' => 'code_intelligence', 'score' => 0.5, 'required' => false, 'available' => true],
        ];
        $currentRanked = [
            ['candidate_hash' => 'h2', 'source_ref_hash' => 's2', 'source_type' => 'code_intelligence', 'score' => 0.95, 'required' => false, 'available' => true],
            ['candidate_hash' => 'h1', 'source_ref_hash' => 's1', 'source_type' => 'memory_signals', 'score' => 0.4, 'required' => true, 'available' => true],
        ];
        $report = Support::feedbackImpactReport(
            $baselineRanked,
            $currentRanked,
            [$baselineRanked[0]],
            [$currentRanked[0]],
            ['memory_signals' => true, 'code_intelligence' => false],
            ['memory_signals' => false, 'code_intelligence' => true],
            $activeHint,
        );

        $this->assertSame(Support::FEEDBACK_IMPACT_REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame('active', $report['status']);
        $this->assertTrue($report['selected_set_changed']);
        $this->assertGreaterThan(0, $report['rank_position_change_count']);
        $this->assertSame(['code_intelligence'], $report['coverage_delta']['gained_required_sources']);
        $this->assertSame(['memory_signals'], $report['coverage_delta']['lost_required_sources']);
        $this->assertNotEmpty($report['promoted_refs']);
        $this->assertNotEmpty($report['demoted_refs']);
        $this->assertTrue($report['policy']['provider_safe_only']);

        $inactive = Support::feedbackImpactReport([], [], [], [], [], [], $hint);
        $this->assertSame('inactive', $inactive['status']);
        $this->assertFalse($inactive['selected_set_changed']);
    }

    #[Test]
    public function ranked_candidates_compose_explainable_scores_and_reasons(): void
    {
        $candidates = [
            [
                'candidate_id' => 'cand-code',
                'source_type' => 'code_intelligence',
                'source_ref' => 'ref-code',
                'score_hint' => 0.8,
                'authority_level' => 'source_adapter',
                'status' => 'implemented_ready',
                'provider_safe' => true,
                'required' => true,
                'available' => true,
                'owner_doc' => 'docs/a.md',
                'reason' => 'code hit',
                'candidate_hash' => 'hash-code',
            ],
            [
                'candidate_id' => 'cand-mem',
                'source_type' => 'memory_signals',
                'source_ref' => 'ref-mem',
                'score_hint' => 0.7,
                'authority_level' => 'source_observed',
                'status' => 'implemented_partial',
                'provider_safe' => false,
                'required' => false,
                'available' => true,
                'owner_doc' => '',
                'reason' => 'memory hit',
                'candidate_hash' => 'hash-mem',
            ],
        ];

        $professional = [
            'ranked_refs' => [
                ['source' => 'code_intelligence', 'ref' => 'ref-code', 'score' => 1.6],
                ['source' => 'memory_signals', 'ref' => 'ref-mem', 'score' => 1.0],
            ],
        ];
        $graph = [
            'ranked_sources' => [
                ['path' => 'docs/a.md', 'top_score' => 2.0],
            ],
        ];

        $ranked = Support::rankedCandidates($candidates, $professional, $graph, Support::inactiveFeedbackHint());
        $this->assertCount(2, $ranked);
        $this->assertSame('cand-code', $ranked[0]['candidate_id']);
        $this->assertSame(Support::CONTEXT_SCORE_SCHEMA, $ranked[0]['score_components']['schema_version']);
        $this->assertGreaterThan($ranked[1]['score'], $ranked[0]['score']);
        $this->assertContains('authority:source_adapter', $ranked[0]['reasons']);
        $this->assertContains('freshness:current', $ranked[0]['reasons']);
        $this->assertContains('graph_support', $ranked[0]['reasons']);
        $this->assertContains('provider_unsafe_penalty', $ranked[1]['reasons']);
        $this->assertSame(MissionCanonicalHash::sha256('ref-code'), $ranked[0]['source_ref_hash']);

        $refs = Support::refsForProfessionalRanker($candidates);
        $this->assertSame('code_intelligence', $refs[0]['source']);
        $this->assertSame('aucri_hybrid_retrieval', $refs[0]['retrieval_channel']);
        $this->assertSame('provider_safe', $refs[0]['privacy']);
        $this->assertSame('current', $refs[0]['freshness']);
        $this->assertSame('provider_unsafe', $refs[1]['privacy']);
        $this->assertSame('usable', $refs[1]['freshness']);
    }

    #[Test]
    public function hint_strings_flatten_and_summary_respect_scope_flag(): void
    {
        $this->assertSame(
            ['a', 'b', 'c'],
            Support::hintStrings(['a', 'b'], 'c', [['', 'b'], null]),
        );
        $this->assertSame([], Support::flattenScalars(new \stdClass));
        $this->assertSame(['x'], Support::flattenScalars(' x '));

        $hint = [
            'active' => true,
            'source' => 'latest_flow_feedback',
            'feedback_scope' => 'flow',
            'flow_id' => 'programming.repair',
            'repromote_source_types' => ['code_intelligence'],
            'demote_source_types' => ['noise'],
            'demote_source_hashes' => ['h'],
            'demote_context_refs' => ['r'],
            'event_available' => true,
        ];

        $withScope = Support::feedbackHintSummary($hint, true);
        $this->assertSame('active', $withScope['status']);
        $this->assertSame('flow', $withScope['feedback_scope']);
        $this->assertSame(1, $withScope['demote_source_type_count']);
        $this->assertSame(['code_intelligence'], $withScope['repromote_source_types']);

        $withoutScope = Support::feedbackHintSummary($hint, false);
        $this->assertArrayNotHasKey('feedback_scope', $withoutScope);
        $this->assertSame('latest_flow_feedback', $withoutScope['source']);
    }

    #[Test]
    public function graph_source_scores_and_rank_position_helpers_are_stable(): void
    {
        $scores = Support::graphSourceScores([
            ['path' => 'docs/a.md', 'top_score' => 2.5],
            ['path' => '', 'top_score' => 9],
            ['path' => 'docs/b.md', 'top_score' => 5.0],
        ]);
        $this->assertSame(1.0, $scores['docs/a.md']);
        $this->assertSame(1.0, $scores['docs/b.md']); // clamped
        $this->assertArrayNotHasKey('', $scores);

        $this->assertSame(0.8, Support::graphBoost(['owner_doc' => 'docs/a.md'], ['docs/a.md' => 0.8]));
        $this->assertSame(0.0, Support::graphBoost(['owner_doc' => 'missing'], $scores));

        $ranked = [
            ['candidate_hash' => 'h1', 'source_ref_hash' => 's1', 'source_type' => 'memory_signals', 'score' => 0.9],
            ['candidate_hash' => '', 'source_ref_hash' => 's2', 'source_type' => 'code_intelligence', 'score' => 0.5],
        ];
        $map = Support::rankPositionMap($ranked);
        $this->assertSame(1, $map['candidate:h1']['rank']);
        $this->assertSame(2, $map['source:code_intelligence:s2']['rank']);
        $this->assertSame(['candidate:h1', 'source:code_intelligence:s2'], Support::rankKeys($ranked));
        $this->assertSame('candidate:h1', Support::rankKey($ranked[0]));

        $snapshot = Support::rankingSnapshot($ranked, [$ranked[0]]);
        $this->assertCount(2, $snapshot['positions']);
        $this->assertCount(1, $snapshot['selected']);
    }
}
