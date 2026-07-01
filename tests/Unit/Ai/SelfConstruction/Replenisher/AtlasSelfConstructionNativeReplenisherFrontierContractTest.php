<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Replenisher;

use App\Services\Ai\SelfConstruction\Replenisher\AtlasSelfConstructionNativeReplenisherFrontierContract;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionNativeReplenisherFrontierContractTest extends TestCase
{
    private AtlasSelfConstructionNativeReplenisherFrontierContract $contract;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contract = new AtlasSelfConstructionNativeReplenisherFrontierContract();
    }

    // AC: frontiers with only tests, only impl, or no runnable gate → rejected
    public function test_test_only_rejected(): void
    {
        $result = $this->contract->validate([
            'test_files' => ['tests/XTest.php'],
            'acceptance_criteria' => ['test passes'],
            'runnable_gate' => 'php artisan test',
        ]);

        $this->assertFalse($result['accepted']);
        $this->assertContains('missing:implementation_files', $result['blockers']);
    }

    public function test_impl_only_rejected(): void
    {
        $result = $this->contract->validate([
            'implementation_files' => ['app/X.php'],
            'acceptance_criteria' => ['test passes'],
            'runnable_gate' => 'php artisan test',
        ]);

        $this->assertFalse($result['accepted']);
        $this->assertContains('missing:test_files', $result['blockers']);
    }

    public function test_no_runnable_gate_rejected(): void
    {
        $result = $this->contract->validate([
            'implementation_files' => ['app/X.php'],
            'test_files' => ['tests/XTest.php'],
            'acceptance_criteria' => ['test passes'],
        ]);

        $this->assertFalse($result['accepted']);
        $this->assertContains('missing:runnable_gate', $result['blockers']);
    }

    public function test_no_acceptance_rejected(): void
    {
        $result = $this->contract->validate([
            'implementation_files' => ['app/X.php'],
            'test_files' => ['tests/XTest.php'],
            'runnable_gate' => 'php artisan test',
        ]);

        $this->assertFalse($result['accepted']);
        $this->assertContains('missing:acceptance_criteria', $result['blockers']);
    }

    public function test_complete_frontier_accepted(): void
    {
        $result = $this->contract->validate([
            'implementation_files' => ['app/X.php'],
            'test_files' => ['tests/XTest.php'],
            'acceptance_criteria' => ['test passes'],
            'runnable_gate' => 'php artisan test tests/XTest.php',
        ]);

        $this->assertTrue($result['accepted']);
        $this->assertEmpty($result['blockers']);
    }

    public function test_empty_frontier_rejected_with_all_blockers(): void
    {
        $result = $this->contract->validate([]);

        $this->assertFalse($result['accepted']);
        $this->assertCount(4, $result['blockers']);
    }
}
