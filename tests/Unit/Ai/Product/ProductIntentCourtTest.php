<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Product;

use App\Services\Ai\Product\ProductIntentCase;
use App\Services\Ai\Product\ProductIntentCourt;
use Tests\TestCase;

final class ProductIntentCourtTest extends TestCase
{
    public function test_admitted_intent_is_complete_and_deterministic(): void
    {
        $court = new ProductIntentCourt;
        $case = ProductIntentCase::fromArray($this->validCase());

        $first = $court->adjudicate($case);
        $second = $court->adjudicate(ProductIntentCase::fromArray($case->toArray()));

        self::assertSame('admitted', $first->status);
        self::assertSame($first->intentHash, $second->intentHash);
        self::assertSame($first->toArray(), $second->toArray());
        self::assertSame('checkout', $first->problem);
        self::assertSame('buyer', $first->user);
        self::assertNotEmpty($first->metric);
        self::assertNotEmpty($first->observationWindow);
        self::assertNotEmpty($first->falsifiers);
        self::assertNotEmpty($first->sourceRefs);
    }

    public function test_missing_metric_or_window_requires_revision(): void
    {
        $verdict = (new ProductIntentCourt)->adjudicate(ProductIntentCase::fromArray($this->validCase([
            'metric' => '', 'observation_window' => '',
        ])));

        self::assertSame('revise', $verdict->status);
        self::assertContains('metric_missing', $verdict->blockingReasons);
        self::assertContains('observation_window_missing', $verdict->blockingReasons);
    }

    public function test_missing_world_snapshot_is_held_for_risky_intent(): void
    {
        $verdict = (new ProductIntentCourt)->adjudicate(ProductIntentCase::fromArray($this->validCase([
            'world_snapshot_hash' => null, 'risk_class' => 'R4',
        ])));

        self::assertSame('held', $verdict->status);
        self::assertContains('world_snapshot_missing', $verdict->blockingReasons);
    }

    public function test_missing_falsifier_and_unbounded_side_effect_are_refused(): void
    {
        $verdict = (new ProductIntentCourt)->adjudicate(ProductIntentCase::fromArray($this->validCase([
            'falsifiers' => [],
            'side_effects' => [['description' => 'delete all customer data', 'containment' => '']],
        ])));

        self::assertSame('refused', $verdict->status);
        self::assertContains('falsifier_missing', $verdict->blockingReasons);
        self::assertContains('unbounded_side_effect', $verdict->blockingReasons);
    }

    public function test_mode_does_not_change_adjudication_for_equivalent_facts(): void
    {
        $base = $this->validCase();
        $dev = (new ProductIntentCourt)->adjudicate(ProductIntentCase::fromArray($base + ['mode' => 'dev']));
        $forge = (new ProductIntentCourt)->adjudicate(ProductIntentCase::fromArray($base + ['mode' => 'forge']));
        $autonomos = (new ProductIntentCourt)->adjudicate(ProductIntentCase::fromArray($base + ['mode' => 'autonomos']));

        self::assertSame('admitted', $dev->status);
        self::assertSame($dev->intentHash, $forge->intentHash);
        self::assertSame($dev->intentHash, $autonomos->intentHash);
    }

    public function test_contradictory_constraints_and_missing_acceptance_require_revision(): void
    {
        $verdict = (new ProductIntentCourt)->adjudicate(ProductIntentCase::fromArray($this->validCase([
            'constraints' => ['no_external_write'], 'non_goals' => ['no_external_write'], 'acceptance' => [],
        ])));

        self::assertSame('revise', $verdict->status);
        self::assertContains('contradictory_constraints', $verdict->blockingReasons);
        self::assertContains('acceptance_missing', $verdict->blockingReasons);
    }

    /** @return array<string,mixed> */
    private function validCase(array $overrides = []): array
    {
        return array_replace([
            'human_request' => 'Melhorar checkout para reduzir falhas de pagamento',
            'mode' => 'dev',
            'problem' => 'checkout',
            'user' => 'buyer',
            'value' => 'complete payment without duplicate charge',
            'metric' => 'payment_success_rate >= 0.98',
            'observation_window' => '30d',
            'source_refs' => ['product-brief:checkout-v2'],
            'world_snapshot_hash' => str_repeat('a', 64),
            'world_snapshot_status' => 'fresh',
            'risk_class' => 'R3',
            'constraints' => ['no_duplicate_charge'],
            'non_goals' => ['change pricing'],
            'hypotheses' => ['idempotency reduces duplicate payment failures'],
            'uncertainties' => ['provider outage rate'],
            'alternatives' => ['retain current checkout'],
            'falsifiers' => ['success rate stays below baseline after 30d'],
            'side_effects' => [['description' => 'slower checkout', 'containment' => 'latency budget alert']],
            'acceptance' => ['payment success and failure paths are covered'],
            'release_policy' => ['canary' => true, 'rollback' => 'revert'],
            'outcome_policy' => ['windows' => ['0h', '24h', '30d']],
        ], $overrides);
    }
}
