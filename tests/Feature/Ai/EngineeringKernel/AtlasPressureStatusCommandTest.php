<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\EngineeringKernel;

use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\EngineeringKernel\PressureLayerGuards;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * GOAL 3.5 · SLICE 2 — atlas:pressure:status reads the REAL per-guard cadence from the outcome
 * ledger. 0 at the start is HONEST (not fabricated); after real verdicts land it reports the
 * true counters Goal 4 will read.
 */
final class AtlasPressureStatusCommandTest extends TestCase
{
    private string $tmp;

    private AtlasDecideLiveOutcomeFeedbackService $feedback;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_pstatus_'.uniqid('', true);
        @mkdir($this->tmp, 0775, true);
        $this->feedback = new AtlasDecideLiveOutcomeFeedbackService;
        $this->feedback->setLogPathForTesting($this->tmp.'/live_outcomes.jsonl');
        $this->app->instance(AtlasDecideLiveOutcomeFeedbackService::class, $this->feedback);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp);
        parent::tearDown();
    }

    public function test_reports_an_honest_zero_when_no_cadence_has_accumulated(): void
    {
        $out = $this->runStatus();

        $this->assertSame(0, $out['total_verdicts']);
        $this->assertStringContainsString('honest zero', (string) $out['note']);
        // The 3 guard rows are always present, honestly at zero.
        $this->assertCount(3, $out['guards']);
        foreach ($out['guards'] as $g) {
            $this->assertSame(0, $g['runs']);
            $this->assertEquals(0.0, $g['capture_rate']); // 0.0 round-trips through JSON as int 0
        }
    }

    public function test_reports_the_real_ledger_counters_per_guard(): void
    {
        // runtime_verifier: 1 proven pass + 1 capture (failure). context_cartographer: 1 pass.
        $this->record(PressureLayerGuards::RUNTIME_VERIFIER, 'success', provenReal: true);
        $this->record(PressureLayerGuards::RUNTIME_VERIFIER, 'failure', provenReal: false);
        $this->record(PressureLayerGuards::CONTEXT_CARTOGRAPHER, 'success', provenReal: false);

        $out = $this->runStatus();
        $this->assertSame(3, $out['total_verdicts']);

        $byGuard = [];
        foreach ($out['guards'] as $g) {
            $byGuard[$g['guard']] = $g;
        }

        // runtime_verifier: 2 runs, 1 pass (proven), 1 capture → capture_rate 0.5.
        $this->assertSame(2, $byGuard[PressureLayerGuards::RUNTIME_VERIFIER]['runs']);
        $this->assertSame(1, $byGuard[PressureLayerGuards::RUNTIME_VERIFIER]['proven']);
        $this->assertSame(1, $byGuard[PressureLayerGuards::RUNTIME_VERIFIER]['captures']);
        $this->assertSame(0.5, $byGuard[PressureLayerGuards::RUNTIME_VERIFIER]['capture_rate']);

        // context_cartographer: 1 run, 1 pass, 0 captures (real, not fabricated).
        $this->assertSame(1, $byGuard[PressureLayerGuards::CONTEXT_CARTOGRAPHER]['runs']);
        $this->assertSame(0, $byGuard[PressureLayerGuards::CONTEXT_CARTOGRAPHER]['captures']);
    }

    /**
     * @return array<string,mixed>
     */
    private function runStatus(): array
    {
        $code = Artisan::call('atlas:pressure:status', ['--task-category' => 'programming', '--json' => true]);
        $this->assertSame(0, $code);
        $decoded = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function record(string $guardRole, string $result, bool $provenReal): void
    {
        $this->feedback->record([
            'task_category' => 'programming',
            'role' => $guardRole,
            'provider' => 'hermes_cli',
            'result' => $result,
            'proven_real' => $provenReal,
            'actor' => 'pressure_layer_on_land',
        ]);
    }
}
