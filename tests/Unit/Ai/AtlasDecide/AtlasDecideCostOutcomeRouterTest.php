<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AtlasDecide;

use App\Services\Ai\AtlasDecide\AtlasDecideCostOutcomeRouter;
use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsProviderPerformanceLedgerService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsRunPathResolver;
use Tests\TestCase;

class AtlasDecideCostOutcomeRouterTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir().'/atlas_cost_outcome_'.uniqid('', true);
        @mkdir($this->tmpRoot.'/ledger', 0775, true);
        config(['atlas_rivals.ledger_root' => $this->tmpRoot.'/ledger']);
        config(['atlas.patamar4.adml_cost_outcome' => []]);
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

    private function buildRouter(): AtlasDecideCostOutcomeRouter
    {
        $paths = new AtlasForgeRivalsRunPathResolver;
        $ledger = new AtlasForgeRivalsProviderPerformanceLedgerService($paths);

        return new AtlasDecideCostOutcomeRouter(
            $ledger,
            null,
            static fn ($p) => is_string($p) && $p !== '' ? $p : null,
            static fn ($provider, $model) => is_string($model) && $model !== '' ? $model : null,
            static fn ($p) => in_array($p, ['codex_cli', 'claude_cli', 'minimax'], true),
            static fn ($v) => is_numeric($v) ? (float) $v : null,
        );
    }

    /** @return array<string,mixed> */
    private function certifiedEntry(string $provider, string $model, float $score = 90.0, float $cost = 0.05, string $runId = 'r1'): array
    {
        return [
            'provider' => $provider,
            'model' => $model,
            'valid_for_ranking' => true,
            'tests_passed' => true,
            'replay_passed' => true,
            'hard_failures' => [],
            'score_total' => $score,
            'cost_estimate' => $cost,
            'recorded_at' => '2026-06-01T00:00:00+00:00',
            'run_id' => $runId,
            'evidence_source' => 'forge_rivals_provider_performance_ledger',
        ];
    }

    private function cfg(array $overrides = []): array
    {
        return array_merge([
            'enabled' => true,
            'min_evidence' => 3,
            'min_certification_rate' => 0.8,
            'min_score' => 80.0,
            'max_score_drop' => 3.0,
            'require_measured_cost' => true,
            'min_cost_samples' => 1,
        ], $overrides);
    }

    // ── isCertifiedCostOutcomeEntry: forge_rivals source ─────────────────────

    public function test_certified_entry_when_all_flags_set(): void
    {
        $router = $this->buildRouter();

        $this->assertTrue($router->isCertifiedCostOutcomeEntry($this->certifiedEntry('codex_cli', 'gpt-5.5')));
    }

    public function test_not_certified_when_valid_for_ranking_false(): void
    {
        $router = $this->buildRouter();
        $entry = $this->certifiedEntry('codex_cli', 'gpt-5.5');
        $entry['valid_for_ranking'] = false;

        $this->assertFalse($router->isCertifiedCostOutcomeEntry($entry));
    }

    public function test_not_certified_when_tests_passed_false(): void
    {
        $router = $this->buildRouter();
        $entry = $this->certifiedEntry('codex_cli', 'gpt-5.5');
        $entry['tests_passed'] = false;

        $this->assertFalse($router->isCertifiedCostOutcomeEntry($entry));
    }

    public function test_not_certified_when_hard_failures_present(): void
    {
        $router = $this->buildRouter();
        $entry = $this->certifiedEntry('codex_cli', 'gpt-5.5');
        $entry['hard_failures'] = ['assertion_failure'];

        $this->assertFalse($router->isCertifiedCostOutcomeEntry($entry));
    }

    // ── isCertifiedCostOutcomeEntry: live_outcome_feedback source ─────────────

    public function test_live_feedback_certified_when_success_and_numeric_scores(): void
    {
        $router = $this->buildRouter();
        $entry = [
            'evidence_source' => 'live_outcome_feedback',
            'result' => AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS,
            'quality_score' => 0.9,
            'score_total' => 90.0,
            'hard_failures' => [],
        ];

        $this->assertTrue($router->isCertifiedCostOutcomeEntry($entry));
    }

    public function test_live_feedback_not_certified_when_result_failure(): void
    {
        $router = $this->buildRouter();
        $entry = [
            'evidence_source' => 'live_outcome_feedback',
            'result' => AtlasDecideLiveOutcomeFeedbackService::RESULT_FAILURE,
            'quality_score' => 0.9,
            'score_total' => 90.0,
            'hard_failures' => [],
        ];

        $this->assertFalse($router->isCertifiedCostOutcomeEntry($entry));
    }

    public function test_live_feedback_not_certified_when_quality_score_missing(): void
    {
        $router = $this->buildRouter();
        $entry = [
            'evidence_source' => 'live_outcome_feedback',
            'result' => AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS,
            'quality_score' => null,
            'score_total' => 90.0,
            'hard_failures' => [],
        ];

        $this->assertFalse($router->isCertifiedCostOutcomeEntry($entry));
    }

    // ── costOutcomeRoute: disabled ────────────────────────────────────────────

    public function test_route_disabled_returns_disabled_status(): void
    {
        config(['atlas.patamar4.adml_cost_outcome.enabled' => false]);
        $router = $this->buildRouter();

        $result = $router->costOutcomeRoute('backend', 'builder', null, 'schema.v1');

        $this->assertSame('disabled', $result['status']);
        $this->assertFalse($result['enabled']);
        $this->assertFalse($result['external_provider_call']);
        $this->assertFalse($result['provider_tokens_spent']);
    }

    // ── costOutcomeRoute: empty ledger ────────────────────────────────────────

    public function test_route_blocked_when_no_evidence(): void
    {
        config(['atlas.patamar4.adml_cost_outcome.enabled' => true]);
        $router = $this->buildRouter();

        $result = $router->costOutcomeRoute('backend', 'builder', null, 'schema.v1');

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('no_relevant_cost_outcome_evidence', $result['blockers']);
        $this->assertSame(0, $result['candidate_count']);
    }

    // ── evidence_deficit: new field ───────────────────────────────────────────

    public function test_evidence_deficit_equals_gap_to_min_evidence(): void
    {
        $router = $this->buildRouter();
        $cfg = $this->cfg(['min_evidence' => 3]);

        // 2 certified entries → deficit = 1
        $entries = [
            $this->certifiedEntry('codex_cli', 'gpt-5.5', runId: 'r1'),
            $this->certifiedEntry('codex_cli', 'gpt-5.5', runId: 'r2'),
        ];

        $candidates = $router->costOutcomeCandidates($entries, $cfg);

        $this->assertCount(1, $candidates);
        $this->assertSame(1, $candidates[0]['evidence_deficit']);
    }

    public function test_evidence_deficit_zero_when_certified_count_meets_minimum(): void
    {
        $router = $this->buildRouter();
        $cfg = $this->cfg(['min_evidence' => 3]);

        $entries = [
            $this->certifiedEntry('codex_cli', 'gpt-5.5', runId: 'r1'),
            $this->certifiedEntry('codex_cli', 'gpt-5.5', runId: 'r2'),
            $this->certifiedEntry('codex_cli', 'gpt-5.5', runId: 'r3'),
        ];

        $candidates = $router->costOutcomeCandidates($entries, $cfg);

        $this->assertSame(0, $candidates[0]['evidence_deficit']);
    }

    public function test_evidence_deficit_zero_when_certified_count_exceeds_minimum(): void
    {
        $router = $this->buildRouter();
        $cfg = $this->cfg(['min_evidence' => 2]);

        $entries = [
            $this->certifiedEntry('codex_cli', 'gpt-5.5', runId: 'r1'),
            $this->certifiedEntry('codex_cli', 'gpt-5.5', runId: 'r2'),
            $this->certifiedEntry('codex_cli', 'gpt-5.5', runId: 'r3'),
        ];

        $candidates = $router->costOutcomeCandidates($entries, $cfg);

        $this->assertSame(0, $candidates[0]['evidence_deficit']);
    }

    public function test_evidence_deficit_per_candidate_independently(): void
    {
        $router = $this->buildRouter();
        $cfg = $this->cfg(['min_evidence' => 3]);

        $entries = [
            // codex_cli: 1 certified → deficit 2
            $this->certifiedEntry('codex_cli', 'gpt-5.5', runId: 'r1'),
            // claude_cli: 3 certified → deficit 0
            $this->certifiedEntry('claude_cli', 'claude-opus', runId: 'r2'),
            $this->certifiedEntry('claude_cli', 'claude-opus', runId: 'r3'),
            $this->certifiedEntry('claude_cli', 'claude-opus', runId: 'r4'),
        ];

        $candidates = $router->costOutcomeCandidates($entries, $cfg);
        $byProvider = [];
        foreach ($candidates as $c) {
            $byProvider[$c['provider']] = $c['evidence_deficit'];
        }

        $this->assertSame(2, $byProvider['codex_cli']);
        $this->assertSame(0, $byProvider['claude_cli']);
    }
}
