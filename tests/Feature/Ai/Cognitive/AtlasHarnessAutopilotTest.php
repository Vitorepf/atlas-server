<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognitive;

use App\Models\AiLearningProposal;
use App\Services\Ai\Cognitive\Failure\FailureRecurrenceMetricService;
use App\Services\Ai\Cognitive\Failure\FailureSignatureClassifier;
use App\Services\Ai\Cognitive\Harness\AtlasHarnessAutopilot;
use App\Services\Ai\Cognitive\Harness\AtlasHarnessFrozenSuite;
use App\Services\Ai\Cognitive\Harness\AtlasHarnessSurface;
use App\Services\Ai\Compounding\AtlasLearningProposalApplier;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AP-819 AUTOPILOT — evolução automática gated por matemática:
 *   gate 1 superfície (bounds), gate 2 não-regressão dupla na suite congelada
 *   (regressão ⇒ reverse IMEDIATO), gate 3 recorrência no outcome cru após a
 *   janela (sem melhora estrita ⇒ AUTO-REVERSE). Proposta tentada nunca re-tenta.
 */
class AtlasHarnessAutopilotTest extends TestCase
{
    private AtlasHarnessSurface $surface;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_19_030000_strengthen_rag_feedback_and_create_learning_proposals.php'))->up();
        Schema::create('ai_job_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('ai_job_id');
            $table->string('provider');
            $table->string('model')->nullable();
            $table->string('status');
            $table->integer('exit_code')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        $this->surface = new AtlasHarnessSurface;
        $this->surface->setOverridesPathForTesting($this->tmpFile('overrides'));
        $this->app->instance(AtlasHarnessSurface::class, $this->surface);

        config(['atlas.ai.harness_autopilot.enabled' => true]);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
        Schema::dropIfExists('ai_job_attempts');
        Schema::dropIfExists('ai_learning_proposals');
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    private function tmpFile(string $tag): string
    {
        $path = sys_get_temp_dir().'/atlas-autopilot-'.$tag.'-'.bin2hex(random_bytes(4)).'.json';
        $this->files[] = $path;

        return $path;
    }

    private function autopilot(?AtlasHarnessFrozenSuite $suite = null): AtlasHarnessAutopilot
    {
        if ($suite === null) {
            $suite = new AtlasHarnessFrozenSuite;
            $suite->setBaselinePathForTesting($this->tmpFile('baseline'));
        }
        $autopilot = new AtlasHarnessAutopilot(
            $this->surface,
            $suite,
            app(AtlasLearningProposalApplier::class),
            app(FailureRecurrenceMetricService::class),
        );
        $autopilot->setStatePathForTesting($this->tmpFile('state'));

        return $autopilot;
    }

    private function proposal(int $value = 900, string $cluster = ''): AiLearningProposal
    {
        return AiLearningProposal::query()->create([
            'kind' => 'harness_config',
            'status' => 'proposed',
            'summary' => 'autopilot test proposal — raise worker timeout within surface bounds',
            'current_state' => ['key' => 'runtime_control.timeout_seconds', 'value' => 600],
            'proposed_state' => ['key' => 'runtime_control.timeout_seconds', 'value' => $value, 'from_cluster' => $cluster],
            'evidence_refs' => ['failure_alert:test'],
            'proposal_hash' => hash('sha256', 'autopilot-test-'.$value.$cluster.Str::ulid()),
        ]);
    }

    public function test_disabled_flag_is_a_no_op(): void
    {
        config(['atlas.ai.harness_autopilot.enabled' => false]);
        $this->proposal();

        $result = $this->autopilot()->run();

        $this->assertSame('disabled', $result['status']);
        $this->assertSame('proposed', AiLearningProposal::query()->firstOrFail()->status);
    }

    public function test_applies_one_proposal_under_observation_with_math_gates(): void
    {
        config(['atlas.ai.timeout_seconds' => 600]);
        $proposal = $this->proposal(900);
        $autopilot = $this->autopilot();

        $result = $autopilot->run();

        $this->assertSame('applied_under_observation', $result['status'], json_encode($result));
        $this->assertSame(900, config('atlas.ai.timeout_seconds'));
        $this->assertSame('applied', $proposal->fresh()->status);
        $this->assertSame(AtlasHarnessAutopilot::ACTOR, $proposal->fresh()->decided_by);
        $state = $autopilot->state();
        $this->assertSame('applied_under_observation', $state[(string) $proposal->getKey()]['outcome']);

        // Cap estrutural: segunda chamada no MESMO run-dia não aplica outra
        // (mesma chave em observação) — e proposta tentada nunca re-tenta.
        $this->proposal(1200);
        $second = $autopilot->run();
        $this->assertSame('nothing_to_apply', $second['status']);
    }

    public function test_suite_regression_after_apply_triggers_immediate_reverse(): void
    {
        config(['atlas.ai.timeout_seconds' => 600]);
        $proposal = $this->proposal(900);

        $suite = $this->createMock(AtlasHarnessFrozenSuite::class);
        $suite->method('promotionVerdict')->willReturnOnConsecutiveCalls(
            ['verdict' => 'no_gain', 'promote' => false, 'delta_held_in' => 0.0, 'delta_held_out' => 0.0],
            ['verdict' => 'regression', 'promote' => false, 'delta_held_in' => -0.15, 'delta_held_out' => 0.0],
        );
        $autopilot = $this->autopilot($suite);

        $result = $autopilot->run();

        $this->assertSame('auto_reversed_suite_regression', $result['status']);
        $this->assertSame(600, config('atlas.ai.timeout_seconds'), 'config restaurado pelo reverse imediato');
        $this->assertSame([], $this->surface->readOverrides());
        $this->assertSame('auto_reversed_suite_regression', $autopilot->state()[(string) $proposal->getKey()]['outcome']);
    }

    public function test_monitor_confirms_when_cluster_strictly_improves(): void
    {
        $proposal = $this->proposal(900, 'fsig_demo_cluster');
        $proposal->forceFill(['status' => 'applied'])->save();

        $autopilot = $this->autopilot();
        file_put_contents($autopilot->statePath(), json_encode([
            (string) $proposal->getKey() => [
                'key' => 'runtime_control.timeout_seconds',
                'cluster' => 'fsig_demo_cluster',
                'outcome' => 'applied_under_observation',
                'applied_at' => now()->subDays(8)->toJSON(),
                'observation_days' => 7,
                'pre_count' => 5,
            ],
        ], JSON_THROW_ON_ERROR));

        // Nenhum attempt cru combina com o cluster ⇒ post_count 0 < 5 ⇒ confirma.
        $report = $autopilot->monitor();

        $this->assertSame(1, $report['confirmed'], json_encode($report));
        $this->assertSame('confirmed', $autopilot->state()[(string) $proposal->getKey()]['outcome']);
        $this->assertSame('applied', $proposal->fresh()->status, 'confirmado segue aplicado');
    }

    public function test_monitor_auto_reverses_when_cluster_does_not_improve(): void
    {
        config(['atlas.ai.timeout_seconds' => 600]);

        // Aplica de verdade para o reverse ter o que desfazer.
        $proposal = $this->proposal(900, '');
        $applier = app(AtlasLearningProposalApplier::class);
        app(\App\Services\Ai\Compounding\AtlasLearningProposalService::class)->approve($proposal, 'test');
        $this->assertTrue($applier->apply($proposal, 'test')['applied']);
        $this->assertSame(900, config('atlas.ai.timeout_seconds'));

        // Cluster real: a assinatura dos attempts crus semeados na janela pós.
        $signature = app(FailureSignatureClassifier::class)->classify([
            'event_type' => 'ai_job_attempt_failed',
            'domain' => 'engineering',
            'message' => 'autopilot recurring probe failure',
            'error_class' => 'probe_err',
            'provider' => 'probe_cli',
        ]);
        $cluster = (string) $signature['signature_key'];
        foreach ([5, 4] as $daysAgo) {
            DB::table('ai_job_attempts')->insert([
                'id' => (string) Str::uuid(),
                'ai_job_id' => (string) Str::uuid(),
                'provider' => 'probe_cli',
                'status' => 'failed',
                'error_code' => 'probe_err',
                'error_message' => 'autopilot recurring probe failure',
                'created_at' => now()->subDays($daysAgo)->toDateTimeString(),
                'updated_at' => now()->subDays($daysAgo)->toDateTimeString(),
            ]);
        }

        $autopilot = $this->autopilot();
        file_put_contents($autopilot->statePath(), json_encode([
            (string) $proposal->getKey() => [
                'key' => 'runtime_control.timeout_seconds',
                'cluster' => $cluster,
                'outcome' => 'applied_under_observation',
                'applied_at' => now()->subDays(8)->toJSON(),
                'observation_days' => 7,
                'pre_count' => 1,
            ],
        ], JSON_THROW_ON_ERROR));

        $report = $autopilot->monitor();

        $this->assertSame(1, $report['auto_reversed'], json_encode($report));
        $this->assertSame(600, config('atlas.ai.timeout_seconds'), 'edit revertido: o mundo não melhorou');
        $this->assertSame('auto_reversed_no_cluster_improvement', $autopilot->state()[(string) $proposal->getKey()]['outcome']);
        $this->assertSame(2, $autopilot->state()[(string) $proposal->getKey()]['post_count']);
    }

    public function test_monitor_waits_until_observation_window_elapses(): void
    {
        $proposal = $this->proposal(900, 'fsig_waiting');
        $autopilot = $this->autopilot();
        file_put_contents($autopilot->statePath(), json_encode([
            (string) $proposal->getKey() => [
                'key' => 'runtime_control.timeout_seconds',
                'cluster' => 'fsig_waiting',
                'outcome' => 'applied_under_observation',
                'applied_at' => now()->subDays(2)->toJSON(),
                'observation_days' => 7,
                'pre_count' => 3,
            ],
        ], JSON_THROW_ON_ERROR));

        $report = $autopilot->monitor();

        $this->assertSame(1, $report['still_observing']);
        $this->assertSame(0, $report['checked']);
    }
}
