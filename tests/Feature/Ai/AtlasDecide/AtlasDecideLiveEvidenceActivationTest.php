<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AtlasDecide;

use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\AtlasDecide\AtlasDecideMetaLearningService;
use Tests\TestCase;

/**
 * Live-evidence route activation: the offline ledger is retired, so recommend()
 * must derive its signal from the LIVE outcome ledger (real Dev runs recorded
 * per (task_category, role)) — closing free_to_choose → follow_learned on
 * evidence instead of a dead feed. Fail-closed floors: MIN_CALLS_FOR_SIGNAL
 * samples and success rate >= the degradation threshold, else the signal stays
 * honestly insufficient (never activate a route the sweep would flag).
 */
final class AtlasDecideLiveEvidenceActivationTest extends TestCase
{
    private string $tmpRoot;

    private AtlasDecideLiveOutcomeFeedbackService $feedback;

    private AtlasDecideMetaLearningService $adml;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir().'/atlas_live_act_'.uniqid('', true);
        @mkdir($this->tmpRoot, 0775, true);
        config(['atlas_rivals.ledger_root' => $this->tmpRoot.'/ledger']);
        // HERMETIC: the live .env may enable the cost-outcome router (which vetoes
        // actionability without measured cost); this suite tests the live-evidence
        // signal, not cost routing.
        config(['atlas.patamar4.adml_cost_outcome.enabled' => false]);

        $this->feedback = new AtlasDecideLiveOutcomeFeedbackService;
        $this->feedback->setLogPathForTesting($this->tmpRoot.'/live_outcomes.jsonl');
        $this->app->instance(AtlasDecideLiveOutcomeFeedbackService::class, $this->feedback);

        $this->adml = $this->app->make(AtlasDecideMetaLearningService::class);
        $this->adml->setActivationLogPathForTesting($this->tmpRoot.'/routing_activations.jsonl');
        $this->adml->setLiveOutcomeFeedback($this->feedback);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpRoot.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpRoot);
        parent::tearDown();
    }

    private function record(string $provider, string $result, int $times, string $role = 'repair'): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->feedback->record([
                'task_category' => 'programming',
                'role' => $role,
                'provider' => $provider,
                'model' => $provider.'-default-model',
                'result' => $result,
                'actor' => 'atlas_dev_pipeline',
            ]);
        }
    }

    public function test_strong_live_evidence_makes_recommendation_actionable_and_activatable(): void
    {
        // hermes_cli: 10/10 green in the window; claude_cli: 3/6 (below floor).
        $this->record('hermes_cli', 'success', 10);
        $this->record('claude_cli', 'success', 3);
        $this->record('claude_cli', 'failure', 3);

        $rec = $this->adml->recommend(['task_category' => 'programming', 'role' => 'repair']);

        $this->assertTrue((bool) $rec['actionable'], json_encode($rec['reason'] ?? []));
        $this->assertSame('hermes_cli', $rec['recommended_provider']);

        $receipt = $this->adml->applyAction([
            'action' => AtlasDecideMetaLearningService::ACTION_ACTIVATE,
            'task_category' => 'programming',
            'role' => 'repair',
            'actor' => 'live_evidence_test',
        ]);
        $this->assertSame('active', $receipt['new_mode']);

        $route = $this->adml->activeRouteFor('programming', 'repair');
        $this->assertNotNull($route, 'activation folds into the live routing table');
        $this->assertSame('hermes_cli', $route['provider']);
    }

    public function test_insufficient_samples_stay_honestly_not_actionable(): void
    {
        $this->record('hermes_cli', 'success', 3); // below MIN_CALLS_FOR_SIGNAL

        $rec = $this->adml->recommend(['task_category' => 'programming', 'role' => 'repair']);

        $this->assertFalse((bool) $rec['actionable']);
        $this->assertContains('insufficient_evidence', (array) $rec['reason']);
    }

    public function test_success_rate_below_degradation_floor_is_never_recommended(): void
    {
        // 3/6 = 0.5 < DEGRADATION_THRESHOLD (0.7): a route the sweep would flag
        // as degrading must never come back as an activation recommendation.
        $this->record('hermes_cli', 'success', 3);
        $this->record('hermes_cli', 'failure', 3);

        $rec = $this->adml->recommend(['task_category' => 'programming', 'role' => 'repair']);

        $this->assertFalse((bool) $rec['actionable']);
    }

    public function test_close_live_race_stays_not_actionable(): void
    {
        // 0.75 vs 0.75 — a dead-heat race must not activate either provider.
        $this->record('hermes_cli', 'success', 6);
        $this->record('hermes_cli', 'failure', 2);
        $this->record('claude_cli', 'success', 6);
        $this->record('claude_cli', 'failure', 2);

        $rec = $this->adml->recommend(['task_category' => 'programming', 'role' => 'repair']);

        $this->assertFalse((bool) $rec['actionable']);
        $this->assertContains('close_race_runner_up_in_play', (array) $rec['reason']);
    }
}
