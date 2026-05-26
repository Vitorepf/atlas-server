<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Patamar4;

use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\Governance\AtlasTrustBudgetService;
use App\Services\Ai\Patamar4\AtlasSubsystemAutoRebalanceService;
use Tests\TestCase;

class AtlasSubsystemAutoRebalanceServiceTest extends TestCase
{
    private string $log;

    private string $tbLog;

    private AtlasSubsystemAutoRebalanceService $svc;

    private AtlasTrustBudgetService $tb;

    protected function setUp(): void
    {
        parent::setUp();
        $u = uniqid('', true);
        $this->log = sys_get_temp_dir()."/atlas_rebalance_{$u}.jsonl";
        $this->tbLog = sys_get_temp_dir()."/atlas_rebalance_tb_{$u}.jsonl";

        $kernel = new AtlasConstitutionalKernelService;
        $kernel->setViolationsLogPathForTesting(sys_get_temp_dir()."/atlas_rebalance_kernel_{$u}.jsonl");

        $this->tb = new AtlasTrustBudgetService;
        $this->tb->setLogPathForTesting($this->tbLog);

        $this->svc = new AtlasSubsystemAutoRebalanceService($kernel, $this->tb);
        $this->svc->setLogPathForTesting($this->log);
    }

    protected function tearDown(): void
    {
        @unlink($this->log);
        @unlink($this->tbLog);
        parent::tearDown();
    }

    public function test_plan_returns_planned_envelope_shape(): void
    {
        $env = $this->svc->plan(AtlasSubsystemAutoRebalanceService::KIND_CACHE_COMPACT);
        $this->assertSame(AtlasSubsystemAutoRebalanceService::PLAN_SCHEMA, $env['schema_version']);
        $this->assertStringStartsWith('sha256:', $env['plan_hash']);
        $this->assertSame(AtlasSubsystemAutoRebalanceService::STATUS_PLANNED, $env['status']);
        $this->assertSame(AtlasTrustBudgetService::TIER_LOW, $env['tier']);
        $this->assertIsArray($env['diagnostics']);
        $this->assertIsArray($env['recommended_actions']);
    }

    public function test_plan_rejects_unknown_kind(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->plan('unknown_kind');
    }

