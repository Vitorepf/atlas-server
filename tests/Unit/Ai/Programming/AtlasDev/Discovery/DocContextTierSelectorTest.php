<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Discovery;

use App\Services\Ai\Programming\AtlasDev\Discovery\DocContextTierSelector;
use PHPUnit\Framework\TestCase;

final class DocContextTierSelectorTest extends TestCase
{
    public function test_question_r0_minimal_tiers_no_core_required_doc(): void
    {
        $selector = new DocContextTierSelector;
        $envelope = DiscoveryFixtureFactory::envelope([
            'normalized_intent' => 'explique o fluxo do AtlasDev',
            'permission_mode' => 'read',
            'write_allowed' => false,
        ]);
        $compactSdd = DiscoveryFixtureFactory::compactSdd([
            'task_kind' => 'question',
            'risk_level' => 'R0',
            'mode' => 'read_only',
            'verification_profile' => 'generic_no_test',
            'budget' => ['max_chars' => 4000],
        ]);

        $plan = $selector->select($envelope, $compactSdd);

        $this->assertContains('code_intelligence', $plan->selectedTiers);
        $this->assertContains('interface', $plan->selectedTiers, 'desktop_ai surface should pull interface tier');
        $this->assertNotContains('core', $plan->selectedTiers, 'question task_kind must skip the core tier');
        $this->assertNotContains('sdd', $plan->selectedTiers);
        $this->assertNotContains('forge', $plan->selectedTiers);
        $this->assertSame(4000, $plan->budgetChars);
        $this->assertSame([], $plan->requiredSources, 'question read-only run should not require any docs');
    }

    public function test_patch_r2_selects_core_sdd_and_workspace_optional(): void
    {
        $selector = new DocContextTierSelector;
        $envelope = DiscoveryFixtureFactory::envelope();
        $compactSdd = DiscoveryFixtureFactory::compactSdd([
            'task_kind' => 'patch',
            'risk_level' => 'R2',
            'mode' => 'patch',
        ]);

        $plan = $selector->select($envelope, $compactSdd);

        $this->assertContains('core', $plan->selectedTiers);
        $this->assertContains('code_intelligence', $plan->selectedTiers);
        $this->assertContains('sdd', $plan->selectedTiers);
        $this->assertContains('interface', $plan->selectedTiers, 'atlas_desktop_ai surface still pulls interface tier');
        $this->assertNotContains('forge', $plan->selectedTiers);
        $this->assertContains(
            'doc://engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md',
            $plan->requiredSources,
        );
        $this->assertContains(
            'doc://engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1.md',
            $plan->requiredSources,
        );
    }

    public function test_r4_risky_forces_forge_tier_and_required_source(): void
    {
        $selector = new DocContextTierSelector;
        $envelope = DiscoveryFixtureFactory::envelope();
        $compactSdd = DiscoveryFixtureFactory::compactSdd([
            'task_kind' => 'risky',
            'risk_level' => 'R4',
            'mode' => 'escalate_preview',
            'budget' => ['max_chars' => 22000],
        ]);

        $plan = $selector->select($envelope, $compactSdd);

        $this->assertContains('forge', $plan->selectedTiers);
        $this->assertContains(
            'doc://engineering-knowledge-base/atlas-forge-operating-system.md',
            $plan->requiredSources,
        );
        $this->assertSame('required', $plan->truncationPolicy['open_brain_mode']);
    }

    public function test_open_brain_mode_off_from_user_constraint(): void
    {
        $selector = new DocContextTierSelector;
        $envelope = DiscoveryFixtureFactory::envelope([
            'user_constraints' => ['open_brain=off'],
        ]);
        $compactSdd = DiscoveryFixtureFactory::compactSdd();

        $plan = $selector->select($envelope, $compactSdd);

        $this->assertSame('off', $plan->truncationPolicy['open_brain_mode']);
        $this->assertContains('open_brain_mode=off→required_memory_refs_skipped', $plan->truncationPolicy['reasons']);
    }

    public function test_tiers_are_returned_in_canonical_order(): void
    {
        $selector = new DocContextTierSelector;
        $envelope = DiscoveryFixtureFactory::envelope();
        $compactSdd = DiscoveryFixtureFactory::compactSdd([
            'task_kind' => 'risky',
            'risk_level' => 'R4',
            'mode' => 'escalate_preview',
        ]);

        $plan = $selector->select($envelope, $compactSdd);

        $expected = ['core', 'code_intelligence', 'sdd', 'interface', 'forge'];
        $this->assertSame($expected, array_values(array_intersect($expected, $plan->selectedTiers)));
        $this->assertSame(array_values($plan->selectedTiers), array_intersect($expected, $plan->selectedTiers));
    }

    public function test_select_is_deterministic_byte_identical_payload(): void
    {
        $selector = new DocContextTierSelector;
        $envelope = DiscoveryFixtureFactory::envelope();
        $compactSdd = DiscoveryFixtureFactory::compactSdd();

        $a = $selector->select($envelope, $compactSdd);
        $b = $selector->select($envelope, $compactSdd);

        $this->assertSame($a->toJson(), $b->toJson());
        $this->assertSame($a->planHash, $b->planHash);
    }

    public function test_no_rivals_or_benchmark_leakage_in_canonical_payload(): void
    {
        $selector = new DocContextTierSelector;
        $envelope = DiscoveryFixtureFactory::envelope();
        $compactSdd = DiscoveryFixtureFactory::compactSdd();

        $serialized = $selector->select($envelope, $compactSdd)->toJson();

        foreach (['rivals', 'benchmark', 'opus', 'messy_human_local', 'cost_normalized_score'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $serialized);
        }
    }
}
