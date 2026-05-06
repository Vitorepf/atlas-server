<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\Kernel\Architecture\AtlasRivalsStrategyCaseRegistrar;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasAiRivalsStrategyApiTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        Schema::dropIfExists('atlas_strategy_rivals_reviews');
        Schema::dropIfExists('atlas_strategy_rivals_cases');
        (require database_path('migrations/2026_05_06_120000_create_atlas_strategy_rivals_tables.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_strategy_rivals_reviews');
        Schema::dropIfExists('atlas_strategy_rivals_cases');

        parent::tearDown();
    }

    public function test_rivals_strategy_api_returns_read_model(): void
    {
        $this->getJson('/ai/rivals-strategy?hours=24', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('rivals_strategy.schema_version', 'atlas.rivals_strategy.v1')
            ->assertJsonPath('rivals_strategy.available', true)
            ->assertJsonPath('rivals_strategy.case_count', 0)
            ->assertJsonPath('rivals_strategy.review_signal.recommended_action', 'register_first_strategy_rivals_case');
    }

    public function test_rivals_strategy_api_records_scored_review(): void
    {
        $registration = app(AtlasRivalsStrategyCaseRegistrar::class)->register([
            'title' => 'Trocar stack principal do Atlas',
            'baseline_choice' => 'Manter decisao direta',
            'atlas_assisted_choice' => 'Usar Atlas como co-estrategista plan-only',
        ]);

        $this->postJson('/ai/rivals-strategy/review', [
            'case_id' => $registration['case_id'],
            'horizon_days' => 30,
            'regret_score' => 10,
            'alignment_score' => 92,
            'agency_score' => 88,
            'outcome_summary' => 'Decisao ainda alinhada e com baixo arrependimento.',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('recorded_review.status', 'ok')
            ->assertJsonPath('recorded_review.scores.regret', 10)
            ->assertJsonPath('rivals_strategy.scored_review_count', 1)
            ->assertJsonPath('rivals_strategy.review_signal.status', 'ok');

        $this->assertDatabaseHas('atlas_strategy_rivals_reviews', [
            'case_id' => $registration['case_id'],
            'horizon_days' => 30,
            'status' => 'reviewed',
        ]);
    }

    public function test_rivals_strategy_api_lists_due_reviews(): void
    {
        app(AtlasRivalsStrategyCaseRegistrar::class)->register([
            'title' => 'Trocar stack principal do Atlas',
            'baseline_choice' => 'Manter decisao direta',
            'atlas_assisted_choice' => 'Usar Atlas como co-estrategista plan-only',
        ]);

        $this->getJson('/ai/rivals-strategy/due-reviews?days=30', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('due_reviews.due_review_count', 1)
            ->assertJsonPath('due_reviews.due_reviews.0.case_title', 'Trocar stack principal do Atlas')
            ->assertJsonPath('due_reviews.review_signal.recommended_action', 'record_due_rivals_strategy_reviews');
    }

    public function test_rivals_strategy_api_requires_atlas_token(): void
    {
        $this->getJson('/ai/rivals-strategy')
            ->assertUnauthorized();
    }
}
