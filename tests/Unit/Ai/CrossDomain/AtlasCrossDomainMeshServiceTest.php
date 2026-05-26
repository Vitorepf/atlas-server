<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\CrossDomain;

use App\Services\Ai\CrossDomain\AtlasCrossDomainMeshService;
use Tests\TestCase;

class AtlasCrossDomainMeshServiceTest extends TestCase
{
    private string $tmpDir;

    private AtlasCrossDomainMeshService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/atlas_xdomain_'.uniqid('', true);
        @mkdir($this->tmpDir, 0775, true);
        $this->svc = new AtlasCrossDomainMeshService;
        $this->svc->setRequestsLogPathForTesting($this->tmpDir.'/requests.jsonl');
        $this->svc->setDecisionsLogPathForTesting($this->tmpDir.'/decisions.jsonl');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    public function test_15_canonical_domains(): void
    {
        $this->assertCount(15, AtlasCrossDomainMeshService::DOMAINS);
        foreach (['engineering', 'cyber', 'finance', 'marketing', 'personal'] as $d) {
            $this->assertContains($d, AtlasCrossDomainMeshService::DOMAINS);
        }
    }

    public function test_same_domain_is_no_op_approved(): void
    {
        $d = $this->svc->evaluate('engineering', 'engineering', 'normal');
        $this->assertTrue($d['approved']);
        $this->assertContains('same_domain_no_op', $d['reason']);
        $this->assertFalse($d['bridge_applied']);
    }

    public function test_engineering_to_ops_normal_is_approved(): void
    {
        $d = $this->svc->evaluate('engineering', 'ops', 'normal');
        $this->assertTrue($d['approved']);
        $this->assertTrue($d['bridge_applied']);
    }

    public function test_sensitive_payload_never_reaches_marketing(): void
    {
        foreach (['sensitive', 'secret', 'cyber'] as $privacy) {
            $d = $this->svc->evaluate('finance', 'marketing', $privacy);
            $this->assertFalse($d['approved'], "privacy={$privacy} must be vetoed to marketing");
            $this->assertContains('privacy_class_blocks_consumer_audience_target', $d['reason']);
        }
    }

    public function test_cyber_outbound_restricted_to_governance_legal_public_only(): void
    {
        $this->assertFalse($this->svc->evaluate('cyber', 'finance', 'normal')['approved']);
        $this->assertFalse($this->svc->evaluate('cyber', 'engineering', 'public')['approved']);
        $this->assertTrue($this->svc->evaluate('cyber', 'governance', 'public')['approved']);
        $this->assertTrue($this->svc->evaluate('cyber', 'legal', 'public')['approved']);
        $this->assertFalse($this->svc->evaluate('cyber', 'governance', 'secret')['approved']);
    }

    public function test_secret_crosses_only_within_trusted_cluster(): void
    {
        $this->assertTrue($this->svc->evaluate('finance', 'trading', 'secret')['approved']);
        $this->assertTrue($this->svc->evaluate('engineering', 'infra', 'secret')['approved']);
        $this->assertFalse($this->svc->evaluate('finance', 'engineering', 'secret')['approved']);
    }

    public function test_personal_health_outbound_restricted(): void
    {
        $this->assertFalse($this->svc->evaluate('personal', 'engineering', 'normal')['approved']);
        $this->assertFalse($this->svc->evaluate('health', 'marketing', 'normal')['approved']);
        $this->assertTrue($this->svc->evaluate('personal', 'engineering', 'public')['approved']);
    }

    public function test_unknown_domain_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->evaluate('martian', 'finance', 'normal');
    }

    public function test_unknown_privacy_class_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->evaluate('finance', 'trading', 'top_secret_alien');
    }

    public function test_bridge_persists_request_and_decision(): void
    {
        $r = $this->svc->bridge([
            'from_domain' => 'engineering',
            'to_domain' => 'ops',
            'privacy_class' => 'normal',
            'rationale' => 'test',
        ]);
        $this->assertTrue($r['approved']);
        $this->assertCount(1, $this->svc->listRequests());
        $this->assertCount(1, $this->svc->listDecisions());
    }

    public function test_topology_envelope_shape(): void
    {
        $t = $this->svc->topology();
        $this->assertSame(AtlasCrossDomainMeshService::TOPOLOGY_SCHEMA, $t['schema_version']);
        $this->assertCount(15, $t['domains']);
        $this->assertNotEmpty($t['edges_allowed']);
        $this->assertStringStartsWith('sha256:', $t['topology_hash']);
    }

    public function test_decision_hash_is_deterministic_for_same_triple(): void
    {
        $a = $this->svc->evaluate('engineering', 'ops', 'normal');
        $b = $this->svc->evaluate('engineering', 'ops', 'normal');
        $this->assertSame($a['decision_hash'], $b['decision_hash']);
    }

    public function test_topology_hash_is_deterministic(): void
    {
        $a = $this->svc->topology()['topology_hash'];
        $b = $this->svc->topology()['topology_hash'];
        $this->assertSame($a, $b);
    }
}
