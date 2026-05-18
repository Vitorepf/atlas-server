<?php

namespace Tests\Feature\Ai\Cyber;

use App\Services\Ai\Cyber\CyberDomainException;
use App\Services\Ai\Cyber\CyberEngagementIntakeService;
use App\Services\Ai\Cyber\CyberEvidenceChainService;
use Tests\Concerns\CreatesCyberRuntimeTables;
use Tests\TestCase;

class CyberDomainEvidenceChainTest extends TestCase
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

    private function engagement()
    {
        return app(CyberEngagementIntakeService::class)->intake([
            'title' => 'Engagement for chain test',
            'engagement_kind' => CyberEngagementIntakeService::KIND_DEFENSIVE_REVIEW,
            'requester' => 'operator',
            'targets' => ['internal'],
            'authorization_present' => true,
            'authorization' => ['doc_url' => 'internal://auth'],
        ]);
    }

    public function test_evidence_chain_links_entries_and_verifies_integrity(): void
    {
        $engagement = $this->engagement();
        $svc = app(CyberEvidenceChainService::class);

        $first = $svc->append($engagement, [
            'entry_kind' => CyberEvidenceChainService::KIND_AUTHORIZATION,
            'actor' => 'operator',
            'payload' => ['doc' => 'internal://auth'],
        ]);
        $second = $svc->append($engagement, [
            'entry_kind' => CyberEvidenceChainService::KIND_SCOPE,
            'actor' => 'operator',
            'payload' => ['scope_id' => 'scope-1'],
        ]);

        $this->assertNull($first->previous_hash);
        $this->assertSame($first->entry_hash, $second->previous_hash);

        $verification = $svc->verify($engagement);
        $this->assertTrue($verification['integrity_ok']);
        $this->assertSame(2, $verification['entry_count']);
        $this->assertSame([], $verification['broken_links']);
    }

    public function test_evidence_chain_rejects_invalid_kind(): void
    {
        $engagement = $this->engagement();
        $this->expectException(CyberDomainException::class);
        app(CyberEvidenceChainService::class)->append($engagement, [
            'entry_kind' => 'random-kind',
            'actor' => 'operator',
            'payload' => ['x' => 1],
        ]);
    }

    public function test_evidence_chain_rejects_missing_actor(): void
    {
        $engagement = $this->engagement();
        $this->expectException(CyberDomainException::class);
        app(CyberEvidenceChainService::class)->append($engagement, [
            'entry_kind' => CyberEvidenceChainService::KIND_AUTHORIZATION,
            'actor' => '',
            'payload' => ['x' => 1],
        ]);
    }
}
