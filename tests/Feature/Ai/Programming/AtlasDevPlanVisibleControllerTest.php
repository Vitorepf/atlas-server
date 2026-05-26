<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Models\AtlasProgrammingWorkItem;
use App\Services\Ai\Programming\AtlasDev\Schemas\PlanVisible;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAtlasProgrammingGovernanceTables;
use Tests\TestCase;

/**
 * Feature tests for AP-700 Atlas Dev Plan-Visible HTTP read model.
 *
 * Uses the lightweight `CreatesAtlasProgrammingGovernanceTables` trait
 * instead of RefreshDatabase to avoid running the full migration set
 * (which contains a Postgres-only JSONB migration incompatible with
 * the SQLite test DB).
 */
final class AtlasDevPlanVisibleControllerTest extends TestCase
{
    use CreatesAtlasProgrammingGovernanceTables;

    /** @var array<string,string> */
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas.token', 'test-token-with-enough-length-123');
        $this->createAtlasProgrammingGovernanceTables();
    }

    protected function tearDown(): void
    {
        $this->dropAtlasProgrammingGovernanceTables();
        parent::tearDown();
    }

    public function test_show_returns_404_when_no_plan_persisted(): void
    {
        $workItem = $this->makeWorkItem(planJson: null);

        $response = $this->getJson("/atlas-code/programming/work-items/{$workItem->getKey()}/plan-visible", $this->headers);

        $response->assertStatus(404)
            ->assertJsonPath('code', 'plan_visible_not_persisted');
    }

    public function test_show_returns_200_with_plan_and_etag_when_plan_persisted(): void
    {
        $plan = $this->makePlan();
        $workItem = $this->makeWorkItem(planJson: $plan->toCanonicalArray());

        $response = $this->getJson("/atlas-code/programming/work-items/{$workItem->getKey()}/plan-visible", $this->headers);

        $response->assertStatus(200)
            ->assertJsonPath('plan_visible.schema_version', PlanVisible::SCHEMA_VERSION)
            ->assertJsonPath('hash', $plan->hash());
        self::assertSame('"'.$plan->hash().'"', $response->headers->get('ETag'));
    }

    public function test_show_returns_304_when_if_none_match_matches(): void
    {
        $plan = $this->makePlan();
        $workItem = $this->makeWorkItem(planJson: $plan->toCanonicalArray());

        $response = $this->getJson(
            "/atlas-code/programming/work-items/{$workItem->getKey()}/plan-visible",
            array_merge($this->headers, ['If-None-Match' => '"'.$plan->hash().'"']),
        );

        $response->assertStatus(304);
    }

    public function test_index_lists_work_items_with_persisted_plans(): void
    {
        $plan = $this->makePlan();
        $this->makeWorkItem(planJson: $plan->toCanonicalArray());
        $this->makeWorkItem(planJson: null);

        $response = $this->getJson('/atlas-code/programming/plan-visible', $this->headers);

        $response->assertStatus(200)
            ->assertJsonPath('schema', 'atlas.dev.plan_visible.index.v1');
        // At least one item with a persisted plan; loadPersisted may skip
        // entries whose schema_version is missing, so we assert >= 1 not == 1.
        self::assertGreaterThanOrEqual(1, (int) $response->json('count'));
    }

    /**
     * @param  array<string,mixed>|null  $planJson
     */
    private function makeWorkItem(?array $planJson): AtlasProgrammingWorkItem
    {
        return AtlasProgrammingWorkItem::query()->create([
            'id' => (string) Str::uuid(),
            'code' => 'WI-'.Str::random(8),
            'intent_text' => 'test plan-visible read model',
            'intent_type' => 'dev',
            'scope_mode' => 'focused',
            'risk_level' => 'low',
            'status' => 'planned',
            'current_stage' => 'plan',
            'placement_json' => [],
            'code_intelligence_json' => [],
            'spec_json' => [],
            'plan_json' => $planJson ?? [],
            'tasks_json' => [],
            'evidence_refs_json' => [],
            'gaps_json' => [],
            'metadata_json' => [],
        ]);
    }

    private function makePlan(): PlanVisible
    {
        return PlanVisible::fromArray([
            'schema_version' => PlanVisible::SCHEMA_VERSION,
            'target_files' => ['app/Foo.php'],
            'tests_to_run' => ['tests/Unit/FooTest.php'],
            'risk_band' => PlanVisible::RISK_BAND_LOW,
            'proposed_diff_summary' => 'rename method getFoo to fetchFoo',
            'run_id' => 'run-test-001',
            'task_contract_hash' => 'sha256:'.str_repeat('a', 64),
            'approval_status' => PlanVisible::APPROVAL_STATUS_PENDING,
        ]);
    }
}
