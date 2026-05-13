<?php

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasAiRivalsStrategyCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTables();
        (require database_path('migrations/2026_05_06_120000_create_atlas_strategy_rivals_tables.php'))->up();
    }

    protected function tearDown(): void
    {
        $this->dropTables();

        parent::tearDown();
    }

    public function test_command_reports_empty_strategy_rivals_read_model_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:rivals-strategy', [
            'action' => 'report',
            '--hours' => 24,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('atlas.rivals_strategy.v1', data_get($payload, 'rivals_strategy.schema_version'));
        $this->assertTrue(data_get($payload, 'rivals_strategy.available'));
        $this->assertSame(0, data_get($payload, 'rivals_strategy.case_count'));
        $this->assertSame('register_first_strategy_rivals_case', data_get($payload, 'rivals_strategy.review_signal.recommended_action'));
    }

    public function test_command_registers_case_and_four_revisit_reviews(): void
    {
        $exit = Artisan::call('atlas:ai:rivals-strategy', [
            'action' => 'register-case',
            '--title' => 'Trocar stack principal do Atlas',
            '--baseline' => 'Manter decisao direta',
            '--atlas' => 'Usar Atlas como co-estrategista plan-only',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame(4, $payload['scheduled_reviews']);
        $this->assertDatabaseHas('atlas_strategy_rivals_cases', [
            'id' => $payload['registered_case_id'],
            'title' => 'Trocar stack principal do Atlas',
        ]);
        $this->assertDatabaseCount('atlas_strategy_rivals_reviews', 4);
        $this->assertSame(1, data_get($payload, 'rivals_strategy.case_count'));
        $this->assertSame(4, data_get($payload, 'rivals_strategy.scheduled_review_count'));
    }

    public function test_recent_report_counts_future_revisit_schedule_for_registered_cases(): void
    {
        Artisan::call('atlas:ai:rivals-strategy', [
            'action' => 'register-case',
            '--title' => 'Priorizar Memory antes de Voice',
            '--baseline' => 'Continuar Voice como trilha principal',
            '--atlas' => 'Estacionar Voice e consolidar Memory/Open Brain',
            '--json' => true,
        ]);

        $exit = Artisan::call('atlas:ai:rivals-strategy', [
            'action' => 'report',
            '--hours' => 1,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(1, data_get($payload, 'rivals_strategy.case_count'));
        $this->assertSame(4, data_get($payload, 'rivals_strategy.scheduled_review_count'));
        $this->assertSame(4, data_get($payload, 'rivals_strategy.pending_review_count'));
        $this->assertSame([], data_get($payload, 'rivals_strategy.due_reviews'));
        $this->assertSame('passed', collect(data_get($payload, 'rivals_strategy.gates', []))->firstWhere('id', 'revisit_schedule_created')['status'] ?? null);
    }

    public function test_command_records_scored_review_and_updates_multiplier_signal(): void
    {
        Artisan::call('atlas:ai:rivals-strategy', [
            'action' => 'register-case',
            '--title' => 'Trocar stack principal do Atlas',
            '--baseline' => 'Manter decisao direta',
            '--atlas' => 'Usar Atlas como co-estrategista plan-only',
            '--json' => true,
        ]);
        $caseId = (string) DB::table('atlas_strategy_rivals_cases')->value('id');

        $exit = Artisan::call('atlas:ai:rivals-strategy', [
            'action' => 'record-review',
            '--case-id' => $caseId,
            '--review-horizon' => 30,
            '--regret' => 10,
            '--alignment' => 92,
            '--agency' => 88,
            '--outcome' => 'Decisao ainda alinhada e com baixo arrependimento.',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', data_get($payload, 'recorded_review.status'));
        $this->assertSame(1, data_get($payload, 'rivals_strategy.scored_review_count'));
        $this->assertSame(10, data_get($payload, 'rivals_strategy.average_regret_score'));
        $this->assertSame(92, data_get($payload, 'rivals_strategy.average_alignment_score'));
        $this->assertSame(88, data_get($payload, 'rivals_strategy.average_agency_score'));
        $this->assertSame(90.2, data_get($payload, 'rivals_strategy.strategy_multiplier_score'));
        $this->assertSame('use_strategy_rivals_signal_for_qualitative_level', data_get($payload, 'rivals_strategy.review_signal.recommended_action'));
        $this->assertDatabaseHas('atlas_strategy_rivals_reviews', [
            'case_id' => $caseId,
            'horizon_days' => 30,
            'status' => 'reviewed',
            'regret_score' => 10,
            'alignment_score' => 92,
            'agency_score' => 88,
        ]);
    }

    public function test_command_lists_due_reviews_with_record_command(): void
    {
        Artisan::call('atlas:ai:rivals-strategy', [
            'action' => 'register-case',
            '--title' => 'Trocar stack principal do Atlas',
            '--baseline' => 'Manter decisao direta',
            '--atlas' => 'Usar Atlas como co-estrategista plan-only',
            '--json' => true,
        ]);

        $exit = Artisan::call('atlas:ai:rivals-strategy', [
            'action' => 'due-reviews',
            '--due-days' => 30,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame(1, data_get($payload, 'due_reviews.due_review_count'));
        $this->assertSame('Trocar stack principal do Atlas', data_get($payload, 'due_reviews.due_reviews.0.case_title'));
        $this->assertStringContainsString('record-review', data_get($payload, 'due_reviews.due_reviews.0.record_command'));
        $this->assertSame('record_due_rivals_strategy_reviews', data_get($payload, 'due_reviews.review_signal.recommended_action'));
    }

    private function dropTables(): void
    {
        Schema::dropIfExists('atlas_strategy_rivals_reviews');
        Schema::dropIfExists('atlas_strategy_rivals_cases');
    }
}
