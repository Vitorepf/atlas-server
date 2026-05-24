<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Context;

use App\Services\Ai\Programming\AtlasDev\Schemas\ContextRetrievalPlan;
use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use PHPUnit\Framework\TestCase;

final class ContextRetrievalPlanTest extends TestCase
{
    private function makePlan(array $overrides = []): ContextRetrievalPlan
    {
        $payload = $this->fixturePayload();

        return ContextRetrievalPlan::fromArray(array_replace($payload, $overrides));
    }

    private function fixturePayload(): array
    {
        $raw = file_get_contents(__DIR__.'/../../../../../../Fixtures/AtlasDev/context/valid_context_plan_desktop_r2.json');
        $this->assertIsString($raw);

        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    public function test_constructs_from_fixture_and_implements_contract(): void
    {
        $plan = $this->makePlan();

        $this->assertInstanceOf(AtlasDevSchemaContract::class, $plan);
        $this->assertSame('atlas.dev.context_retrieval_plan.v1', $plan->schemaVersion());
        $this->assertSame('atlas.dev.context_retrieval_plan.v1', ContextRetrievalPlan::SCHEMA_VERSION);
        $this->assertSame(['core', 'code_intelligence', 'sdd'], $plan->selectedTiers);
        $this->assertSame(12000, $plan->budgetChars);
        $this->assertSame([], $plan->missingSources);
        $this->assertTrue($plan->isProviderSafe());
    }

    public function test_canonical_array_is_deterministic_and_sorted(): void
    {
        $plan = $this->makePlan();

        $canonical = $plan->toCanonicalArray();
        $keys = array_keys($canonical);
        $sorted = $keys;
        sort($sorted, SORT_STRING);

        $this->assertSame($sorted, $keys, 'top-level keys must be sorted alphabetically');

        $again = $plan->toCanonicalArray();
        $this->assertSame($canonical, $again);
    }

    public function test_round_trip_through_canonical_array_preserves_state(): void
    {
        $plan = $this->makePlan();
        $rebuilt = ContextRetrievalPlan::fromArray($plan->toCanonicalArray());

        $this->assertSame($plan->toCanonicalArray(), $rebuilt->toCanonicalArray());
        $this->assertSame($plan->hash(), $rebuilt->hash());
        $this->assertSame($plan->toJson(), $rebuilt->toJson());
    }

    public function test_hash_is_deterministic_across_calls(): void
    {
        $plan = $this->makePlan();

        $this->assertSame($plan->hash(), $plan->hash());
    }

    public function test_hash_changes_when_relevant_field_changes(): void
    {
        $base = $this->makePlan();
        $changed = $this->makePlan(['budget_chars' => 6000]);

        $this->assertNotSame($base->hash(), $changed->hash());
    }

    public function test_hash_ignores_plan_hash_field_itself(): void
    {
        $a = $this->makePlan(['plan_hash' => 'aaaa']);
        $b = $this->makePlan(['plan_hash' => 'bbbb']);

        $this->assertNotSame($a->planHash, $b->planHash);
        $this->assertSame($a->hash(), $b->hash(), 'hash() must exclude plan_hash');
    }

    public function test_provider_safe_is_explicit_and_flows_through(): void
    {
        $safe = $this->makePlan(['provider_safe' => true]);
        $unsafe = $this->makePlan(['provider_safe' => false]);

        $this->assertTrue($safe->isProviderSafe());
        $this->assertFalse($unsafe->isProviderSafe());
        $this->assertNotSame($safe->hash(), $unsafe->hash(), 'provider_safe is part of canonical payload');
    }

    public function test_to_json_matches_canonical_json_encoder(): void
    {
        $plan = $this->makePlan();

        $this->assertSame(
            CanonicalJson::encode($plan->toCanonicalArray()),
            $plan->toJson(),
        );
    }
}