    public function test_apply_requires_actor_and_reason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->apply(AtlasSubsystemAutoRebalanceService::KIND_CACHE_COMPACT, '', '');
    }

    public function test_apply_consumes_trust_budget_and_records_applied(): void
    {
        $env = $this->svc->apply(
            AtlasSubsystemAutoRebalanceService::KIND_CACHE_COMPACT,
            'operator',
            'compact cache after observed growth'
        );
        $this->assertSame(AtlasSubsystemAutoRebalanceService::APPLY_SCHEMA, $env['schema_version']);
        $this->assertSame(AtlasSubsystemAutoRebalanceService::STATUS_APPLIED, $env['status']);
        $this->assertNotNull($env['trust_budget_action_id']);
        $this->assertStringStartsWith('sha256:', $env['receipt_hash']);
    }

    public function test_apply_aemor_recompact_uses_high_tier(): void
    {
        $env = $this->svc->apply(
            AtlasSubsystemAutoRebalanceService::KIND_AEMOR_RECOMPACT,
            'operator',
            'aemor redundancy observed'
        );
        $this->assertSame(AtlasTrustBudgetService::TIER_HIGH, $env['tier']);
        $this->assertSame(AtlasSubsystemAutoRebalanceService::STATUS_APPLIED, $env['status']);
    }

    public function test_apply_records_receipt_even_when_budget_denied(): void
    {
        // critical autonomous = 0 cap; we pass autonomous_class with a critical-tier kind.
        // No canonical critical kind exists, so we test autonomous_agent on high (capped at 5)
        // by exhausting budget. Easier: use external_class on aemor (high tier external cap = 0).
        $env = $this->svc->apply(
            AtlasSubsystemAutoRebalanceService::KIND_AEMOR_RECOMPACT,
            'external_agent',
            'attempt by external',
            AtlasTrustBudgetService::CLASS_EXTERNAL
        );
        $this->assertSame(AtlasSubsystemAutoRebalanceService::STATUS_DENIED_BUDGET, $env['status']);
    }

    public function test_kind_constants_canon(): void
    {
        $this->assertSame('cache_compact', AtlasSubsystemAutoRebalanceService::KIND_CACHE_COMPACT);
        $this->assertSame('agrn_reindex_advice', AtlasSubsystemAutoRebalanceService::KIND_AGRN_REINDEX);
        $this->assertSame('aemor_recompact_advice', AtlasSubsystemAutoRebalanceService::KIND_AEMOR_RECOMPACT);
        $this->assertSame('mcp_pool_warmup_advice', AtlasSubsystemAutoRebalanceService::KIND_MCP_POOL_WARMUP);
    }

    public function test_status_constants_canon(): void
    {
        $this->assertSame('noop', AtlasSubsystemAutoRebalanceService::STATUS_NOOP);
        $this->assertSame('planned', AtlasSubsystemAutoRebalanceService::STATUS_PLANNED);
        $this->assertSame('applied', AtlasSubsystemAutoRebalanceService::STATUS_APPLIED);
        $this->assertSame('denied_budget', AtlasSubsystemAutoRebalanceService::STATUS_DENIED_BUDGET);
        $this->assertSame('denied_kernel', AtlasSubsystemAutoRebalanceService::STATUS_DENIED_KERNEL);
    }

    public function test_persisted_receipts_append_only(): void
    {
        $this->svc->plan(AtlasSubsystemAutoRebalanceService::KIND_CACHE_COMPACT);
        $this->svc->apply(AtlasSubsystemAutoRebalanceService::KIND_CACHE_COMPACT, 'operator', 'two');
        $this->assertCount(2, $this->svc->listReceipts());
    }

    public function test_diagnose_unwired_kind_reports_probe_status_unwired(): void
    {
        $env = $this->svc->plan(AtlasSubsystemAutoRebalanceService::KIND_CACHE_COMPACT);
        $this->assertSame('unwired', $env['diagnostics']['probe_status']);
        $this->assertNull($env['diagnostics']['observed']);
        $this->assertNotEmpty($env['diagnostics']['source']);
        $this->assertNotEmpty($env['diagnostics']['metric']);
    }

    public function test_set_probe_wires_real_diagnostic(): void
    {
        $this->svc->setProbe(
            AtlasSubsystemAutoRebalanceService::KIND_AEMOR_RECOMPACT,
            static fn (): array => [
                'observed' => 0.73,
                'source' => 'fake.aemor.test',
                'note' => 'synthetic redundancy',
            ]
        );
        $env = $this->svc->plan(AtlasSubsystemAutoRebalanceService::KIND_AEMOR_RECOMPACT);
        $this->assertSame('ok', $env['diagnostics']['probe_status']);
        $this->assertSame(0.73, $env['diagnostics']['observed']);
        $this->assertSame('fake.aemor.test', $env['diagnostics']['source']);
    }

    public function test_probe_throwing_records_probe_status_error(): void
    {
        $this->svc->setProbe(
            AtlasSubsystemAutoRebalanceService::KIND_MCP_POOL_WARMUP,
            static function (): array { throw new \RuntimeException('synthetic probe failure'); }
        );
        $env = $this->svc->plan(AtlasSubsystemAutoRebalanceService::KIND_MCP_POOL_WARMUP);
        $this->assertSame('error', $env['diagnostics']['probe_status']);
        $this->assertNull($env['diagnostics']['observed']);
        $this->assertStringContainsString('probe_error', $env['diagnostics']['note']);
    }

    public function test_set_probe_rejects_unknown_kind(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->setProbe('made_up_kind', static fn (): array => []);
    }

    public function test_set_probe_null_unwires(): void
    {
        $this->svc->setProbe(
            AtlasSubsystemAutoRebalanceService::KIND_CACHE_COMPACT,
            static fn (): array => ['observed' => 42, 'source' => 'wired']
        );
        $this->svc->setProbe(AtlasSubsystemAutoRebalanceService::KIND_CACHE_COMPACT, null);
        $env = $this->svc->plan(AtlasSubsystemAutoRebalanceService::KIND_CACHE_COMPACT);
        $this->assertSame('unwired', $env['diagnostics']['probe_status']);
    }

    public function test_last_receipt_returns_latest(): void
    {
        $this->svc->plan(AtlasSubsystemAutoRebalanceService::KIND_CACHE_COMPACT);
        $applied = $this->svc->apply(AtlasSubsystemAutoRebalanceService::KIND_MCP_POOL_WARMUP, 'operator', 'warm');
        $this->assertSame($applied['receipt_hash'], $this->svc->lastReceipt()['receipt_hash']);
    }
}
