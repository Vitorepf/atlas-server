<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AtlasDecide;

use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\AtlasDecide\AtlasDecideMetaLearningService;
use Tests\TestCase;

/**
 * GOAL 2 · SLICE 2 — Learning Loop AMPLO. The Decision Core's activation regime must
 * weight ONLY proven_real outcomes (the Goal 1 marker), never fake-green. This proves the
 * MECHANISM (better proven outcome → preferred route); the real improvement is measured on
 * the cadence, never fabricated.
 *
 * Scenario: claude_cli logs 10 fake-green "successes" (result=success, proven_real=false);
 * hermes_cli logs 8 PROVEN successes + 2 failures. Legacy success-rate would crown the
 * fake-green claude_cli (1.0 > 0.8). The proven regime flips the preference to hermes_cli
 * (proven 0.8 > 0.0) — a measurable routing shift driven purely by proven outcome.
 */
final class AtlasDecideProvenOutcomeShiftTest extends TestCase
{
    private string $tmpRoot;

    private AtlasDecideLiveOutcomeFeedbackService $feedback;

    private AtlasDecideMetaLearningService $adml;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir().'/atlas_proven_shift_'.uniqid('', true);
        @mkdir($this->tmpRoot, 0775, true);
        config(['atlas.patamar4.adml_cost_outcome.enabled' => false]);
        config(['atlas.patamar4.adml_auto_activation_enabled' => false]);

        $this->feedback = new AtlasDecideLiveOutcomeFeedbackService;
        $this->feedback->setLogPathForTesting($this->tmpRoot.'/live_outcomes.jsonl');
        $this->app->instance(AtlasDecideLiveOutcomeFeedbackService::class, $this->feedback);

        $this->adml = $this->app->make(AtlasDecideMetaLearningService::class);
        $this->adml->setActivationLogPathForTesting($this->tmpRoot.'/routing_activations.jsonl');
        $this->adml->setLiveOutcomeFeedback($this->feedback);

        // claude_cli: 10 fake-green "successes" (never proven). hermes_cli: 8 proven + 2 fail.
        $this->record('claude_cli', 'success', 10, provenReal: false);
        $this->record('hermes_cli', 'success', 8, provenReal: true);
        $this->record('hermes_cli', 'failure', 2, provenReal: false);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpRoot.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpRoot);
        parent::tearDown();
    }

    private function record(string $provider, string $result, int $times, bool $provenReal): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->feedback->record([
                'task_category' => 'programming',
                'role' => 'repair',
                'provider' => $provider,
                'model' => $provider.'-default-model',
                'result' => $result,
                'proven_real' => $provenReal,
                'actor' => 'atlas_dev_pipeline',
            ]);
        }
    }

    public function test_fake_green_success_contributes_zero_proven_weight(): void
    {
        $stats = $this->feedback->routeStats('programming', 'repair');
        $byProvider = [];
        foreach ($stats['providers'] as $p) {
            $byProvider[$p['provider']] = $p;
        }

        // claude_cli looks perfect on raw success but is worth NOTHING once proven-gated.
        $this->assertSame(10, $byProvider['claude_cli']['success']);
        $this->assertSame(0, $byProvider['claude_cli']['proven_success']);
        $this->assertSame(0.0, $byProvider['claude_cli']['proven_success_rate']);

        $this->assertSame(8, $byProvider['hermes_cli']['proven_success']);
        $this->assertSame(0.8, $byProvider['hermes_cli']['proven_success_rate']);
    }

    public function test_legacy_signal_prefers_the_fake_green_inflated_provider(): void
    {
        // Pre-Goal-2 hole: raw success rate crowns the fake-green route.
        $rec = $this->adml->recommend(['task_category' => 'programming', 'role' => 'repair']);
        $this->assertTrue((bool) $rec['actionable'], json_encode($rec['reason'] ?? []));
        $this->assertSame('claude_cli', $rec['recommended_provider']);
    }

    public function test_proven_only_shifts_routing_to_the_really_proven_provider(): void
    {
        // The shift: proven-gated evidence flips the preference to the truly-proven route.
        $rec = $this->adml->recommend(['task_category' => 'programming', 'role' => 'repair', 'proven_only' => true]);
        $this->assertTrue((bool) $rec['actionable'], json_encode($rec['reason'] ?? []));
        $this->assertSame('hermes_cli', $rec['recommended_provider']);
    }

    public function test_activation_regime_consumes_only_proven_and_is_default_safe(): void
    {
        // Default OFF: no-op, nothing activated.
        $off = $this->adml->autoActivateFromLiveEvidence('proven_shift_test');
        $this->assertFalse($off['enabled']);
        $this->assertSame([], $off['activated']);
        $this->assertNull($this->adml->activeRouteFor('programming', 'repair'));

        // Flag ON: the activation regime weights ONLY proven outcome → activates hermes_cli,
        // NEVER the fake-green claude_cli. Audited via the activation receipt.
        config(['atlas.patamar4.adml_auto_activation_enabled' => true]);
        $on = $this->adml->autoActivateFromLiveEvidence('proven_shift_test');
        $this->assertTrue($on['enabled']);
        $this->assertCount(1, $on['activated'], json_encode($on['skipped']));
        $this->assertSame('hermes_cli', $on['activated'][0]['provider']);
        $this->assertSame('hermes_cli', $this->adml->activeRouteFor('programming', 'repair')['provider']);

        // Activation receipt persisted (audit trail).
        $this->assertFileExists($this->tmpRoot.'/routing_activations.jsonl');
    }
}
