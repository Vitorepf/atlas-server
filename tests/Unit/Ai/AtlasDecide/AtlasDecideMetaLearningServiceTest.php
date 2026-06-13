<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AtlasDecide;

use App\Services\Ai\AtlasDecide\AtlasDecideMetaLearningService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsDecideSignalProjectionService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsProviderPerformanceLedgerService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsRunPathResolver;
use Tests\TestCase;

/**
 * Unit tests for Atlas Decide · Meta-Learning Loop Closure.
 *
 * Uses the REAL projection + ledger services. The ledger is pointed at a
 * temporary directory so no real provider data is touched. No mocks.
 */
class AtlasDecideMetaLearningServiceTest extends TestCase
{
    private string $tmpRoot;

    private string $activationLog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir().'/atlas_meta_learning_'.uniqid('', true);
        @mkdir($this->tmpRoot.'/runs', 0775, true);
        @mkdir($this->tmpRoot.'/ledger', 0775, true);
        // Point the rivals runs root at our temp dir so the ledger reads from there.
        config(['atlas_rivals.runs_root' => $this->tmpRoot.'/runs']);
        config(['atlas_rivals.ledger_root' => $this->tmpRoot.'/ledger']);
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
        $paths = new AtlasForgeRivalsRunPathResolver;
        $ledger = new AtlasForgeRivalsProviderPerformanceLedgerService($paths);
        $projection = new AtlasForgeRivalsDecideSignalProjectionService($ledger);
        $svc = new AtlasDecideMetaLearningService($projection, $ledger);
        $svc->setActivationLogPathForTesting($this->activationLog);

        return $svc;
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

