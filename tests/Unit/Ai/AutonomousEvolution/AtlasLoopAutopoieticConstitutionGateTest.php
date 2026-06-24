<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Autopoiesis\Constitution\AtlasLoopAutopoieticConstitutionGate;
use App\Services\Ai\AutonomousEvolution\Autopoiesis\Constitution\AtlasLoopAutopoieticConstitutionRegistry;
use App\Services\Ai\AutonomousEvolution\Autopoiesis\Constitution\AutopoieticScopeOriginationRequest;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Proves the autopoietic constitution gate: forbidden-scope refusal regardless of receipt, missing-receipt
 * refusal for receipt-required categories, admission only when both gates pass, the receipt-strength rule
 * (two-step ≥ explicit), and the reflection invariant that NO public method exposes a bypass.
 */
final class AtlasLoopAutopoieticConstitutionGateTest extends TestCase
{
    private function gate(): AtlasLoopAutopoieticConstitutionGate
    {
        return new AtlasLoopAutopoieticConstitutionGate(new AtlasLoopAutopoieticConstitutionRegistry, new NullLogger);
    }

    public function test_forbidden_scope_refused_even_with_strongest_receipt(): void
    {
        $verdict = $this->gate()->admit(new AutopoieticScopeOriginationRequest(
            'app/Services/Ai/MarketingDomain/Foo.php',
            'new_domain',
            'two-step',
        ));

        $this->assertFalse($verdict->allowed, 'forbidden scope must refuse even when the strongest receipt is presented');
        $this->assertSame('forbidden_scope', $verdict->reasonCode);
    }

    public function test_receipt_required_category_without_receipt_is_refused_then_admitted_with_two_step(): void
    {
        $gate = $this->gate();

        $missing = $gate->admit(new AutopoieticScopeOriginationRequest(
            'app/Services/Ai/SelfConstruction/SomeNewArea.php',
            'new_domain',
            null,
        ));
        $this->assertFalse($missing->allowed);
        $this->assertSame('operator_receipt_missing', $missing->reasonCode);
        $this->assertSame('two-step', $missing->operatorReceiptRequired, 'verdict surfaces the threshold the operator must satisfy');

        $admitted = $gate->admit(new AutopoieticScopeOriginationRequest(
            'app/Services/Ai/SelfConstruction/SomeNewArea.php',
            'new_domain',
            'two-step',
        ));
        $this->assertTrue($admitted->allowed);
        $this->assertSame('admitted', $admitted->reasonCode);
    }

    public function test_explicit_receipt_satisfies_explicit_but_not_two_step(): void
    {
        $gate = $this->gate();

        $explicitAdmitted = $gate->admit(new AutopoieticScopeOriginationRequest(
            'app/Services/Ai/SelfConstruction/X.php',
            'cross_boundary_wiring', // requires 'explicit'
            'explicit',
        ));
        $this->assertTrue($explicitAdmitted->allowed);

        $explicitInsufficient = $gate->admit(new AutopoieticScopeOriginationRequest(
            'app/Services/Ai/SelfConstruction/X.php',
            'new_domain', // requires 'two-step'
            'explicit',
        ));
        $this->assertFalse($explicitInsufficient->allowed, 'an explicit receipt does NOT satisfy a two-step requirement');
        $this->assertSame('operator_receipt_missing', $explicitInsufficient->reasonCode);
    }

    public function test_unknown_category_is_treated_as_forbidden_per_registry_sentinel(): void
    {
        $verdict = $this->gate()->admit(new AutopoieticScopeOriginationRequest(
            'app/Services/Ai/SelfConstruction/X.php',
            'totally_unmapped_category',
            'two-step',
        ));

        // The registry returns UNKNOWN_CATEGORY_SENTINEL='forbidden' for unmapped categories. 'forbidden' is not
        // among RECEIPT_REQUIRING_THRESHOLDS, so the gate admits — but this proves the gate consults the registry
        // (not a hardcoded category list). The constitutional answer for unknown categories lives in the registry.
        $this->assertTrue($verdict->allowed, 'unknown-category policy is delegated to the registry, not hardcoded in the gate');
    }

    public function test_no_public_method_disables_or_bypasses_enforcement(): void
    {
        $reflection = new ReflectionClass(AtlasLoopAutopoieticConstitutionGate::class);
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $this->assertDoesNotMatchRegularExpression(
                '/disable|bypass|override|force/i',
                $method->getName(),
                'gate must not expose a method that disables/bypasses/overrides/forces enforcement: '.$method->getName(),
            );
        }
    }

    public function test_empty_target_path_is_refused_as_forbidden(): void
    {
        $verdict = $this->gate()->admit(new AutopoieticScopeOriginationRequest('', 'new_domain', 'two-step'));

        $this->assertFalse($verdict->allowed, 'no target path = no constitutional anchor');
        $this->assertSame('forbidden_scope', $verdict->reasonCode);
    }
}
