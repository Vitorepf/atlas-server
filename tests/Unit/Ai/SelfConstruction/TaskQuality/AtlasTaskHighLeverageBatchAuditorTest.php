<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskQuality;

use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskHighLeverageBatchAuditor;
use PHPUnit\Framework\TestCase;

final class AtlasTaskHighLeverageBatchAuditorTest extends TestCase
{
    private function svc(): AtlasTaskHighLeverageBatchAuditor
    {
        return new AtlasTaskHighLeverageBatchAuditor;
    }

    private function spec(string $id, string $objective, array $files, array $qualityOverrides = []): array
    {
        return [
            'task_packet_id' => $id,
            'objective' => $objective,
            'allowed_files' => $files,
            'packet_quality' => array_merge(
                ['self_sufficient' => true, 'facts' => ['dormant_cli_arm_proxy' => false, 'test_only_has_contract' => false]],
                $qualityOverrides,
            ),
        ];
    }

    public function test_homogeneous_dormant_cli_arm_batch_is_not_creditable(): void
    {
        $specs = [
            $this->spec('arm-1', 'Create CLI arm wrapper that delegates to dormant underlying command class', ['app/Console/Commands/ArmA.php'],
                ['facts' => ['dormant_cli_arm_proxy' => true]]),
            $this->spec('arm-2', 'Create CLI arm wrapper that delegates to dormant underlying command class', ['app/Console/Commands/ArmB.php'],
                ['facts' => ['dormant_cli_arm_proxy' => true]]),
            $this->spec('arm-3', 'Create CLI arm wrapper that delegates to dormant underlying command class', ['app/Console/Commands/ArmC.php'],
                ['facts' => ['dormant_cli_arm_proxy' => true]]),
        ];

        $result = $this->svc()->audit($specs);

        $this->assertFalse($result['creditable']);
        $patterns = array_column($result['anti_proxy_facts'], 'pattern');
        $this->assertContains('homogeneous_dormant_cli_arm', $patterns);

        $fact = $result['anti_proxy_facts'][array_search('homogeneous_dormant_cli_arm', $patterns)];
        $this->assertSame(3, $fact['dormant_count']);
        $this->assertSame(3, $fact['total']);
        $this->assertGreaterThanOrEqual(0.5, $fact['ratio']);
    }

    public function test_singleton_microtest_batch_is_not_creditable(): void
    {
        $specs = [
            $this->spec('micro-1', 'Add characterisation test for the payment processor boundary contract verification', ['tests/Unit/Payment/ProcessorTest.php'],
                ['facts' => ['dormant_cli_arm_proxy' => false, 'test_only_has_contract' => true]]),
        ];

        $result = $this->svc()->audit($specs);

        $this->assertFalse($result['creditable']);
        $patterns = array_column($result['anti_proxy_facts'], 'pattern');
        $this->assertContains('singleton_microtest_batch', $patterns);

        $fact = $result['anti_proxy_facts'][array_search('singleton_microtest_batch', $patterns)];
        $this->assertSame('micro-1', $fact['spec_id']);
    }

    public function test_diverse_self_sufficient_batch_is_creditable(): void
    {
        $specs = [
            $this->spec('d-1', 'Fix the null pointer bug in the user authentication flow service', ['app/Services/Auth/UserService.php']),
            $this->spec('d-2', 'Harden the certification gate against malformed edge case inputs to prevent bypass', ['app/Services/Gate/CertGate.php']),
            $this->spec('d-3', 'Wire the runtime adapter into the provider registry to complete integration plumbing', ['app/Services/Runtime/RuntimeAdapter.php']),
            $this->spec('d-4', 'Synchronise the canonical documentation and wiki for the evolution loop subsystem', ['docs/evolution-loop.md']),
        ];

        $result = $this->svc()->audit($specs);

        $this->assertTrue($result['creditable'], 'Diverse self-sufficient batch must be creditable. Fired: '.implode(', ', array_column($result['anti_proxy_facts'], 'pattern')));
        $this->assertSame([], $result['anti_proxy_facts']);
        $this->assertSame(AtlasTaskHighLeverageBatchAuditor::SCHEMA, $result['schema']);
    }

    public function test_duplicate_target_family_fires_for_same_file_set(): void
    {
        $specs = [
            $this->spec('dup-1', 'Implement the authentication service constructor with dependency injection support', ['app/Services/Auth/AuthService.php']),
            $this->spec('dup-2', 'Fix the authentication service input validation for null user edge cases correctly', ['app/Services/Auth/AuthService.php']),
        ];

        $result = $this->svc()->audit($specs);

        $this->assertFalse($result['creditable']);
        $patterns = array_column($result['anti_proxy_facts'], 'pattern');
        $this->assertContains('duplicate_target_family', $patterns);
    }

    public function test_thin_quota_farming_fires_when_majority_objectives_are_short(): void
    {
        $specs = [
            $this->spec('t-1', 'Add type', ['a.php']),
            $this->spec('t-2', 'Fix it', ['b.php']),
            $this->spec('t-3', 'Implement a comprehensive self-construction certification harness with evidence ledger integration', ['c.php']),
        ];

        $result = $this->svc()->audit($specs);

        $patterns = array_column($result['anti_proxy_facts'], 'pattern');
        $this->assertContains('thin_quota_farming', $patterns);
    }

    public function test_low_diversity_leverage_class_fires_when_batch_homogeneous(): void
    {
        $specs = [
            $this->spec('lev-1', 'Fix the first authentication bug causing null pointer exception', ['a.php']),
            $this->spec('lev-2', 'Fix the second routing bug causing incorrect redirect response', ['b.php']),
            $this->spec('lev-3', 'Fix the third serialisation bug causing malformed json output', ['c.php']),
        ];

        $result = $this->svc()->audit($specs);

        $patterns = array_column($result['anti_proxy_facts'], 'pattern');
        $this->assertContains('low_diversity_leverage_class', $patterns);

        $fact = $result['anti_proxy_facts'][array_search('low_diversity_leverage_class', $patterns)];
        $this->assertSame('bug_fix', $fact['dominant_class']);
    }

    public function test_non_self_sufficient_spec_makes_batch_not_creditable(): void
    {
        $specs = [
            $this->spec('nss-1', 'Fix the null pointer bug in the user authentication flow service', ['a.php'],
                ['self_sufficient' => false]),
        ];

        $result = $this->svc()->audit($specs);

        $this->assertFalse($result['creditable']);
        $patterns = array_column($result['anti_proxy_facts'], 'pattern');
        $this->assertContains('non_self_sufficient_spec', $patterns);
    }

    public function test_empty_batch_returns_creditable_true(): void
    {
        $result = $this->svc()->audit([]);

        $this->assertTrue($result['creditable']);
        $this->assertSame([], $result['anti_proxy_facts']);
    }
}
