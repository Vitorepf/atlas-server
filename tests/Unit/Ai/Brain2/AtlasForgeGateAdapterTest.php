<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\EngineeringKernel\Adapters\AtlasForgeGateAdapter;
use App\Services\Ai\EngineeringKernel\CriteriaCanonicalizer;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasForgeGateAdapter routes Forge deliveries through the same
 * sovereign honesty floor as Dev, so a lint-only Forge delivery is rejected
 * and a real-suite delivery is accepted (bar(dev)=bar(forge)).
 */
final class AtlasForgeGateAdapterTest extends TestCase
{
    private AtlasForgeGateAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new AtlasForgeGateAdapter;
    }

    public function test_lint_only_forge_delivery_is_rejected_by_sovereign_floor(): void
    {
        // A Forge delivery that claims a whole suite (non-empty selected_tests)
        // but only ran "php -l" — the floor's 'lint_only_run_presented_as_suite'
        // invariant fires and refuses promotion.
        $criteria = ['test: aemor cert reports test counts'];
        $frozenHash = CriteriaCanonicalizer::hash($criteria);

        $verdict = $this->adapter->certifyForgeDelivery([
            'criteria_hash' => $frozenHash,
            'frozen_hash' => $frozenHash,
            'changed_files' => ['app/Services/Ai/Aemor/AtlasAemorCertificationService.php'],
            'execution' => [
                'commands' => ['php -l app/Services/Ai/Aemor/AtlasAemorCertificationService.php'],
                'claimed_status' => 'passed',
                'tests_run' => 0,
                'assertions_executed' => 0,
                'selected_tests' => ['tests/Feature/Ai/Aemor/AtlasAemorCertificationTestExecutionTest.php'],
                'artifacts' => [],
            ],
            'context_sufficiency' => 85,
            'judges' => [
                ['name' => 'judge-alpha', 'provider_family' => 'verboo', 'approved' => true],
                ['name' => 'judge-beta', 'provider_family' => 'openai', 'approved' => true],
            ],
            'changed_public_symbols' => [
                ['symbol' => 'AtlasAemorCertificationService', 'has_criterion' => true, 'has_test' => true],
            ],
        ]);

        $this->assertFalse($verdict->promoted(), 'lint-only Forge delivery must be refused');
        $this->assertContains('false_claim_blocked', $verdict->blockers);
    }

    public function test_real_suite_forge_delivery_is_accepted_by_sovereign_floor(): void
    {
        // A genuine Forge delivery with a real test runner (php artisan test),
        // non-zero tests and assertions, sufficient context, diverse judges,
        // and matching criteria hashes — all invariants pass.
        $criteria = ['test: real suite with assertions'];
        $frozenHash = CriteriaCanonicalizer::hash($criteria);

        $verdict = $this->adapter->certifyForgeDelivery([
            'criteria_hash' => $frozenHash,
            'frozen_hash' => $frozenHash,
            'changed_files' => ['app/Services/Ai/Aemor/AtlasAemorCertificationService.php'],
            'execution' => [
                'commands' => ['php artisan test tests/Feature/Ai/Aemor/AtlasAemorCertificationTestExecutionTest.php'],
                'claimed_status' => 'passed',
                'tests_run' => 5,
                'assertions_executed' => 15,
                'selected_tests' => ['AtlasAemorCertificationTestExecutionTest'],
                'artifacts' => [],
            ],
            'context_sufficiency' => 90,
            'judges' => [
                ['name' => 'judge-1', 'provider_family' => 'anthropic', 'approved' => true],
                ['name' => 'judge-2', 'provider_family' => 'google', 'approved' => true],
            ],
            'changed_public_symbols' => [
                ['symbol' => 'AtlasAemorCertificationService', 'has_criterion' => true, 'has_test' => true],
            ],
        ]);

        $this->assertTrue($verdict->promoted(), 'real-suite Forge delivery must be promoted');
        $this->assertEmpty($verdict->blockers, 'no blockers for a real-suite delivery');
    }
}
