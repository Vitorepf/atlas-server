<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognitive;

use App\Models\AiLearningProposal;
use App\Services\Ai\Cognitive\Harness\AtlasHarnessFrozenSuite;
use App\Services\Ai\Cognitive\Harness\AtlasHarnessProposalBridge;
use App\Services\Ai\Cognitive\Harness\AtlasHarnessSurface;
use App\Services\Ai\Compounding\AtlasLearningProposalApplier;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AP-819 Obra B — DoD provado por teste:
 *   - edit fora da superfície REJEITADO (G1 estrutural);
 *   - apply+reverse de harness_config provados (never-irreversible);
 *   - harness_config NUNCA auto-aplica (red line / trust ladder);
 *   - cluster real → proposta harness_config na superfície (propose-only);
 *   - suite congelada: split fixo + regra de promoção dupla do paper.
 */
class AtlasHarnessObraBTest extends TestCase
{
    private AtlasHarnessSurface $surface;

    private string $overridesPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->overridesPath = sys_get_temp_dir().'/atlas-harness-overrides-'.bin2hex(random_bytes(4)).'.json';
        $this->surface = new AtlasHarnessSurface;
        $this->surface->setOverridesPathForTesting($this->overridesPath);
        $this->app->instance(AtlasHarnessSurface::class, $this->surface);
    }

    protected function tearDown(): void
    {
        @unlink($this->overridesPath);

        parent::tearDown();
    }

    // ---------- F2′: superfície (G1 estrutural) ----------

    public function test_surface_rejects_edits_outside_the_declared_allowlist(): void
    {
        // O pipeline de sinal NÃO pertence à superfície — inalcançável por construção.
        $this->assertFalse($this->surface->validate('signal_pipeline.failure_classifier', 1)['valid']);
        $this->assertFalse($this->surface->validate('frozen_suite.probe_set', 1)['valid']);
        $this->assertFalse($this->surface->validate('runtime_control.timeout_seconds', 999999)['valid']);
        $this->assertFalse($this->surface->validate('runtime_control.max_attempts', 'two')['valid']);
        $this->assertTrue($this->surface->validate('runtime_control.timeout_seconds', 300)['valid']);
    }

    public function test_expanded_surface_validates_new_knobs_and_keeps_signal_pipeline_out(): void
    {
        // Expansão 1: os 5 botões novos validam dentro dos bounds.
        $this->assertTrue($this->surface->validate('loop_control.max_scenarios_per_task', 16)['valid']);
        $this->assertTrue($this->surface->validate('loop_control.search_patience', 5)['valid']);
        $this->assertTrue($this->surface->validate('loop_control.max_seconds_per_scenario', 900)['valid']);
        $this->assertTrue($this->surface->validate('cache_control.response_cache_ttl_seconds', 7200)['valid']);
        $this->assertTrue($this->surface->validate('runtime_control.sync_bridge_max_execution_seconds', 600)['valid']);
        $this->assertFalse($this->surface->validate('loop_control.max_scenarios_per_task', 999)['valid']);

        // G1 anti-gaming: a janela de coleta do PRÓPRIO SINAL nunca é editável.
        $this->assertFalse($this->surface->validate('observability.failure_feed_window_hours', 48)['valid']);
        foreach ($this->surface->sections() as $section) {
            $this->assertStringNotContainsString('failure_auto_feed', $section['config_path']);
        }
    }

    public function test_expanded_bridge_maps_rate_limit_turn_limit_and_crash_clusters(): void
    {
        config([
            'atlas.ai.retry_delay_seconds' => 300,
            'atlas.ai.providers.hermes_cli.max_turns' => 90,
            'atlas.ai.max_attempts' => 1,
        ]);
        $bridge = new AtlasHarnessProposalBridge(
            $this->createStub(\App\Services\Ai\Cognitive\Failure\FailureSignatureRepository::class),
            $this->surface,
        );
        $map = new \ReflectionMethod($bridge, 'mapClusterToSurface');

        $rate = $map->invoke($bridge, ['signature_key' => 'fsig_rate_limit_exceeded_429', 'domain' => 'engineering']);
        $this->assertSame('runtime_control.retry_delay_seconds', $rate['key']);
        $this->assertSame(600, $rate['proposed']);

        $turns = $map->invoke($bridge, ['signature_key' => 'fsig_hermes_max_turns_hit', 'domain' => 'engineering']);
        $this->assertSame('provider_policy.hermes_max_turns', $turns['key']);
        $this->assertSame(120, $turns['proposed']);

        $crash = $map->invoke($bridge, ['signature_key' => 'fsig_provider_exception_crash', 'domain' => 'engineering']);
        $this->assertSame('runtime_control.max_attempts', $crash['key']);
        $this->assertSame(2, $crash['proposed'], '+1 incremental, não multiplicador');

        $this->assertNull($map->invoke($bridge, ['signature_key' => 'fsig_unknown_weirdness', 'domain' => 'engineering']));
    }

    // ---------- applier: apply + reverse (never-irreversible) ----------

    public function test_harness_config_apply_and_reverse_roundtrip_changes_and_restores_config(): void
    {
        config(['atlas.ai.timeout_seconds' => 600]);
        $applier = app(AtlasLearningProposalApplier::class);

        $proposal = (new AiLearningProposal)->forceFill([
            'status' => 'approved',
            'kind' => 'harness_config',
            'proposed_state' => ['key' => 'runtime_control.timeout_seconds', 'value' => 900],
        ]);

        $applied = $applier->apply($proposal, 'vitor');
        $this->assertTrue($applied['applied'], 'reason: '.(string) $applied['reason']);
        $this->assertTrue($applied['reversible']);
        $this->assertSame(900, config('atlas.ai.timeout_seconds'));
        $this->assertSame(600, $applied['change']['previous']);
        $this->assertArrayHasKey('runtime_control.timeout_seconds', $this->surface->readOverrides());

        $reversed = $applier->reverse($proposal, 'vitor');
        $this->assertTrue($reversed['reversed']);
        $this->assertSame(600, config('atlas.ai.timeout_seconds'));
        $this->assertSame([], $this->surface->readOverrides());
    }

    public function test_applier_rejects_out_of_surface_key_and_unapproved_proposals(): void
    {
        $applier = app(AtlasLearningProposalApplier::class);

        $outOfSurface = (new AiLearningProposal)->forceFill([
            'status' => 'approved',
            'kind' => 'harness_config',
            'proposed_state' => ['key' => 'signal_pipeline.failure_classifier', 'value' => 1],
        ]);
        $result = $applier->apply($outOfSurface, 'vitor');
        $this->assertFalse($result['applied']);
        $this->assertSame('harness_config_rejected_by_surface', $result['reason']);

        $unapproved = (new AiLearningProposal)->forceFill([
            'status' => 'proposed',
            'kind' => 'harness_config',
            'proposed_state' => ['key' => 'runtime_control.timeout_seconds', 'value' => 900],
        ]);
        $this->assertFalse($applier->apply($unapproved, 'vitor')['applied']);
    }

    public function test_harness_config_never_auto_applies(): void
    {
        $this->assertFalse(app(AtlasLearningProposalApplier::class)->supportsAutoApply('harness_config'));
    }

    public function test_boot_overlay_reapplies_only_valid_entries(): void
    {
        file_put_contents($this->overridesPath, json_encode([
            'runtime_control.timeout_seconds' => ['value' => 750, 'previous' => 600],
            'signal_pipeline.hacked' => ['value' => 1, 'previous' => 0],
            'runtime_control.max_attempts' => ['value' => 99, 'previous' => 1],
        ], JSON_THROW_ON_ERROR));

        config(['atlas.ai.timeout_seconds' => 600, 'atlas.ai.max_attempts' => 1]);
        $result = $this->surface->bootOverlay();

        $this->assertSame(1, $result['applied']);
        $this->assertSame(2, $result['skipped'], 'fora-da-superfície e fora-dos-bounds são IGNORADOS');
        $this->assertSame(750, config('atlas.ai.timeout_seconds'));
        $this->assertSame(1, config('atlas.ai.max_attempts'));
    }

    // ---------- F4′: suite congelada + regra de promoção dupla ----------

    public function test_frozen_suite_runs_green_on_the_real_harness_and_seals_baseline(): void
    {
        $suite = new AtlasHarnessFrozenSuite;
        $suite->setBaselinePathForTesting(sys_get_temp_dir().'/atlas-harness-baseline-'.bin2hex(random_bytes(4)).'.json');

        $evaluation = $suite->evaluate();
        $this->assertSame(1.0, $evaluation['held_in']['pass_rate'], json_encode($evaluation['held_in']['results']));
        $this->assertSame(1.0, $evaluation['held_out']['pass_rate'], json_encode($evaluation['held_out']['results']));

        $suite->sealBaseline();
        $verdict = $suite->promotionVerdict();
        $this->assertFalse($verdict['promote'], 'estado idêntico ao baseline ⇒ no_gain, nunca promote');
        $this->assertSame('no_gain', $verdict['verdict']);

        @unlink($suite->baselinePath());
    }

    public function test_double_non_regression_promotion_rule(): void
    {
        $suite = new AtlasHarnessFrozenSuite;

        // ganho em um split sem regressão no outro ⇒ promove.
        $this->assertTrue($suite->ruleAllowsPromotion(['held_in' => 0.8, 'held_out' => 0.8], ['held_in' => 0.9, 'held_out' => 0.8]));
        // regressão em QUALQUER split ⇒ rejeita, mesmo com ganho no outro.
        $this->assertFalse($suite->ruleAllowsPromotion(['held_in' => 0.8, 'held_out' => 0.8], ['held_in' => 1.0, 'held_out' => 0.7]));
        // sem ganho algum ⇒ rejeita (max > 0 obrigatório).
        $this->assertFalse($suite->ruleAllowsPromotion(['held_in' => 0.8, 'held_out' => 0.8], ['held_in' => 0.8, 'held_out' => 0.8]));
    }

    public function test_promotion_verdict_rejects_baseline_from_another_suite_version(): void
    {
        $suite = new AtlasHarnessFrozenSuite;
        $path = sys_get_temp_dir().'/atlas-harness-baseline-'.bin2hex(random_bytes(4)).'.json';
        $suite->setBaselinePathForTesting($path);

        $sealed = $suite->sealBaseline();
        $tampered = $sealed;
        $tampered['suite_hash'] = 'forged-'.$sealed['suite_hash'];
        file_put_contents($path, json_encode($tampered, JSON_THROW_ON_ERROR));

        $verdict = $suite->promotionVerdict();
        $this->assertFalse($verdict['promote']);
        $this->assertSame('suite_hash_mismatch', $verdict['verdict']);

        @unlink($path);
    }

    // ---------- F2′: ponte cluster→proposta (propose-only) ----------

    public function test_bridge_creates_harness_config_proposal_from_real_decision_expired_cluster(): void
    {
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_07_160000_create_failure_signatures_table.php'))->up();
        (require database_path('migrations/2026_05_19_030000_strengthen_rag_feedback_and_create_learning_proposals.php'))->up();

        try {
            config(['atlas.ai.decision_receipt_ttl_seconds' => 7200]);

            DB::table('failure_repetition_alerts')->insert([
                'signature_key' => 'fsig_decision_expired_demo',
                'domain' => 'engineering',
                'repetition_count' => 5,
                'first_occurrence_at' => now()->subDays(2),
                'latest_occurrence_at' => now(),
                'alert_status' => 'open',
                'severity' => 'critical',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('failure_signatures')->insert([
                'envelope_id' => 'job_attempt:demo',
                'signature_key' => 'fsig_decision_expired_demo',
                'domain' => 'engineering',
                'category' => 'decision',
                'sub_cause' => 'decision_expired',
                'context_summary' => 'DecisionReceipt expirado antes da execucao do provider.',
                'canonical_features' => json_encode(['event_type' => 'ai_job_attempt_failed']),
                'recurrence_count' => 5,
                'recorded_at' => now()->toJSON(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            // Cluster SEM mapeamento — deve sair como unmapped, sem proposta.
            DB::table('failure_repetition_alerts')->insert([
                'signature_key' => 'fsig_weird_cluster',
                'domain' => 'engineering',
                'repetition_count' => 4,
                'first_occurrence_at' => now()->subDays(1),
                'latest_occurrence_at' => now(),
                'alert_status' => 'open',
                'severity' => 'warning',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $report = app(AtlasHarnessProposalBridge::class)->propose();

            $this->assertSame(1, $report['proposals_created'], json_encode($report));
            $created = $report['proposals'][0];
            $this->assertSame('runtime_control.decision_receipt_ttl_seconds', $created['key']);
            $this->assertSame(7200, $created['current']);
            $this->assertSame(14400, $created['proposed']);
            $this->assertSame('proposed', $created['status'], 'PROPOSE-ONLY: nasce proposed, nunca applied');
            $this->assertCount(1, $report['unmapped']);

            $proposal = AiLearningProposal::query()->findOrFail($created['proposal_id']);
            $this->assertSame('harness_config', $proposal->kind);
            $this->assertSame('proposed', $proposal->status);
        } finally {
            \Illuminate\Support\Facades\Schema::dropIfExists('ai_learning_proposals');
            \Illuminate\Support\Facades\Schema::dropIfExists('failure_repetition_alerts');
            \Illuminate\Support\Facades\Schema::dropIfExists('failure_diversity_metrics');
            \Illuminate\Support\Facades\Schema::dropIfExists('failure_signatures');
            \Illuminate\Support\Facades\Schema::dropIfExists('atlas_ledger_events');
        }
    }
}
