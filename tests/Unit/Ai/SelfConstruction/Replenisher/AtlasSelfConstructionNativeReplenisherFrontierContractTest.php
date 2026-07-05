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

    // ── AC: proxy or cosmetic opportunity kinds are rejected with proxy_kind reason ──

    public function test_proxy_kind_rejected_with_proxy_kind_reason(): void
    {
        $result = $this->contract->normalize([
            [
                'frontier_id' => 'f-1',
                'kind' => 'proxy',
                'target_scope' => 'scope-1',
                'allowed_file_candidates' => ['app/Foo.php', 'tests/FooTest.php'],
                'acceptance_obligations' => ['php artisan test tests/FooTest.php'],
                'evidence_obligations' => ['evidence-1'],
            ],
        ]);

        $this->assertCount(0, $result['accepted']);
        $this->assertCount(1, $result['rejected']);
        $this->assertContains('proxy_kind:proxy', $result['rejected'][0]['blockers']);
    }

    public function test_cosmetic_kind_rejected_with_proxy_kind_reason(): void
    {
        $result = $this->contract->normalize([
            [
                'frontier_id' => 'f-1',
                'kind' => 'cosmetic',
                'target_scope' => 'scope-1',
                'allowed_file_candidates' => ['app/Foo.php', 'tests/FooTest.php'],
                'acceptance_obligations' => ['php artisan test tests/FooTest.php'],
                'evidence_obligations' => ['evidence-1'],
            ],
        ]);

        $this->assertCount(1, $result['rejected']);
        $this->assertContains('proxy_kind:cosmetic', $result['rejected'][0]['blockers']);
    }

    public function test_whitespace_kind_rejected(): void
    {
        $result = $this->contract->normalize([
            [
                'frontier_id' => 'f-1',
                'kind' => 'whitespace',
                'target_scope' => 'scope-1',
                'allowed_file_candidates' => ['app/Foo.php', 'tests/FooTest.php'],
                'acceptance_obligations' => ['php artisan test tests/FooTest.php'],
                'evidence_obligations' => ['evidence-1'],
            ],
        ]);

        $this->assertCount(1, $result['rejected']);
    }

    public function test_comment_only_kind_rejected(): void
    {
        $result = $this->contract->normalize([
            [
                'frontier_id' => 'f-1',
                'kind' => 'comment-only',
                'target_scope' => 'scope-1',
                'allowed_file_candidates' => ['app/Foo.php', 'tests/FooTest.php'],
                'acceptance_obligations' => ['php artisan test tests/FooTest.php'],
                'evidence_obligations' => ['evidence-1'],
            ],
        ]);

        $this->assertCount(1, $result['rejected']);
    }

    public function test_cyclomatic_only_kind_rejected(): void
    {
        $result = $this->contract->normalize([
            [
                'frontier_id' => 'f-1',
                'kind' => 'cyclomatic-only',
                'target_scope' => 'scope-1',
                'allowed_file_candidates' => ['app/Foo.php', 'tests/FooTest.php'],
                'acceptance_obligations' => ['php artisan test tests/FooTest.php'],
                'evidence_obligations' => ['evidence-1'],
            ],
        ]);

        $this->assertCount(1, $result['rejected']);
    }

    // ── AC: accepted frontier facts include capability_delta, owner_file and runnable_gate ──

    public function test_accepted_frontier_includes_capability_delta(): void
    {
        $result = $this->contract->normalize([
            [
                'frontier_id' => 'f-1',
                'target_scope' => 'scope-1',
                'capability_delta' => 'adds_new_capability',
                'allowed_file_candidates' => ['app/Foo.php', 'tests/FooTest.php'],
                'acceptance_obligations' => ['php artisan test tests/FooTest.php'],
                'evidence_obligations' => ['evidence-1'],
            ],
        ]);

        $this->assertCount(1, $result['accepted']);
        $this->assertSame('adds_new_capability', $result['accepted'][0]['capability_delta']);
    }

    public function test_accepted_frontier_includes_owner_file(): void
    {
        $result = $this->contract->normalize([
            [
                'frontier_id' => 'f-1',
                'target_scope' => 'scope-1',
                'allowed_file_candidates' => ['app/Services/Foo.php', 'tests/FooTest.php'],
                'acceptance_obligations' => ['php artisan test tests/FooTest.php'],
                'evidence_obligations' => ['evidence-1'],
            ],
        ]);

        $this->assertCount(1, $result['accepted']);
        $this->assertSame('app/Services/Foo.php', $result['accepted'][0]['owner_file']);
    }

    public function test_accepted_frontier_includes_runnable_gate(): void
    {
        $result = $this->contract->normalize([
            [
                'frontier_id' => 'f-1',
                'target_scope' => 'scope-1',
                'allowed_file_candidates' => ['app/Foo.php', 'tests/FooTest.php'],
                'acceptance_obligations' => ['php artisan test tests/FooTest.php'],
                'evidence_obligations' => ['evidence-1'],
            ],
        ]);

        $this->assertCount(1, $result['accepted']);
        $this->assertNotEmpty($result['accepted'][0]['runnable_gate']);
    }

    // ── AC: risk defaults to standard only when no concrete higher risk is present ──

    public function test_risk_defaults_to_standard_when_not_specified(): void
    {
        $result = $this->contract->normalize([
            [
                'frontier_id' => 'f-1',
                'target_scope' => 'scope-1',
                'allowed_file_candidates' => ['app/Foo.php', 'tests/FooTest.php'],
                'acceptance_obligations' => ['php artisan test tests/FooTest.php'],
                'evidence_obligations' => ['evidence-1'],
            ],
        ]);

        $this->assertSame('standard', $result['accepted'][0]['risk_class']);
    }

    public function test_risk_uses_specified_value_when_present(): void
    {
        $result = $this->contract->normalize([
            [
                'frontier_id' => 'f-1',
                'target_scope' => 'scope-1',
                'risk_class' => 'high',
                'allowed_file_candidates' => ['app/Foo.php', 'tests/FooTest.php'],
                'acceptance_obligations' => ['php artisan test tests/FooTest.php'],
                'evidence_obligations' => ['evidence-1'],
            ],
        ]);

        $this->assertSame('high', $result['accepted'][0]['risk_class']);
    }
}
