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

    private function spec(string $id, string $objective, array $files, array $qualityOverrides = [], array $overrides = []): array
    {
        return array_merge([
            'task_packet_id' => $id,
            'objective' => $objective,
            'allowed_files' => $files,
            'acceptance_criteria' => ['/opt/homebrew/bin/php artisan test '.$id.'Test exits 0 and returns the expected result'],
            'required_evidence' => ['tests_or_gates_result'],
            'packet_quality' => array_merge(
                ['self_sufficient' => true, 'facts' => ['dormant_cli_arm_proxy' => false, 'test_only_has_contract' => false]],
                $qualityOverrides,
            ),
        ], $overrides);
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

    public function test_output_includes_frontier_floor_passed_and_batch_hints(): void
    {
        $specs = [
            $this->spec('arm-1', 'Create CLI arm wrapper that delegates to dormant underlying command class', ['app/Console/Commands/ArmA.php'],
                ['facts' => ['dormant_cli_arm_proxy' => true]]),
        ];

        $result = $this->svc()->audit($specs);

        $this->assertArrayHasKey('frontier_floor_passed', $result);
        $this->assertArrayHasKey('batch_hints', $result);
        $this->assertFalse($result['frontier_floor_passed']);
        $this->assertNotEmpty($result['batch_hints']);
    }

    public function test_diverse_self_sufficient_batch_is_creditable(): void
    {
        $specs = [
            $this->spec('d-1', 'Fix the null pointer bug in the user authentication flow service', ['app/Services/Auth/UserService.php', 'tests/Unit/Auth/UserServiceTest.php']),
            $this->spec('d-2', 'Harden the certification gate against malformed edge case inputs to prevent bypass', ['app/Services/Gate/CertGate.php', 'tests/Unit/Gate/CertGateTest.php']),
            $this->spec('d-3', 'Wire the runtime adapter into the provider registry to complete integration plumbing', ['app/Services/Runtime/RuntimeAdapter.php', 'tests/Unit/Runtime/RuntimeAdapterTest.php']),
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

    public function test_semantic_near_duplicate_objectives_fire_even_with_different_allowed_files(): void
    {
        $specs = [
            $this->spec('farm-1', 'Upgrade AlphaGateService so the gate validates input deterministically', ['app/Services/Gate/AlphaGateService.php', 'tests/Unit/Gate/AlphaGateServiceTest.php']),
            $this->spec('farm-2', 'Upgrade BetaGateService so the gate validates input deterministically', ['app/Services/Gate/BetaGateService.php', 'tests/Unit/Gate/BetaGateServiceTest.php']),
        ];

        $result = $this->svc()->audit($specs);

        $this->assertFalse($result['creditable']);
        $patterns = array_column($result['anti_proxy_facts'], 'pattern');
        $this->assertContains('semantic_near_duplicate_template_farm', $patterns);
        $fact = $result['anti_proxy_facts'][array_search('semantic_near_duplicate_template_farm', $patterns)];
        $this->assertContains('farm-1', $fact['spec_ids']);
        $this->assertContains('farm-2', $fact['spec_ids']);
    }

    public function test_spec_missing_implementation_test_pair_runnable_acceptance_or_evidence_is_low_implementability(): void
    {
        $specs = [
            $this->spec('li-1', 'Implement a comprehensive validation service for incoming requests', ['app/Services/Validation/Service.php'],
                [], ['acceptance_criteria' => [], 'required_evidence' => []]),
        ];

        $result = $this->svc()->audit($specs);

        $this->assertFalse($result['creditable']);
        $patterns = array_column($result['anti_proxy_facts'], 'pattern');
        $this->assertContains('low_implementability_spec', $patterns);
        $fact = $result['anti_proxy_facts'][array_search('low_implementability_spec', $patterns)];
        $this->assertContains('missing_implementation_or_test_pair', $fact['reasons']);
        $this->assertContains('no_runnable_acceptance_criterion', $fact['reasons']);
        $this->assertContains('no_required_evidence', $fact['reasons']);
    }

    public function test_doc_only_spec_is_exempt_from_implementation_test_pair_requirement(): void
    {
        $specs = [
            $this->spec('doc-1', 'Synchronise the canonical engineering documentation for the gate subsystem', ['docs/gate-subsystem.md']),
        ];

        $result = $this->svc()->audit($specs);

        $allReasons = array_merge([], ...array_map(
            static fn (array $f): array => (array) ($f['reasons'] ?? []),
            $result['anti_proxy_facts'],
        ));
        $this->assertNotContains('missing_implementation_or_test_pair', $allReasons, 'a doc-only spec must never be flagged for lacking an implementation/test pair');
    }

    public function test_exit_code_only_acceptance_criteria_is_weak(): void
    {
        $specs = [
            $this->spec('weak-1', 'Implement a comprehensive validation service for incoming requests', ['app/Services/Validation/Service.php', 'tests/Unit/Validation/ServiceTest.php'],
                [], ['acceptance_criteria' => ['/opt/homebrew/bin/php artisan test ServiceTest exits 0']]),
        ];

        $result = $this->svc()->audit($specs);

        $this->assertFalse($result['creditable']);
        $patterns = array_column($result['anti_proxy_facts'], 'pattern');
        $this->assertContains('weak_acceptance_criteria', $patterns);
    }

    public function test_no_provider_call_queue_mutation_or_db_table_in_source(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../../../../app/Services/Ai/SelfConstruction/TaskQuality/AtlasTaskHighLeverageBatchAuditor.php');
        foreach (['Http::', '->enqueue(', 'DB::table(', 'Schema::', 'Artisan::call('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "auditor must not perform {$forbidden}");
        }
    }

    // ── AC2: single-family volume fires unconditionally, even without batchContext ──

    public function test_single_family_volume_fires_without_batch_context_when_batch_is_large(): void
    {
        $specs = [
            $this->spec('sf-1', 'Fix the first authentication bug causing null pointer exception', ['app/Services/Auth/A.php']),
            $this->spec('sf-2', 'Harden the routing guard against invalid session tokens correctly', ['app/Services/Auth/B.php']),
            $this->spec('sf-3', 'Wire the serialisation adapter into the registry to complete plumbing', ['app/Services/Auth/C.php']),
        ];

        $result = $this->svc()->audit($specs);

        $patterns = array_column($result['anti_proxy_facts'], 'pattern');
        $this->assertContains('single_family_volume', $patterns);
    }

    public function test_single_family_volume_does_not_fire_for_multi_family_batch(): void
    {
        $result = $this->svc()->audit([
            $this->spec('d-1', 'Fix the null pointer bug in the user authentication flow service', ['app/Services/Auth/UserService.php', 'tests/Unit/Auth/UserServiceTest.php']),
            $this->spec('d-2', 'Harden the certification gate against malformed edge case inputs to prevent bypass', ['app/Services/Gate/CertGate.php', 'tests/Unit/Gate/CertGateTest.php']),
            $this->spec('d-3', 'Wire the runtime adapter into the provider registry to complete integration plumbing', ['app/Services/Runtime/RuntimeAdapter.php', 'tests/Unit/Runtime/RuntimeAdapterTest.php']),
        ]);

        $patterns = array_column($result['anti_proxy_facts'], 'pattern');
        $this->assertNotContains('single_family_volume', $patterns);
    }

    // ── AC3: leverage_rewards score distinct capability, simplification, risk reduction, unlocks, proof ──

    public function test_leverage_rewards_present_in_output(): void
    {
        $result = $this->svc()->audit([
            $this->spec('d-1', 'Fix the null pointer bug in the user authentication flow service', ['app/Services/Auth/UserService.php', 'tests/Unit/Auth/UserServiceTest.php']),
        ]);

        foreach (['distinct_capability_classes', 'simplification_count', 'risk_reduction_count', 'downstream_unlocks_count', 'runnable_proof_count', 'reward_score'] as $key) {
            $this->assertArrayHasKey($key, $result['leverage_rewards'], "missing key: {$key}");
        }
    }

    public function test_simplification_and_risk_reduction_keywords_are_counted(): void
    {
        $result = $this->svc()->audit([
            $this->spec('s-1', 'Simplify and dedupe the certification workbench quartet builders significantly', ['app/Services/Foo/A.php']),
            $this->spec('s-2', 'Harden the gate against regression risk with a fail-closed safety check', ['app/Services/Bar/B.php']),
        ]);

        $this->assertSame(1, $result['leverage_rewards']['simplification_count']);
        $this->assertSame(1, $result['leverage_rewards']['risk_reduction_count']);
    }

    public function test_downstream_unlocks_are_counted_when_declared(): void
    {
        $result = $this->svc()->audit([
            $this->spec('u-1', 'Implement the billing reconciliation matcher build extend logic', ['app/Services/Billing/A.php'],
                [], ['unlocks' => ['other-task-id']]),
        ]);

        $this->assertSame(1, $result['leverage_rewards']['downstream_unlocks_count']);
    }

    public function test_reward_score_is_higher_for_diverse_high_leverage_batch_than_narrow_batch(): void
    {
        $diverse = $this->svc()->audit([
            $this->spec('d-1', 'Fix the null pointer bug in the user authentication flow service', ['app/Services/Auth/UserService.php', 'tests/Unit/Auth/UserServiceTest.php']),
            $this->spec('d-2', 'Harden the certification gate against malformed edge case inputs to prevent bypass', ['app/Services/Gate/CertGate.php', 'tests/Unit/Gate/CertGateTest.php']),
            $this->spec('d-3', 'Wire the runtime adapter into the provider registry to complete integration plumbing', ['app/Services/Runtime/RuntimeAdapter.php', 'tests/Unit/Runtime/RuntimeAdapterTest.php']),
        ]);

        $narrow = $this->svc()->audit([
            $this->spec('n-1', 'Fix the first authentication bug causing null pointer exception', ['a.php']),
            $this->spec('n-2', 'Fix the second routing bug causing incorrect redirect response', ['b.php']),
            $this->spec('n-3', 'Fix the third serialisation bug causing malformed json output', ['c.php']),
        ]);

        $this->assertGreaterThan($narrow['leverage_rewards']['reward_score'], $diverse['leverage_rewards']['reward_score']);
    }

    // ── AC4: accept / trim / rewrite / reject decision + per-weak-task reasons ────

    public function test_creditable_batch_decision_is_accept(): void
    {
        $result = $this->svc()->audit([
            $this->spec('d-1', 'Fix the null pointer bug in the user authentication flow service', ['app/Services/Auth/UserService.php', 'tests/Unit/Auth/UserServiceTest.php']),
            $this->spec('d-2', 'Harden the certification gate against malformed edge case inputs to prevent bypass', ['app/Services/Gate/CertGate.php', 'tests/Unit/Gate/CertGateTest.php']),
            $this->spec('d-3', 'Wire the runtime adapter into the provider registry to complete integration plumbing', ['app/Services/Runtime/RuntimeAdapter.php', 'tests/Unit/Runtime/RuntimeAdapterTest.php']),
            $this->spec('d-4', 'Synchronise the canonical documentation and wiki for the evolution loop subsystem', ['docs/evolution-loop.md']),
        ]);

        $this->assertSame('accept', $result['decision']);
        $this->assertSame([], $result['weak_task_reasons']);
    }

    public function test_batch_wide_pattern_yields_rewrite_decision(): void
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

        $this->assertSame('rewrite', $result['decision']);
    }

    public function test_one_weak_spec_among_healthy_batch_yields_trim_decision_with_reason(): void
    {
        $specs = [
            $this->spec('d-1', 'Fix the null pointer bug in the user authentication flow service', ['app/Services/Auth/UserService.php', 'tests/Unit/Auth/UserServiceTest.php']),
            $this->spec('d-2', 'Harden the certification gate against malformed edge case inputs to prevent bypass', ['app/Services/Gate/CertGate.php', 'tests/Unit/Gate/CertGateTest.php']),
            $this->spec('d-3', 'Wire the runtime adapter into the provider registry to complete integration plumbing', ['app/Services/Runtime/RuntimeAdapter.php', 'tests/Unit/Runtime/RuntimeAdapterTest.php']),
            $this->spec('weak-1', 'Implement a comprehensive validation service for incoming requests', ['app/Services/Validation/Service.php', 'tests/Unit/Validation/ServiceTest.php'],
                [], ['acceptance_criteria' => ['/opt/homebrew/bin/php artisan test ServiceTest exits 0']]),
        ];

        $result = $this->svc()->audit($specs);

        $this->assertSame('trim', $result['decision']);
        $this->assertArrayHasKey('weak-1', $result['weak_task_reasons']);
        $this->assertContains('weak_acceptance_criteria', $result['weak_task_reasons']['weak-1']);
    }

    public function test_all_specs_weak_yields_reject_decision(): void
    {
        $specs = [
            $this->spec('weak-1', 'Implement a comprehensive validation service for incoming requests', ['app/Services/Validation/Service.php', 'tests/Unit/Validation/ServiceTest.php'],
                [], ['acceptance_criteria' => ['/opt/homebrew/bin/php artisan test ServiceTest exits 0']]),
        ];

        $result = $this->svc()->audit($specs);

        $this->assertSame('reject', $result['decision']);
    }

    public function test_empty_batch_decision_is_accept(): void
    {
        $result = $this->svc()->audit([]);

        $this->assertSame('accept', $result['decision']);
    }
}
