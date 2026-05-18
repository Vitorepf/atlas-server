<?php

namespace Tests\Feature\Ai\Cyber;

use App\Services\Ai\Cyber\AuthorizedBugBountyIntakeService;
use App\Services\Ai\Cyber\CyberDomainException;
use App\Services\Ai\Cyber\CyberEngagementIntakeService;
use Tests\Concerns\CreatesCyberRuntimeTables;
use Tests\TestCase;

class CyberDomainBugBountyIntakeTest extends TestCase
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

    private function authorizedEngagement()
    {
        return app(CyberEngagementIntakeService::class)->intake([
            'title' => 'Authorized bounty engagement',
            'engagement_kind' => CyberEngagementIntakeService::KIND_AUTHORIZED_BUG_BOUNTY,
            'requester' => 'operator',
            'targets' => ['external-program'],
            'authorization_present' => true,
            'authorization' => ['doc_url' => 'internal://auth', 'authorized_by' => 'operator'],
        ]);
    }

    public function test_bounty_intake_blocked_when_engagement_not_authorized(): void
    {
        $engagement = app(CyberEngagementIntakeService::class)->intake([
            'title' => 'Non-authorized engagement',
            'engagement_kind' => CyberEngagementIntakeService::KIND_DEFENSIVE_REVIEW,
            'requester' => 'operator',
            'targets' => ['internal'],
            'authorization_present' => false,
        ]);
        $this->expectException(CyberDomainException::class);
        app(AuthorizedBugBountyIntakeService::class)->intake($engagement, [
            'program' => 'Program X',
            'authorization_present' => true,
            'authorization_doc' => ['url' => 'x'],
            'scope_parsed' => true,
            'roe_documented' => true,
            'legal_gate_passed' => true,
            'privacy_gate_passed' => true,
        ]);
    }

    public function test_bounty_intake_blocked_without_all_gates(): void
    {
        $engagement = $this->authorizedEngagement();
        $intake = app(AuthorizedBugBountyIntakeService::class)->intake($engagement, [
            'program' => 'Program X',
            'authorization_present' => true,
            'authorization_doc' => ['url' => 'x', 'contacts' => ['operator']],
            'scope_parsed' => true,
            'roe_documented' => false,
            'legal_gate_passed' => false,
            'privacy_gate_passed' => true,
        ]);
        $this->assertSame(AuthorizedBugBountyIntakeService::STATUS_BLOCKED, $intake->status);
        $kinds = collect($intake->blockers)->pluck('kind')->all();
        $this->assertContains('roe_not_documented', $kinds);
        $this->assertContains('legal_gate_pending', $kinds);
    }

    public function test_bounty_intake_authorized_when_all_gates_passed(): void
    {
        $engagement = $this->authorizedEngagement();
        $intake = app(AuthorizedBugBountyIntakeService::class)->intake($engagement, [
            'program' => 'Program X',
            'authorization_present' => true,
            'authorization_doc' => ['url' => 'x', 'contacts' => ['operator']],
            'scope_parsed' => true,
            'roe_documented' => true,
            'legal_gate_passed' => true,
            'privacy_gate_passed' => true,
        ]);
        $this->assertSame(AuthorizedBugBountyIntakeService::STATUS_AUTHORIZED, $intake->status);
        $this->assertNull($intake->blockers);
    }

    public function test_bounty_intake_rejects_missing_authorization_doc(): void
    {
        $engagement = $this->authorizedEngagement();
        $this->expectException(CyberDomainException::class);
        app(AuthorizedBugBountyIntakeService::class)->intake($engagement, [
            'program' => 'Program X',
            'authorization_present' => false,
            'authorization_doc' => null,
            'scope_parsed' => true,
            'roe_documented' => true,
            'legal_gate_passed' => true,
            'privacy_gate_passed' => true,
        ]);
    }
}
