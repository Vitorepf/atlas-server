<?php

namespace Tests\Feature\Ai\Cyber;

use App\Services\Ai\Cyber\CyberDomainException;
use App\Services\Ai\Cyber\CyberEngagementIntakeService;
use App\Services\Ai\Cyber\CyberScopeRulesOfEngagementService;
use Tests\Concerns\CreatesCyberRuntimeTables;
use Tests\TestCase;

class CyberDomainScopeRoeTest extends TestCase
{
    use CreatesCyberRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCyberRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropCyberRuntimeTables();
        parent::tearDown();
    }

    public function test_scope_rules_persist_with_required_fields(): void
    {
        $engagement = app(CyberEngagementIntakeService::class)->intake([
            'title' => 'Engagement for scope test',
            'engagement_kind' => CyberEngagementIntakeService::KIND_DEFENSIVE_REVIEW,
            'requester' => 'operator',
            'targets' => ['internal'],
            'authorization_present' => true,
            'authorization' => ['doc_url' => 'internal://auth'],
        ]);

        $rules = app(CyberScopeRulesOfEngagementService::class)->define($engagement, [
            'in_scope_targets' => ['internal'],
            'out_of_scope_targets' => ['external'],
            'allowed_techniques' => ['static-review'],
            'forbidden_techniques' => ['exploit', 'scan'],
            'escalation_contacts' => [['name' => 'operator', 'channel' => 'cli']],
        ]);
        $this->assertNotEmpty($rules->rules_hash);
        $this->assertSame($engagement->id, $rules->engagement_id);
    }

    public function test_scope_rules_reject_missing_allowed_techniques(): void
    {
        $engagement = app(CyberEngagementIntakeService::class)->intake([
            'title' => 'Engagement for scope test',
            'engagement_kind' => CyberEngagementIntakeService::KIND_DEFENSIVE_REVIEW,
            'requester' => 'operator',
            'targets' => ['internal'],
            'authorization_present' => true,
            'authorization' => ['doc_url' => 'internal://auth'],
        ]);

        $this->expectException(CyberDomainException::class);
        app(CyberScopeRulesOfEngagementService::class)->define($engagement, [
            'in_scope_targets' => ['internal'],
            'out_of_scope_targets' => ['external'],
            'allowed_techniques' => [],
            'forbidden_techniques' => ['exploit'],
            'escalation_contacts' => [['name' => 'operator']],
        ]);
    }

    public function test_scope_rules_reject_missing_forbidden_techniques(): void
    {
        $engagement = app(CyberEngagementIntakeService::class)->intake([
            'title' => 'Engagement for scope test',
            'engagement_kind' => CyberEngagementIntakeService::KIND_DEFENSIVE_REVIEW,
            'requester' => 'operator',
            'targets' => ['internal'],
            'authorization_present' => true,
            'authorization' => ['doc_url' => 'internal://auth'],
        ]);

        $this->expectException(CyberDomainException::class);
        app(CyberScopeRulesOfEngagementService::class)->define($engagement, [
            'in_scope_targets' => ['internal'],
            'out_of_scope_targets' => ['external'],
            'allowed_techniques' => ['static-review'],
            'forbidden_techniques' => [],
            'escalation_contacts' => [['name' => 'operator']],
        ]);
    }
}
