<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Gateway;

use App\Services\Ai\Gateway\AtlasGatewayPreflightService;
use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\Teos\AtlasTeosI3CounterfactualService;
use App\Services\Ai\Teos\AtlasTeosI4CounterfactualTreeService;
use Tests\TestCase;

class AtlasGatewayPreflightServiceTest extends TestCase
{
    private string $log;

    private string $kernelLog;

    private string $admissionLog;

    private string $teosI3Log;

    private string $teosI4Log;

    private AtlasGatewayPreflightService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $u = uniqid('', true);
        $this->log = sys_get_temp_dir()."/atlas_preflight_{$u}.jsonl";
        $this->kernelLog = sys_get_temp_dir()."/atlas_preflight_kernel_{$u}.jsonl";
        $this->admissionLog = sys_get_temp_dir()."/atlas_preflight_admission_{$u}.jsonl";
        $this->teosI3Log = sys_get_temp_dir()."/atlas_preflight_teos3_{$u}.jsonl";
        $this->teosI4Log = sys_get_temp_dir()."/atlas_preflight_teos4_{$u}.jsonl";

        $kernel = new AtlasConstitutionalKernelService;
        $kernel->setViolationsLogPathForTesting($this->kernelLog);

        $admission = new AtlasAutonomyAdmissionService($kernel);
        $admission->setTicketsLogPathForTesting($this->admissionLog);

        $teosI3 = new AtlasTeosI3CounterfactualService($kernel, $admission);
        $teosI3->setBranchesLogPathForTesting($this->teosI3Log);

        $teosI4 = new AtlasTeosI4CounterfactualTreeService($teosI3, $kernel, $admission);
        $teosI4->setTreesLogPathForTesting($this->teosI4Log);

        $this->svc = new AtlasGatewayPreflightService($teosI4, $kernel);
        $this->svc->setLogPathForTesting($this->log);
    }

    protected function tearDown(): void
    {
        @unlink($this->log);
        @unlink($this->kernelLog);
        @unlink($this->admissionLog);
        @unlink($this->teosI3Log);
        @unlink($this->teosI4Log);
        parent::tearDown();
    }

    public function test_short_safe_input_is_not_major(): void
    {
        $this->assertFalse($this->svc->isMajor('hi', 'claude_cli', [
            'privacy_class' => 'public',
            'requested_autonomy' => 'execute_with_approval',
        ]));
    }

    public function test_long_input_is_major(): void
    {
        $longInput = 'constrói o ecommerce dos sapatos com checkout PIX e marketing inteiro';
        $this->assertTrue($this->svc->isMajor($longInput, 'claude_cli', []));
    }

    public function test_sensitive_privacy_is_major(): void
    {
        $this->assertTrue($this->svc->isMajor('check log', 'claude_cli', [
            'privacy_class' => 'sensitive',
        ]));
    }

    public function test_autonomous_is_major(): void
    {
        $this->assertTrue($this->svc->isMajor('ok', 'claude_cli', [
            'requested_autonomy' => 'autonomous',
        ]));
    }

    public function test_force_preflight_is_major(): void
    {
        $this->assertTrue($this->svc->isMajor('ok', 'claude_cli', [
            'force_preflight' => true,
        ]));
    }

    public function test_non_major_returns_not_projected(): void
    {
        $env = $this->svc->preflight('hi', 'claude_cli', [
            'privacy_class' => 'public',
        ]);
        $this->assertSame(
            AtlasGatewayPreflightService::VERDICT_NOT_PROJECTED,
            $env['verdict']
        );
        $this->assertNull($env['tree_id']);
    }

    public function test_major_expands_tree_and_records_envelope(): void
    {
        $env = $this->svc->preflight(
            'constrói o ecommerce inteiro dos sapatos com checkout e marketing',
            'claude_cli',
            ['privacy_class' => 'normal']
        );
        $this->assertSame(AtlasGatewayPreflightService::ENVELOPE_SCHEMA, $env['schema_version']);
        $this->assertStringStartsWith('sha256:', $env['envelope_hash']);
        $this->assertStringStartsWith('sha256:', $env['input_hash']);
        $this->assertContains(
            $env['verdict'],
            [
                AtlasGatewayPreflightService::VERDICT_PROJECTED_OK,
                AtlasGatewayPreflightService::VERDICT_PROJECTED_LOW_GAIN,
                AtlasGatewayPreflightService::VERDICT_PROJECTED_KERNEL_BLOCK,
            ]
        );
    }

    public function test_envelope_persists_append_only(): void
    {
        $this->svc->preflight('hi', 'claude_cli', ['privacy_class' => 'public']);
        $this->svc->preflight('hi', 'claude_cli', ['privacy_class' => 'public']);
        $this->assertCount(2, $this->svc->listEnvelopes());
    }

    public function test_last_envelope_returns_latest(): void
    {
        $this->svc->preflight('a', 'claude_cli', ['privacy_class' => 'public']);
        $second = $this->svc->preflight('b', 'codex_cli', ['privacy_class' => 'public']);
        $this->assertSame($second['envelope_hash'], $this->svc->lastEnvelope()['envelope_hash']);
    }

    public function test_verdict_constants_canon(): void
    {
        $this->assertSame('not_projected', AtlasGatewayPreflightService::VERDICT_NOT_PROJECTED);
        $this->assertSame('projected_ok', AtlasGatewayPreflightService::VERDICT_PROJECTED_OK);
        $this->assertSame('projected_low_gain', AtlasGatewayPreflightService::VERDICT_PROJECTED_LOW_GAIN);
        $this->assertSame('projected_kernel_block', AtlasGatewayPreflightService::VERDICT_PROJECTED_KERNEL_BLOCK);
    }

    public function test_input_hash_is_deterministic(): void
    {
        $env1 = $this->svc->preflight('same input here longer than twelve words alpha beta gamma delta', 'claude_cli', ['privacy_class' => 'public']);
        $env2 = $this->svc->preflight('same input here longer than twelve words alpha beta gamma delta', 'claude_cli', ['privacy_class' => 'public']);
        $this->assertSame($env1['input_hash'], $env2['input_hash']);
    }
}
