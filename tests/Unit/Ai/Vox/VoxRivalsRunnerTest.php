<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Vox;

use App\Models\AtlasVoxRivalsCase;
use App\Services\Ai\Vox\Rivals\VoxRivalsRunner;
use App\Services\Ai\Vox\VoxEvidenceService;
use App\Services\Ai\Vox\VoxSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

final class VoxRivalsRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('atlas_vox_rivals_cases');
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_20_010000_create_atlas_vox_rivals_cases_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_vox_rivals_cases');
        Schema::dropIfExists('atlas_ledger_events');
        parent::tearDown();
    }

    private function runner(): VoxRivalsRunner
    {
        return new VoxRivalsRunner($this->app->make(VoxEvidenceService::class));
    }

    public function test_record_persists_case_and_returns_event(): void
    {
        $result = $this->runner()->record([
            'kind' => 'provider_direct',
            'mode' => 'intent_compile',
            'vox_session_id' => 'sess-1',
            'baseline_label' => 'prompt manual codex',
            'baseline_duration_ms' => 120000,
            'vox_duration_ms' => 60000,
            'baseline_score' => 3,
            'vox_score' => 5,
            'preference' => 'vox',
            'prompt_quality_vote' => 1,
            'regret_flag' => false,
            'notes' => 'win',
        ]);

        $this->assertInstanceOf(AtlasVoxRivalsCase::class, $result['case']);
        $this->assertStringStartsWith('voxc_', $result['case']->case_id);
        $this->assertSame('VOX_RIVALS_CASE_RECORDED', $result['event']['event_kind']);
        $this->assertSame('provider_direct', $result['event']['payload']['kind']);
    }

    public function test_record_rejects_unknown_kind(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->runner()->record([
            'kind' => 'cloud_api',
            'mode' => 'dictation',
            'baseline_label' => 'x',
            'preference' => 'vox',
        ]);
    }

    public function test_record_rejects_unknown_mode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->runner()->record([
            'kind' => 'manual',
            'mode' => 'something_else',
            'baseline_label' => 'x',
            'preference' => 'vox',
        ]);
    }

    public function test_record_rejects_invalid_preference(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->runner()->record([
            'kind' => 'manual',
            'mode' => 'dictation',
            'baseline_label' => 'x',
            'preference' => 'maybe',
        ]);
    }

    public function test_record_rejects_invalid_vote(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->runner()->record([
            'kind' => 'manual',
            'mode' => 'dictation',
            'baseline_label' => 'x',
            'preference' => 'vox',
            'prompt_quality_vote' => 5,
        ]);
    }

    public function test_record_rejects_out_of_range_scores(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->runner()->record([
            'kind' => 'manual',
            'mode' => 'dictation',
            'baseline_label' => 'x',
            'preference' => 'vox',
            'baseline_score' => 9,
        ]);
    }

    public function test_report_computes_wins_quality_delta_and_multiplier(): void
    {
        $runner = $this->runner();
        $runner->record([
            'kind' => 'provider_direct',
            'mode' => 'intent_compile',
            'baseline_label' => 'a',
            'preference' => 'vox',
            'prompt_quality_vote' => 1,
            'baseline_duration_ms' => 100,
            'vox_duration_ms' => 50,
        ]);
        $runner->record([
            'kind' => 'manual',
            'mode' => 'governed_execute',
            'baseline_label' => 'b',
            'preference' => 'baseline',
            'prompt_quality_vote' => -1,
        ]);

        $report = $runner->report();
        $this->assertSame(2, $report['cases_total']);
        $this->assertSame(1, $report['vox_wins']);
        $this->assertSame(1, $report['baseline_wins']);
        $this->assertSame(0, $report['ties']);
        $this->assertSame(0.0, $report['prompt_quality_delta']); // avg of [1, -1]
        $this->assertSame(2.0, $report['rivals_voice_multiplier']);
        $this->assertSame(['wispr_baseline' => 0, 'provider_direct' => 1, 'manual' => 1], $report['cases_by_kind']);
    }

    public function test_report_empty_returns_no_cases_yet_recommendation(): void
    {
        $report = $this->runner()->report();
        $this->assertSame(0, $report['cases_total']);
        $this->assertSame('no_cases_yet', $report['recommendation']);
    }

    public function test_report_recommendation_vox_winning_when_multiplier_high_and_majority_vox(): void
    {
        $runner = $this->runner();
        for ($i = 0; $i < 4; $i++) {
            $runner->record([
                'kind' => 'provider_direct',
                'mode' => 'intent_compile',
                'baseline_label' => 'baseline',
                'preference' => 'vox',
                'baseline_duration_ms' => 200,
                'vox_duration_ms' => 100,
                'prompt_quality_vote' => 1,
            ]);
        }
        $this->assertSame('vox_winning', $runner->report()['recommendation']);
    }
}
