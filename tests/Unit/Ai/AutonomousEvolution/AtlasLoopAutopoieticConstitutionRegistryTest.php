<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Autopoiesis\Constitution\AtlasLoopAutopoieticConstitutionRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AtlasLoopAutopoieticConstitutionRegistryTest extends TestCase
{
    public function test_forbidden_scopes_include_canonical_never_self_extend_targets(): void
    {
        $scopes = (new AtlasLoopAutopoieticConstitutionRegistry)->forbiddenScopes();

        foreach ([
            'app/Services/Ai/MarketingDomain',
            'app/Services/Ai/Aaeos',
            'app/Services/Ai/Forge',
            'atlas-desktop',
            '.env',
            'vendor',
        ] as $requiredScope) {
            $this->assertContains($requiredScope, $scopes);
        }
    }

    public function test_forbidden_scopes_and_fingerprint_are_deterministic_across_instances(): void
    {
        $first = new AtlasLoopAutopoieticConstitutionRegistry;
        $second = new AtlasLoopAutopoieticConstitutionRegistry;

        $this->assertSame($first->forbiddenScopes(), $second->forbiddenScopes());
        $this->assertSame($first->fingerprint(), $second->fingerprint());
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first->fingerprint());
    }

    public function test_mutating_returned_copy_does_not_change_instance_or_second_instance_fingerprint(): void
    {
        $first = new AtlasLoopAutopoieticConstitutionRegistry;
        $second = new AtlasLoopAutopoieticConstitutionRegistry;
        $before = $second->fingerprint();

        $copy = $first->forbiddenScopes();
        $copy[] = 'app/Services/Ai/MarketingDomain/Mutated';
        $rules = $first->rules();
        $rules['mandatory_operator_approval_thresholds']['new_domain'] = 'advisory';

        $this->assertSame($before, $second->fingerprint());
        $this->assertSame('two-step', $first->approvalThresholdFor('new_domain'));
        $this->assertNotContains('app/Services/Ai/MarketingDomain/Mutated', $first->forbiddenScopes());
    }

    public function test_approval_thresholds_are_fail_closed(): void
    {
        $registry = new AtlasLoopAutopoieticConstitutionRegistry;

        $this->assertSame('two-step', $registry->approvalThresholdFor('new_domain'));
        $this->assertSame('explicit', $registry->approvalThresholdFor('cross_boundary_wiring'));
        $this->assertSame('explicit', $registry->approvalThresholdFor('new_provider'));
        $this->assertSame('two-step', $registry->approvalThresholdFor('financial_action'));
        $this->assertSame(AtlasLoopAutopoieticConstitutionRegistry::UNKNOWN_CATEGORY_SENTINEL, $registry->approvalThresholdFor('unknown_category'));
    }

    public function test_registry_has_no_public_setters_and_stores_rules_in_readonly_private_state(): void
    {
        $reflection = new ReflectionClass(AtlasLoopAutopoieticConstitutionRegistry::class);

        foreach ($reflection->getMethods() as $method) {
            $this->assertFalse(str_starts_with($method->getName(), 'set'), 'registry exposes no setters');
        }

        $property = $reflection->getProperty('rules');
        $this->assertTrue($property->isPrivate());
        $this->assertTrue($property->isReadOnly());
    }
}
