<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\EngineeringKernel\Adapters\AtlasAutonomosGateAdapter;
use App\Services\Ai\EngineeringKernel\CriteriaCanonicalizer;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasAutonomosGateAdapter routes autonomous loop deliveries through
 * the same sovereign honesty floor as Dev, so a lint-only autónomos delivery
 * is rejected and a real-suite delivery is accepted (bar(dev)=bar(forge)=bar(autonomos)).
 */
final class AtlasAutonomosGateAdapterTest extends TestCase
{
    private AtlasAutonomosGateAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new AtlasAutonomosGateAdapter;
    }

    public function test_lint_only_autonomos_delivery_is_rejected_by_sovereign_floor(): void
    {
        $criteria = ['test: autonomos delivery'];
        $frozenHash = CriteriaCanonicalizer::hash($criteria);

        $verdict = $this->adapter->certifyAutonomosDelivery([
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
                ['name' => 'judge-1', 'provider_family' => 'verboo', 'approved' => true],
                ['name' => 'judge-2', 'provider_family' => 'openai', 'approved' => true],
            ],
            'changed_public_symbols' => [
                ['symbol' => 'AtlasAemorCertificationService', 'has_criterion' => true, 'has_test' => true],
            ],
        ]);

        $this->assertFalse($verdict->promoted(), 'lint-only autonomos delivery must be refused');
        $this->assertContains('false_claim_blocked', $verdict->blockers);
    }

    public function test_real_suite_autonomos_delivery_is_accepted_by_sovereign_floor(): void
    {
        $criteria = ['test: real autonomos suite'];
        $frozenHash = CriteriaCanonicalizer::hash($criteria);

        $verdict = $this->adapter->certifyAutonomosDelivery([
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

        $this->assertTrue($verdict->promoted(), 'real-suite autonomos delivery must be promoted');
        $this->assertEmpty($verdict->blockers, 'no blockers for a real-suite delivery');
    }
}
