<?php

namespace Tests\Feature\Ai\Cyber;

use App\Services\Ai\Cyber\CyberDomainException;
use App\Services\Ai\Cyber\CyberRuntimeService;
use Tests\Concerns\CreatesCyberRuntimeTables;
use Tests\TestCase;

class CyberDomainOffensiveGuardsTest extends TestCase
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

    public function test_runtime_refuses_offensive_verbs(): void
    {
        /** @var CyberRuntimeService $svc */
        $svc = app(CyberRuntimeService::class);
        $this->assertTrue($svc->isForbiddenOffensive('execute_exploit'));
        $this->assertTrue($svc->isForbiddenOffensive('execute_scan'));
        $this->assertTrue($svc->isForbiddenOffensive('collect_credentials'));
        $this->assertTrue($svc->isForbiddenOffensive('disable_control'));
        $this->assertTrue($svc->isForbiddenOffensive('supply_chain_publish'));
        $this->assertTrue($svc->isForbiddenOffensive('register_typosquat'));
        $this->assertFalse($svc->isForbiddenOffensive('appsec_review'));
    }

    public function test_refuse_offensive_throws_with_clear_message(): void
    {
        /** @var CyberRuntimeService $svc */
        $svc = app(CyberRuntimeService::class);
        try {
            $svc->refuseOffensive('execute_exploit');
            $this->fail('expected CyberDomainException');
        } catch (CyberDomainException $e) {
            $this->assertStringContainsString('forbidden offensive action', $e->getMessage());
            $this->assertStringContainsString('execute_exploit', $e->getMessage());
        }
    }
}
