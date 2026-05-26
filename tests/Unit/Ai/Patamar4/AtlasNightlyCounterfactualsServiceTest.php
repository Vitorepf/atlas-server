<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Patamar4;

use App\Services\Ai\Gateway\AtlasGatewayPreflightService;
use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\Patamar4\AtlasNightlyCounterfactualsService;
use App\Services\Ai\Teos\AtlasTeosI3CounterfactualService;
use App\Services\Ai\Teos\AtlasTeosI4CounterfactualTreeService;
use Tests\TestCase;

class AtlasNightlyCounterfactualsServiceTest extends TestCase
{
    private string $log;

    private string $preflightLog;

    private AtlasNightlyCounterfactualsService $svc;

    private AtlasGatewayPreflightService $preflight;

    protected function setUp(): void
    {
        parent::setUp();
        $u = uniqid('', true);
        $this->log = sys_get_temp_dir()."/atlas_nightly_{$u}.jsonl";
        $this->preflightLog = sys_get_temp_dir()."/atlas_nightly_preflight_{$u}.jsonl";

        $kernel = new AtlasConstitutionalKernelService;
        $kernel->setViolationsLogPathForTesting(sys_get_temp_dir()."/atlas_nightly_kernel_{$u}.jsonl");
        $admission = new AtlasAutonomyAdmissionService($kernel);
        $admission->setTicketsLogPathForTesting(sys_get_temp_dir()."/atlas_nightly_admission_{$u}.jsonl");
        $teosI3 = new AtlasTeosI3CounterfactualService($kernel, $admission);
        $teosI3->setBranchesLogPathForTesting(sys_get_temp_dir()."/atlas_nightly_t3_{$u}.jsonl");
        $teosI4 = new AtlasTeosI4CounterfactualTreeService($teosI3, $kernel, $admission);
        $teosI4->setTreesLogPathForTesting(sys_get_temp_dir()."/atlas_nightly_t4_{$u}.jsonl");

        $this->preflight = new AtlasGatewayPreflightService($teosI4, $kernel);
        $this->preflight->setLogPathForTesting($this->preflightLog);

        $this->svc = new AtlasNightlyCounterfactualsService($this->preflight, $teosI4, $kernel);
        $this->svc->setLogPathForTesting($this->log);
    }

    protected function tearDown(): void
    {
        @unlink($this->log);
        @unlink($this->preflightLog);
        parent::tearDown();
    }

    public function test_run_returns_no_decisions_when_preflight_empty(): void
    {
        $env = $this->svc->run('test_actor');
        $this->assertSame(AtlasNightlyCounterfactualsService::STATUS_NO_DECISIONS, $env['status']);
        $this->assertSame(0, $env['recommendation_count']);
    }

    public function test_envelope_schema_and_hash(): void
    {
        $env = $this->svc->run('test_actor');
        $this->assertSame(AtlasNightlyCounterfactualsService::SWEEP_SCHEMA, $env['schema_version']);
        $this->assertStringStartsWith('sha256:', $env['sweep_hash']);
        $this->assertArrayHasKey('kernel_hash', $env);
    }

    public function test_run_persists_append_only(): void
    {
        $this->svc->run('a');
        $this->svc->run('b');
        $this->assertCount(2, $this->svc->listSweeps());
    }

    public function test_last_sweep_returns_latest_actor(): void
    {
        $this->svc->run('first');
        $this->svc->run('second');
        $this->assertSame('second', $this->svc->lastSweep()['actor']);
    }

    public function test_run_uses_preflight_envelopes_as_decisions(): void
    {
        // Generate a major preflight envelope first.
        $this->preflight->preflight(
            'constrói o ecommerce inteiro dos sapatos com checkout e marketing',
            'claude_cli',
            ['privacy_class' => 'normal']
        );
        $env = $this->svc->run('test_actor');
        $this->assertSame(AtlasNightlyCounterfactualsService::STATUS_OK, $env['status']);
        $this->assertGreaterThan(0, $env['recommendation_count']);
        $this->assertSame(
            AtlasNightlyCounterfactualsService::RECOMMENDATION_SCHEMA,
            $env['recommendations'][0]['schema_version']
        );
    }

    public function test_recommendation_carries_tree_id_and_projected_improvement(): void
    {
        $this->preflight->preflight(
            'constrói o ecommerce inteiro dos sapatos com checkout e marketing',
            'claude_cli',
            ['privacy_class' => 'normal']
        );
        $env = $this->svc->run('test_actor');
        $rec = $env['recommendations'][0];
        $this->assertArrayHasKey('tree_id', $rec);
        $this->assertArrayHasKey('projected_improvement', $rec);
        $this->assertArrayHasKey('best_path', $rec);
    }

    public function test_inbox_returns_empty_when_no_sweep(): void
    {
        $this->assertSame([], $this->svc->inbox(10));
    }

    public function test_inbox_orders_by_projected_improvement_desc(): void
    {
        $this->preflight->preflight(
            'first long input that surely qualifies as major decision body',
            'claude_cli',
            ['privacy_class' => 'normal']
        );
        $this->preflight->preflight(
            'second different long input that should also qualify ahead',
            'codex_cli',
            ['privacy_class' => 'normal']
        );
        $this->svc->run('test_actor');
        $inbox = $this->svc->inbox(10);
        $this->assertNotEmpty($inbox);
        // Ordered desc — first.projected_improvement >= second
        for ($i = 1; $i < count($inbox); $i++) {
            $this->assertGreaterThanOrEqual(
                (float) $inbox[$i]['projected_improvement'],
                (float) $inbox[$i - 1]['projected_improvement']
            );
        }
    }

    public function test_status_constants_canon(): void
    {
        $this->assertSame('ok', AtlasNightlyCounterfactualsService::STATUS_OK);
        $this->assertSame('no_decisions_found', AtlasNightlyCounterfactualsService::STATUS_NO_DECISIONS);
    }
}
