<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Autonomy;

use App\Services\Ai\SelfConstruction\Autonomy\AtlasSelfConstructionAutonomyLevelLadder;
use App\Services\Ai\SelfConstruction\Autonomy\AtlasSelfConstructionAutonomyPromotionGate;
use Tests\TestCase;

final class AtlasSelfConstructionAutonomyPromotionGateTest extends TestCase
{
    private function allFactsGreen(): array
    {
        return array_fill_keys(AtlasSelfConstructionAutonomyPromotionGate::REQUIRED_FACT_KEYS, true);
    }

    private function allFactsGreen24x7(): array
    {
        return array_fill_keys(
            array_merge(
                AtlasSelfConstructionAutonomyPromotionGate::REQUIRED_FACT_KEYS,
                AtlasSelfConstructionAutonomyPromotionGate::REQUIRED_FACT_KEYS_24_7,
            ),
            true,
        );
    }

    public function test_promotes_when_all_evidence_facts_are_green(): void
    {
        $gate = new AtlasSelfConstructionAutonomyPromotionGate(new AtlasSelfConstructionAutonomyLevelLadder);

        $verdict = $gate->evaluate(
            AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ATLAS_SUPERVISED,
            AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ATLAS_NATIVE_BOUNDED,
            $this->allFactsGreen(),
        );

        $this->assertSame('promote', $verdict['verdict']);
        $this->assertSame(
            AtlasSelfConstructionAutonomyPromotionGate::REQUIRED_FACT_KEYS,
            $verdict['satisfied_fact_keys'],
        );
        $this->assertSame(AtlasSelfConstructionAutonomyPromotionGate::SCHEMA, $verdict['schema_version']);
    }

    public function test_holds_when_evidence_facts_are_missing(): void
    {
        $gate = new AtlasSelfConstructionAutonomyPromotionGate(new AtlasSelfConstructionAutonomyLevelLadder);

        $partial = $this->allFactsGreen();
        unset($partial['verification_court_green'], $partial['rollback_proven']);

        $verdict = $gate->evaluate(
            AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ATLAS_SUPERVISED,
            AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ATLAS_NATIVE_BOUNDED,
            $partial,
        );

        $this->assertSame('hold', $verdict['verdict']);
        $this->assertEqualsCanonicalizing(
            ['verification_court_green', 'rollback_proven'],
            $verdict['missing_fact_keys'],
        );
        $this->assertSame([], $verdict['failing_fact_keys']);
    }

    public function test_holds_when_evidence_facts_are_false(): void
    {
        $gate = new AtlasSelfConstructionAutonomyPromotionGate(new AtlasSelfConstructionAutonomyLevelLadder);

        $facts = $this->allFactsGreen();
        $facts['merge_governor_green'] = false;

        $verdict = $gate->evaluate(
            AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ATLAS_SUPERVISED,
            AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ATLAS_NATIVE_BOUNDED,
            $facts,
        );

        $this->assertSame('hold', $verdict['verdict']);
        $this->assertSame(['merge_governor_green'], $verdict['failing_fact_keys']);
    }

    public function test_refuses_when_atlas_native_target_lacks_atlas_native_owner(): void
    {
        $ladder = new AtlasSelfConstructionAutonomyLevelLadder;
        $gate = new AtlasSelfConstructionAutonomyPromotionGate(
            $ladder,
            function (string $level) use ($ladder): array {
                $description = $ladder->describe($level);
                if ($level === AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ATLAS_NATIVE_BOUNDED) {
                    $description['final_runtime_owner'] = 'external_provider';
                }

                return $description;
            },
        );

        $verdict = $gate->evaluate(
            AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ATLAS_SUPERVISED,
            AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ATLAS_NATIVE_BOUNDED,
            $this->allFactsGreen(),
        );

        $this->assertSame('refuse', $verdict['verdict']);
        $this->assertSame('final_runtime_owner_not_atlas_native', $verdict['refusal_reason']);
        $this->assertSame('external_provider', $verdict['observed_final_runtime_owner']);
    }

    public function test_refuses_level_skip(): void
    {
        $gate = new AtlasSelfConstructionAutonomyPromotionGate(new AtlasSelfConstructionAutonomyLevelLadder);

        $verdict = $gate->evaluate(
            AtlasSelfConstructionAutonomyLevelLadder::LEVEL_BOOTSTRAP,
            AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ATLAS_NATIVE_BOUNDED,
            $this->allFactsGreen(),
        );

        $this->assertSame('refuse', $verdict['verdict']);
        $this->assertSame('level_skip_refused', $verdict['refusal_reason']);
    }

    public function test_promotes_to_24_7_when_all_extra_facts_are_green(): void
    {
        $gate = new AtlasSelfConstructionAutonomyPromotionGate(new AtlasSelfConstructionAutonomyLevelLadder);

        $verdict = $gate->evaluate(
            AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ATLAS_NATIVE_BOUNDED,
            AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ATLAS_NATIVE_24_7,
            $this->allFactsGreen24x7(),
        );

        $this->assertSame('promote', $verdict['verdict']);
        $this->assertEqualsCanonicalizing(
            array_merge(
                AtlasSelfConstructionAutonomyPromotionGate::REQUIRED_FACT_KEYS,
                AtlasSelfConstructionAutonomyPromotionGate::REQUIRED_FACT_KEYS_24_7,
            ),
            $verdict['satisfied_fact_keys'],
        );
    }

    public function test_holds_when_24_7_extra_facts_are_missing(): void
    {
        $gate = new AtlasSelfConstructionAutonomyPromotionGate(new AtlasSelfConstructionAutonomyLevelLadder);

        $verdict = $gate->evaluate(
            AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ATLAS_NATIVE_BOUNDED,
            AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ATLAS_NATIVE_24_7,
            $this->allFactsGreen(), // only base facts — 24/7 extras absent
        );

        $this->assertSame('hold', $verdict['verdict']);
        $this->assertEqualsCanonicalizing(
            AtlasSelfConstructionAutonomyPromotionGate::REQUIRED_FACT_KEYS_24_7,
            $verdict['missing_fact_keys'],
        );
        $this->assertSame([], $verdict['failing_fact_keys']);
    }

    public function test_holds_when_24_7_extra_fact_is_false(): void
    {
        $gate = new AtlasSelfConstructionAutonomyPromotionGate(new AtlasSelfConstructionAutonomyLevelLadder);

        $facts = $this->allFactsGreen24x7();
        $facts['unattended_liveness_green'] = false;

        $verdict = $gate->evaluate(
            AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ATLAS_NATIVE_BOUNDED,
            AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ATLAS_NATIVE_24_7,
            $facts,
        );

        $this->assertSame('hold', $verdict['verdict']);
        $this->assertContains('unattended_liveness_green', $verdict['failing_fact_keys']);
        $this->assertSame([], $verdict['missing_fact_keys']);
    }

    public function test_bounded_promotion_does_not_require_24_7_extra_facts(): void
    {
        $gate = new AtlasSelfConstructionAutonomyPromotionGate(new AtlasSelfConstructionAutonomyLevelLadder);

        // Providing only the base REQUIRED_FACT_KEYS — no 24/7 extras.
        $verdict = $gate->evaluate(
            AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ATLAS_SUPERVISED,
            AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ATLAS_NATIVE_BOUNDED,
            $this->allFactsGreen(),
        );

        $this->assertSame('promote', $verdict['verdict']);
    }
}
