<?php

namespace Tests\Feature\Ai\Cyber;

use App\Services\Ai\Cyber\CyberDomainException;
use App\Services\Ai\Cyber\CyberEngagementIntakeService;
use Tests\Concerns\CreatesCyberRuntimeTables;
use Tests\TestCase;

class CyberDomainEngagementTest extends TestCase
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

    public function test_defensive_engagement_starts_in_intake_when_authorization_absent(): void
    {
        /** @var CyberEngagementIntakeService $svc */
        $svc = app(CyberEngagementIntakeService::class);
        $engagement = $svc->intake([
            'title' => 'Defensive review of internal stack',
            'engagement_kind' => CyberEngagementIntakeService::KIND_DEFENSIVE_REVIEW,
            'requester' => 'operator',
            'targets' => ['internal-stack'],
        ]);
        $this->assertSame(CyberEngagementIntakeService::STATUS_INTAKE, $engagement->status);
        $this->assertFalse($engagement->authorization_present);
        $this->assertIsArray($engagement->blockers);
        $this->assertSame('authorization_pending', $engagement->blockers[0]['kind']);
    }

    public function test_authorized_engagement_starts_authorized(): void
    {
        /** @var CyberEngagementIntakeService $svc */
        $svc = app(CyberEngagementIntakeService::class);
        $engagement = $svc->intake([
            'title' => 'AppSec review with internal authorization',
            'engagement_kind' => CyberEngagementIntakeService::KIND_APPSEC_REVIEW,
            'requester' => 'operator',
            'targets' => ['internal-repo'],
            'authorization_present' => true,
            'authorization' => ['doc_url' => 'internal://auth', 'authorized_by' => 'operator'],
        ]);
        $this->assertSame(CyberEngagementIntakeService::STATUS_AUTHORIZED, $engagement->status);
        $this->assertTrue($engagement->authorization_present);
        $this->assertNull($engagement->blockers);
    }

    public function test_authorized_bug_bounty_engagement_requires_authorization_present(): void
    {
        /** @var CyberEngagementIntakeService $svc */
        $svc = app(CyberEngagementIntakeService::class);
        $this->expectException(CyberDomainException::class);
        $svc->intake([
            'title' => 'External bounty engagement without authorization',
            'engagement_kind' => CyberEngagementIntakeService::KIND_AUTHORIZED_BUG_BOUNTY,
            'requester' => 'operator',
            'targets' => ['external-program'],
            'authorization_present' => false,
        ]);
    }

    public function test_engagement_rejects_missing_targets(): void
    {
        /** @var CyberEngagementIntakeService $svc */
        $svc = app(CyberEngagementIntakeService::class);
        $this->expectException(CyberDomainException::class);
        $svc->intake([
            'title' => 'No targets',
            'engagement_kind' => CyberEngagementIntakeService::KIND_DEFENSIVE_REVIEW,
            'requester' => 'operator',
            'targets' => [],
        ]);
    }
}
