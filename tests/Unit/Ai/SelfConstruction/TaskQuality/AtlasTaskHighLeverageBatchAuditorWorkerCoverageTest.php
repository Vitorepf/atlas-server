<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskQuality;

use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskHighLeverageBatchAuditor;
use PHPUnit\Framework\TestCase;

final class AtlasTaskHighLeverageBatchAuditorWorkerCoverageTest extends TestCase
{
    private function svc(): AtlasTaskHighLeverageBatchAuditor
    {
        return new AtlasTaskHighLeverageBatchAuditor;
    }

    private function spec(string $id, string $objective, array $files): array
    {
        return [
            'task_packet_id' => $id,
            'objective' => $objective,
            'allowed_files' => $files,
            'acceptance_criteria' => ['/opt/homebrew/bin/php artisan test '.$id.'Test exits 0 and returns the expected result'],
            'required_evidence' => ['tests_or_gates_result'],
            'packet_quality' => ['self_sufficient' => true, 'facts' => ['dormant_cli_arm_proxy' => false, 'test_only_has_contract' => false]],
        ];
    }

    public function test_too_few_creditable_specs_for_worker_demand_returns_worker_coverage_gap(): void
    {
        $specs = [
            $this->spec('s1', 'Strengthen the payment gateway timeout handler to retry safely', ['app/Services/Payment/TimeoutHandler.php', 'tests/Unit/Payment/TimeoutHandlerTest.php']),
            $this->spec('s2', 'Strengthen the payment gateway retry policy validator implementation', ['app/Services/Payment/RetryPolicy.php', 'tests/Unit/Payment/RetryPolicyTest.php']),
        ];

        $result = $this->svc()->audit($specs, [
            'target_claimable_floor' => 6,
            'active_worker_count' => 4,
        ]);

        $this->assertFalse($result['creditable']);
        $patterns = array_column($result['anti_proxy_facts'], 'pattern');
        $this->assertContains('worker_coverage_insufficient', $patterns);

        $fact = $result['anti_proxy_facts'][array_search('worker_coverage_insufficient', $patterns)];
        $this->assertSame(4, $fact['worker_coverage_gap']);
        $this->assertFalse($result['worker_coverage']['satisfied']);
    }

    public function test_diverse_batch_reaching_worker_coverage_floor_remains_creditable(): void
    {
        $specs = [
            $this->spec('s1', 'Fix the payment gateway timeout handler retry bug correctly', ['app/Services/Payment/TimeoutHandler.php', 'tests/Unit/Payment/TimeoutHandlerTest.php']),
            $this->spec('s2', 'Harden the search index throttling guard with a verification gate', ['app/Services/Search/ThrottleGuard.php', 'tests/Unit/Search/ThrottleGuardTest.php']),
            $this->spec('s3', 'Wire the notification dispatch retry queue handler into the registry', ['app/Services/Notification/RetryQueueHandler.php', 'tests/Unit/Notification/RetryQueueHandlerTest.php']),
            $this->spec('s4', 'Implement the billing reconciliation matcher build extend logic', ['app/Services/Billing/ReconciliationMatcher.php', 'tests/Unit/Billing/ReconciliationMatcherTest.php']),
        ];

        $result = $this->svc()->audit($specs, [
            'target_claimable_floor' => 4,
            'active_worker_count' => 3,
        ]);

        $this->assertTrue($result['creditable']);
        $this->assertTrue($result['worker_coverage']['satisfied']);
        $this->assertGreaterThan(1, $result['worker_coverage']['distinct_families']);
        $patterns = array_column($result['anti_proxy_facts'], 'pattern');
        $this->assertNotContains('worker_coverage_insufficient', $patterns);
    }

    public function test_no_batch_context_does_not_apply_worker_coverage_check(): void
    {
        $specs = [
            $this->spec('s1', 'Strengthen the payment gateway timeout handler to retry safely', ['app/Services/Payment/TimeoutHandler.php', 'tests/Unit/Payment/TimeoutHandlerTest.php']),
        ];

        $result = $this->svc()->audit($specs);

        $this->assertArrayNotHasKey('worker_coverage', $result);
    }
}
