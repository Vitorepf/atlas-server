<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AtlasDecide;

use App\Services\Ai\AtlasDecide\AtlasDecideMetaLearningService;
use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use Tests\TestCase;

/**
 * Unit tests for Atlas Decide · Meta-Learning Loop Closure.
 *
 * Rivals 1.0 (ForgeRivals) was retired — see
 * docs/engineering-knowledge-base/atlas-rivals2-rebuild-map-v1.md. The offline
 * rivals-fed signal is now permanently `insufficient_evidence` (fail-closed)
 * until the Rivals 2.0 ledger feeds ADML again. Live outcome feedback remains
 * the only evidence source that can make a route actionable.
 */
class AtlasDecideMetaLearningServiceTest extends TestCase
{
    private string $tmpRoot;

    private string $activationLog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir().'/atlas_meta_learning_'.uniqid('', true);
        @mkdir($this->tmpRoot, 0775, true);
        $this->activationLog = $this->tmpRoot.'/routing_activations.jsonl';
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpRoot);
        parent::tearDown();
    }

    private function rmdirRecursive(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $full = $path.'/'.$f;
            is_dir($full) ? $this->rmdirRecursive($full) : @unlink($full);
        }
        @rmdir($path);
    }

    private function buildService(): AtlasDecideMetaLearningService
    {
        $svc = new AtlasDecideMetaLearningService;
        $svc->setActivationLogPathForTesting($this->activationLog);

        return $svc;
    }

    /**
     * @return array<string,mixed>
     */
    private function costOutcomeConfigEnabled(): array
    {
        return [
            'enabled' => true,
            'min_evidence' => 3,
            'min_certification_rate' => 0.8,
            'min_score' => 80.0,
            'max_score_drop' => 3.0,
            'require_measured_cost' => true,
            'min_cost_samples' => 1,
        ];
    }

    private function seededFeedback(string $file = 'live_outcomes.jsonl'): AtlasDecideLiveOutcomeFeedbackService
    {
        $feedback = new AtlasDecideLiveOutcomeFeedbackService;
        $feedback->setLogPathForTesting($this->tmpRoot.'/'.$file);
        for ($i = 0; $i < 3; $i++) {
            $feedback->record([
                'task_category' => 'bugfix',
                'role' => 'repair_agent',
                'framework' => 'python',
                'provider' => 'codex_cli',
                'model' => 'gpt-5.5',
                'result' => AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS,
                'quality_score' => 0.91,
                'cost_usd' => 0.04,
                'tokens_used' => 800,
                'actor' => 'ai_worker',
            ]);
            $feedback->record([
                'task_category' => 'bugfix',
                'role' => 'repair_agent',
                'framework' => 'python',
                'provider' => 'minimax_m27_cli',
                'model' => 'MiniMax-M3',
                'result' => AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS,
                'quality_score' => 0.892,
                'cost_usd' => 0.004,
                'tokens_used' => 300,
                'actor' => 'ai_worker',
            ]);
        }

        return $feedback;
    }

    public function test_recommend_with_no_ledger_returns_insufficient_evidence(): void
    {
        $svc = $this->buildService();
        $rec = $svc->recommend(['task_category' => 'frontend', 'role' => 'builder']);

        $this->assertSame(AtlasDecideMetaLearningService::RECOMMENDATION_SCHEMA, $rec['schema_version']);
        $this->assertSame('insufficient_evidence', $rec['signal']);
        $this->assertFalse($rec['actionable']);
        $this->assertSame(AtlasDecideMetaLearningService::MODE_SHADOW, $rec['mode']);
        $this->assertContains('insufficient_evidence', $rec['reason']);
        $this->assertStringStartsWith('sha256:', $rec['recommendation_hash']);
    }

    public function test_recommend_all_is_empty_with_rivals_ledger_retired(): void
    {
        // Rivals 1.0 ledger retired: no offline entries → no scopes to recommend.
        $this->assertSame([], $this->buildService()->recommendAll());
    }

    public function test_routing_table_with_no_receipts_is_empty(): void
    {
        $table = $this->buildService()->routingTable();
        $this->assertSame(AtlasDecideMetaLearningService::TABLE_SCHEMA, $table['schema_version']);
        $this->assertSame(0, $table['active_entries']);
        $this->assertSame(0, $table['shadow_entries']);
        $this->assertSame([], $table['entries']);
    }

    public function test_activate_blocks_when_recommendation_not_actionable(): void
    {
        $svc = $this->buildService();
        $this->expectException(\InvalidArgumentException::class);
        $svc->applyAction([
            'action' => AtlasDecideMetaLearningService::ACTION_ACTIVATE,
            'task_category' => 'frontend',
            'role' => 'builder',
        ]);
    }

    public function test_deactivate_appends_receipt_without_actionability_check(): void
    {
        $svc = $this->buildService();
        $r = $svc->applyAction([
            'action' => AtlasDecideMetaLearningService::ACTION_DEACTIVATE,
            'task_category' => 'frontend',
            'role' => 'builder',
        ]);
        $this->assertSame(AtlasDecideMetaLearningService::ACTIVATION_SCHEMA, $r['schema_version']);
        $this->assertSame('deactivate', $r['action']);
        $this->assertSame('shadow', $r['new_mode']);
        $this->assertFileExists($this->activationLog);
    }

    public function test_reset_clears_table_state(): void
    {
        $svc = $this->buildService();
        $svc->applyAction([
            'action' => AtlasDecideMetaLearningService::ACTION_DEACTIVATE,
            'task_category' => 'frontend',
            'role' => 'builder',
        ]);
        $r = $svc->applyAction(['action' => AtlasDecideMetaLearningService::ACTION_RESET]);
        $this->assertSame('reset', $r['action']);
        $table = $svc->routingTable();
        $this->assertSame(0, $table['active_entries']);
        $this->assertNotNull($table['last_reset_at']);
    }

    public function test_invalid_action_is_rejected(): void
    {
        $svc = $this->buildService();
        $this->expectException(\InvalidArgumentException::class);
        $svc->applyAction(['action' => 'not_a_real_action']);
    }

    public function test_recommendation_hash_is_deterministic_for_same_state(): void
    {
        $svc = $this->buildService();
        $a = $svc->recommend(['task_category' => 'frontend', 'role' => 'builder']);
        $b = $svc->recommend(['task_category' => 'frontend', 'role' => 'builder']);
        $this->assertSame($a['recommendation_hash'], $b['recommendation_hash']);
    }

    public function test_active_route_for_returns_null_when_no_activation(): void
    {
        $svc = $this->buildService();
        $this->assertNull($svc->activeRouteFor('frontend', 'builder'));
    }

    public function test_activate_requires_task_and_role(): void
    {
        $svc = $this->buildService();
        $this->expectException(\InvalidArgumentException::class);
        $svc->applyAction(['action' => AtlasDecideMetaLearningService::ACTION_ACTIVATE]);
    }

    public function test_table_hash_is_deterministic_across_invocations(): void
    {
        $svc = $this->buildService();
        $a = $svc->routingTable()['table_hash'];
        $b = $svc->routingTable()['table_hash'];
        // Hash spans canonical fields only — timestamps excluded by design.
        $this->assertSame($a, $b);
        $this->assertStringStartsWith('sha256:', $a);
    }

    public function test_recommendation_has_all_required_fields(): void
    {
        $rec = $this->buildService()->recommend([
            'task_category' => 'frontend',
            'role' => 'builder',
            'framework' => 'react',
        ]);
        foreach ([
            'schema_version', 'generated_at', 'scope', 'signal', 'confidence',
            'evidence_count', 'stale_evidence', 'recommended_provider',
            'recommended_model', 'mode', 'actionable', 'requires_human_review',
            'reason', 'rationale', 'recommendation_hash',
        ] as $k) {
            $this->assertArrayHasKey($k, $rec);
        }
        $this->assertSame('react', $rec['scope']['framework']);
    }

    public function test_rivals_advisory_map_is_honest_empty_with_ledger_retired(): void
    {
        $map = $this->buildService()->rivalsAdvisoryMap();

        $this->assertSame(AtlasDecideMetaLearningService::ADVISORY_MAP_SCHEMA, $map['schema_version']);
        $this->assertNull($map['source_schema_version']);
        $this->assertSame(AtlasDecideMetaLearningService::SIGNAL_INSUFFICIENT, $map['source_signal']);
        $this->assertSame(0, $map['segment_count']);
        $this->assertSame([], $map['segments']);
        $this->assertTrue($map['advisory_only']);
        $this->assertFalse($map['should_update_provider_topology']);
        $this->assertTrue($map['never_changes_atlas_decide_topology']);
        $this->assertSame('atlas_decide', $map['owner_of_model_routing']);
        $this->assertSame('none', $map['routing_effect']);
        $this->assertFalse($map['external_provider_call']);
        $this->assertFalse($map['provider_tokens_spent']);
        $this->assertFalse($map['claim_ready']);
        $this->assertFalse($map['external_claim_allowed']);
        $this->assertStringStartsWith('sha256:', $map['advisory_map_hash']);
    }

    public function test_cost_outcome_routing_selects_cheaper_certified_m3_and_activates_from_live_feedback(): void
    {
        config(['atlas.patamar4.adml_cost_outcome' => $this->costOutcomeConfigEnabled()]);

        $svc = $this->buildService();
        $svc->setLiveOutcomeFeedback($this->seededFeedback());

        $rec = $svc->recommend([
            'task_category' => 'bugfix',
            'role' => 'repair_agent',
            'framework' => 'python',
        ]);

        $this->assertTrue($rec['actionable']);
        $this->assertSame(AtlasDecideMetaLearningService::ROUTING_BASIS_COST_OUTCOME, $rec['routing_basis']);
        $this->assertSame('minimax_m27_cli', $rec['recommended_provider']);
        $this->assertSame('MiniMax-M3', $rec['recommended_model']);
        $this->assertSame('codex_cli', $rec['fallback_provider']);
        $this->assertGreaterThan(80, $rec['estimated_savings_pct']);
        $this->assertSame('ready', $rec['cost_outcome']['status']);
        $this->assertSame(1.0, $rec['cost_outcome']['selected']['certification_rate']);

        $receipt = $svc->applyAction([
            'action' => AtlasDecideMetaLearningService::ACTION_ACTIVATE,
            'task_category' => 'bugfix',
            'role' => 'repair_agent',
            'framework' => 'python',
            'actor' => 'operator-test',
        ]);
        $this->assertSame('activate', $receipt['action']);
        $this->assertSame(AtlasDecideMetaLearningService::ROUTING_BASIS_COST_OUTCOME, $receipt['routing_basis']);

        $table = $svc->routingTable();
        $this->assertSame(1, $table['active_entries']);
        $this->assertSame('minimax_m27_cli', $table['entries'][0]['provider']);
        $this->assertSame(AtlasDecideMetaLearningService::ROUTING_BASIS_COST_OUTCOME, $table['entries'][0]['routing_basis']);

        $route = $svc->activeRouteFor('bugfix', 'repair_agent', 'python');
        $this->assertSame('minimax_m27_cli', $route['provider']);
        $this->assertSame('codex_cli', $route['fallback_provider']);
    }

    public function test_cost_outcome_routing_blocks_without_measured_cost(): void
    {
        config(['atlas.patamar4.adml_cost_outcome' => $this->costOutcomeConfigEnabled()]);

        $feedback = new AtlasDecideLiveOutcomeFeedbackService;
        $feedback->setLogPathForTesting($this->tmpRoot.'/live_outcomes.jsonl');
        for ($i = 0; $i < 3; $i++) {
            $feedback->record([
                'task_category' => 'bugfix',
                'role' => 'repair_agent',
                'framework' => 'python',
                'provider' => 'minimax_m27_cli',
                'model' => 'MiniMax-M3',
                'result' => AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS,
                'quality_score' => 0.9,
                'tokens_used' => 300,
                'actor' => 'ai_worker',
            ]);
        }

        $svc = $this->buildService();
        $svc->setLiveOutcomeFeedback($feedback);
        $rec = $svc->recommend([
            'task_category' => 'bugfix',
            'role' => 'repair_agent',
            'framework' => 'python',
        ]);

        $this->assertFalse($rec['actionable']);
        $this->assertSame('blocked', $rec['cost_outcome']['status']);
        $this->assertContains('cost_outcome_missing_measured_cost', $rec['reason']);
    }

    public function test_cost_outcome_routing_can_use_live_feedback_with_measured_cost_and_quality(): void
    {
        config(['atlas.patamar4.adml_cost_outcome' => $this->costOutcomeConfigEnabled()]);

        $svc = $this->buildService();
        $svc->setLiveOutcomeFeedback($this->seededFeedback());

        $rec = $svc->recommend([
            'task_category' => 'bugfix',
            'role' => 'repair_agent',
            'framework' => 'python',
        ]);

        $this->assertTrue($rec['actionable']);
        $this->assertSame(AtlasDecideMetaLearningService::ROUTING_BASIS_COST_OUTCOME, $rec['routing_basis']);
        $this->assertSame('minimax_m27_cli', $rec['recommended_provider']);
        $this->assertSame('MiniMax-M3', $rec['recommended_model']);
        $this->assertSame('codex_cli', $rec['fallback_provider']);
        $this->assertSame('ready', $rec['cost_outcome']['status']);
        $this->assertContains('live_outcome_feedback', $rec['cost_outcome']['selected']['evidence_sources']);
        $this->assertSame(0.004, $rec['cost_outcome']['selected']['average_cost_estimate']);
        $this->assertSame(89.2, $rec['cost_outcome']['selected']['average_score']);
        $this->assertGreaterThan(80, $rec['estimated_savings_pct']);
    }

    // ── degradation_reasons (precise auditable map per active route) ─────────

    /**
     * Activate the bugfix/repair_agent route from live-feedback cost-outcome
     * evidence (the rivals ledger is retired). The activation feedback file is
     * separate from the degradation file the tests feed later: the memoized
     * cost-outcome router keeps reading the activation evidence, while the
     * sweep's degradationSignal reads the fresh degradation feed.
     */
    private function activateBugfixRepairAgentRoute(): AtlasDecideMetaLearningService
    {
        config(['atlas.patamar4.adml_cost_outcome' => $this->costOutcomeConfigEnabled()]);

        $svc = $this->buildService();
        $svc->setLiveOutcomeFeedback($this->seededFeedback('live_outcomes_activation.jsonl'));
        $svc->applyAction([
            'action' => AtlasDecideMetaLearningService::ACTION_ACTIVATE,
            'task_category' => 'bugfix',
            'role' => 'repair_agent',
            'framework' => 'python',
            'actor' => 'operator-test',
        ]);

        return $svc;
    }

    public function test_degrading_active_route_deactivates_with_full_degradation_reasons(): void
    {
        $svc = $this->activateBugfixRepairAgentRoute();

        $feedback = new AtlasDecideLiveOutcomeFeedbackService;
        $feedback->setLogPathForTesting($this->tmpRoot.'/live_outcomes.jsonl');
        // 1 success + 4 failures = 0.2 success rate → below BROKEN_THRESHOLD (0.4).
        $feedback->record(['task_category' => 'bugfix', 'role' => 'repair_agent', 'framework' => 'python', 'provider' => 'minimax_m27_cli', 'model' => 'MiniMax-M3', 'result' => AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS]);
        for ($i = 0; $i < 4; $i++) {
            $feedback->record(['task_category' => 'bugfix', 'role' => 'repair_agent', 'framework' => 'python', 'provider' => 'minimax_m27_cli', 'model' => 'MiniMax-M3', 'result' => AtlasDecideLiveOutcomeFeedbackService::RESULT_FAILURE]);
        }

        $svc->setLiveOutcomeFeedback($feedback);
        $sweep = $svc->autoDeactivateOnDegradation('test-actor');

        $this->assertSame(1, $sweep['deactivated_count']);
        $row = $sweep['deactivated'][0];
        $this->assertArrayHasKey('degradation_reasons', $row);
        $reasons = $row['degradation_reasons'];
        $this->assertSame(0.2, $reasons['success_rate']);
        $this->assertSame(AtlasDecideLiveOutcomeFeedbackService::BROKEN_THRESHOLD, $reasons['minimum_success_rate']);
        $this->assertSame(5, $reasons['sample_count']);
        $this->assertArrayHasKey('stale_data', $reasons);
        $this->assertSame(AtlasDecideMetaLearningService::ACTION_DEACTIVATE, $reasons['recommended_action']);
    }

    public function test_insufficient_live_samples_kept_not_deactivated(): void
    {
        $svc = $this->activateBugfixRepairAgentRoute();

        $feedback = new AtlasDecideLiveOutcomeFeedbackService;
        $feedback->setLogPathForTesting($this->tmpRoot.'/live_outcomes.jsonl');
        // Only 2 outcomes recorded — below MIN_CALLS_FOR_SIGNAL (5) — must stay shadow/unchanged.
        $feedback->record(['task_category' => 'bugfix', 'role' => 'repair_agent', 'framework' => 'python', 'provider' => 'minimax_m27_cli', 'model' => 'MiniMax-M3', 'result' => AtlasDecideLiveOutcomeFeedbackService::RESULT_FAILURE]);
        $feedback->record(['task_category' => 'bugfix', 'role' => 'repair_agent', 'framework' => 'python', 'provider' => 'minimax_m27_cli', 'model' => 'MiniMax-M3', 'result' => AtlasDecideLiveOutcomeFeedbackService::RESULT_FAILURE]);

        $svc->setLiveOutcomeFeedback($feedback);
        $sweep = $svc->autoDeactivateOnDegradation('test-actor');

        $this->assertSame(0, $sweep['deactivated_count']);
        $this->assertSame(1, $sweep['kept_count']);
        $kept = $sweep['kept'][0];
        $this->assertTrue($kept['insufficient_live_outcome_samples']);
        $this->assertSame(AtlasDecideLiveOutcomeFeedbackService::SIGNAL_INSUFFICIENT_EVIDENCE, $kept['signal']);

        // Route must still be active (unchanged) — not deactivated prematurely.
        $route = $svc->activeRouteFor('bugfix', 'repair_agent', 'python');
        $this->assertNotNull($route);
    }
}