    public function test_recommend_all_returns_well_shaped_list(): void
    {
        $recs = $this->buildService()->recommendAll();
        $this->assertIsArray($recs);
        // Production ledger may have entries; we only validate shape, not count.
        foreach ($recs as $r) {
            $this->assertSame(AtlasDecideMetaLearningService::RECOMMENDATION_SCHEMA, $r['schema_version']);
            $this->assertArrayHasKey('scope', $r);
            $this->assertArrayHasKey('signal', $r);
            $this->assertArrayHasKey('recommendation_hash', $r);
        }
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

    public function test_rivals_advisory_map_exposes_category_difficulty_model_guidance_without_routing_effect(): void
    {
        $this->appendLedgerEntry('backend', 'L5', 'builder', 'anthropic_claude', 'claude_opus', 92.0, 'rivals-map-backend-a');
        $this->appendLedgerEntry('backend', 'L5', 'builder', 'openai_gpt', 'gpt-5.5', 78.0, 'rivals-map-backend-b');
        $this->appendLedgerEntry('frontend', 'L2', 'builder', 'openai_codex', 'codex', 89.0, 'rivals-map-frontend-a');
        $this->appendLedgerEntry('frontend', 'L2', 'builder', 'anthropic_claude', 'claude_sonnet', 70.0, 'rivals-map-frontend-b');

        $map = $this->buildService()->rivalsAdvisoryMap();

        $this->assertSame(AtlasDecideMetaLearningService::ADVISORY_MAP_SCHEMA, $map['schema_version']);
        $this->assertSame('atlas.forge.rivals.decide_model_intelligence_map.v1', $map['source_schema_version']);
        $this->assertSame(2, $map['segment_count']);
        $this->assertTrue($map['advisory_only']);
        $this->assertFalse($map['should_update_provider_topology']);
        $this->assertTrue($map['never_changes_atlas_decide_topology']);
        $this->assertSame('atlas_decide', $map['owner_of_model_routing']);
        $this->assertSame('none', $map['routing_effect']);
        $this->assertFalse($map['external_provider_call']);
        $this->assertFalse($map['provider_tokens_spent']);
        $this->assertFalse($map['claim_ready']);
        $this->assertFalse($map['external_claim_allowed']);
        $this->assertSame('Rivals emits measured evidence; Atlas Decide decides model routing.', $map['canonical_phrase']);
        $this->assertStringStartsWith('sha256:', $map['advisory_map_hash']);

        $backend = $this->segmentFor($map['segments'], 'backend', 'L5', 'builder');
        $this->assertSame('anthropic_claude', $backend['recommended_provider']);
        $this->assertSame('claude_opus', $backend['recommended_model']);
        $this->assertSame(92.0, $backend['average_score']);
        $this->assertSame('material_advantage', $backend['advantage_band']);
        $this->assertFalse($backend['actionable_for_auto_routing']);
        $this->assertSame('shadow', $backend['activation_mode']);
        $this->assertFalse($backend['should_update_provider_topology']);
        $this->assertSame('none', $backend['routing_effect']);

        $frontend = $this->segmentFor($map['segments'], 'frontend', 'L2', 'builder');
        $this->assertSame('openai_codex', $frontend['recommended_provider']);
        $this->assertSame('codex', $frontend['recommended_model']);
        $this->assertSame(89.0, $frontend['average_score']);
    }

    public function test_cost_outcome_routing_selects_cheaper_certified_m3_and_activates_existing_table(): void
    {
        config(['atlas.patamar4.adml_cost_outcome' => [
            'enabled' => true,
            'min_evidence' => 3,
            'min_certification_rate' => 0.8,
            'min_score' => 80.0,
            'max_score_drop' => 3.0,
            'require_measured_cost' => true,
            'min_cost_samples' => 1,
        ]]);

        for ($i = 0; $i < 3; $i++) {
            $this->appendLedgerEntry(
                'bugfix',
                'L2',
                'repair_agent',
                'codex',
                'gpt-5.5',
                91.0,
                'cost-outcome-codex-'.$i,
                framework: 'python',
                costEstimate: 0.04,
                recordedAt: date(DATE_ATOM),
            );
            $this->appendLedgerEntry(
                'bugfix',
                'L2',
                'repair_agent',
                'minimax',
                'MiniMax-M3',
                89.2,
                'cost-outcome-m3-'.$i,
                framework: 'python',
                costEstimate: 0.004,
                recordedAt: date(DATE_ATOM),
            );
        }

        $svc = $this->buildService();
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
        config(['atlas.patamar4.adml_cost_outcome' => [
            'enabled' => true,
            'min_evidence' => 3,
            'min_certification_rate' => 0.8,
            'min_score' => 80.0,
            'max_score_drop' => 3.0,
            'require_measured_cost' => true,
            'min_cost_samples' => 1,
        ]]);

        for ($i = 0; $i < 3; $i++) {
            $this->appendLedgerEntry(
                'bugfix',
                'L2',
                'repair_agent',
                'minimax',
                'MiniMax-M3',
                90.0,
                'cost-missing-m3-'.$i,
                framework: 'python',
                costEstimate: null,
                recordedAt: date(DATE_ATOM),
            );
        }

        $rec = $this->buildService()->recommend([
            'task_category' => 'bugfix',
            'role' => 'repair_agent',
            'framework' => 'python',
        ]);

        $this->assertFalse($rec['actionable']);
        $this->assertSame('blocked', $rec['cost_outcome']['status']);
        $this->assertContains('cost_outcome_missing_measured_cost', $rec['reason']);
    }

    private function appendLedgerEntry(
        string $taskCategory,
        string $difficultyLevel,
        string $role,
        string $provider,
        string $model,
        float $score,
        string $runId,
        ?string $framework = null,
        ?float $costEstimate = 0.02,
        ?string $recordedAt = '2026-05-15T12:00:00+00:00',
    ): void {
        $path = $this->tmpRoot.'/ledger/entries.jsonl';
        $entry = [
            'schema_version' => 'atlas.forge.rivals.provider_performance_ledger_entry.v1',
            'entry_id' => $runId.'-'.$provider.'-'.$model,
            'recorded_at' => $recordedAt,
            'run_id' => $runId,
            'battery_id' => 'rivals-map-test',
            'arena_run_id' => $runId,
            'case_id' => $runId,
            'task_id' => $runId,
            'case_source' => 'test',
            'arm' => str_contains($provider, 'anthropic') ? 'atlas' : 'rival',
            'runner_type' => str_contains($provider, 'anthropic') ? 'atlas_forge' : 'raw_provider',
            'provider' => $provider,
            'model' => $model,
            'task_category' => $taskCategory,
            'difficulty_level' => $difficultyLevel,
            'difficulty_weight' => 3.0,
            'role' => $role,
            'framework' => $framework,
            'mode' => 'fair',
            'preset' => 'test',
            'score_total' => $score,
            'winner' => null,
            'outcome' => 'winner',
            'hard_failures' => [],
            'tests_passed' => true,
            'replay_passed' => true,
            'duration_ms' => 60_000,
            'cost_estimate' => $costEstimate,
            'tokens_used' => 2_000,
            'valid_for_ranking' => true,
            'claim_ready' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
        ];

        file_put_contents($path, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND);
    }

    /**
     * @param  list<array<string,mixed>>  $segments
     * @return array<string,mixed>
     */
    private function segmentFor(array $segments, string $category, string $difficulty, string $role): array
    {
        foreach ($segments as $segment) {
            if (($segment['scope']['task_category'] ?? null) === $category
                && ($segment['scope']['difficulty_level'] ?? null) === $difficulty
                && ($segment['scope']['role'] ?? null) === $role) {
                return $segment;
            }
        }

        $this->fail("Missing advisory map segment {$category}/{$difficulty}/{$role}.");
    }
}
