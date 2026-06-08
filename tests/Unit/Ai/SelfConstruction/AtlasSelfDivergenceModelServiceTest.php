<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfDivergenceModelService;
use Tests\TestCase;

class AtlasSelfDivergenceModelServiceTest extends TestCase
{
    private string $log;

    private string $target;

    private AtlasSelfDivergenceModelService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $u = uniqid('', true);
        $this->log = sys_get_temp_dir()."/atlas_divergence_{$u}.jsonl";
        $this->target = sys_get_temp_dir()."/atlas_divergence_target_{$u}.json";
        $this->svc = $this->app->make(AtlasSelfDivergenceModelService::class);
        $this->svc->setLogPathForTesting($this->log);
        $this->svc->setTargetPathForTesting($this->target);
    }

    protected function tearDown(): void
    {
        @unlink($this->log);
        @unlink($this->target);
        parent::tearDown();
    }

    public function test_default_target_when_no_file_measures_real_divergence(): void
    {
        $env = $this->svc->measure();
        $this->assertSame('atlas.self_divergence.measurement.v1', $env['schema_version']);
        // No target file -> the model assumes an all-ready TARGET. The CURRENT scorecard
        // state is now RESOLVED from real evidence (doc = FQN-bound ownership, pipeline =
        // fresh green-run receipt) instead of a hardcoded 10/10, so the honest current is
        // below all-ready and the model surfaces the real gap as divergences. (Previously
        // this asserted 0 — that only held because doc/pipeline were self-declared ready.)
        $this->assertFalse($env['target_state_present']);
        $this->assertGreaterThan(
            0,
            $env['divergence_count'],
            'With an all-ready target and an evidence-resolved current state, real divergences must appear — a 0 here would mean the scorecard over-claim crept back.',
        );
        // The divergences are real downgrades (current dimension below the ready target),
        // not phantom missing/extra rows — every canonical subsystem is still present.
        $kinds = array_map(static fn ($d) => $d['kind'], $env['divergences']);
        $this->assertContains(AtlasSelfDivergenceModelService::DIVERGENCE_DOWNGRADED, $kinds);
    }

    public function test_missing_subsystem_in_current_emits_divergence(): void
    {
        $target = [
            'subsystems' => [
                ['acronym' => 'FAKE_NEW_SUBSYSTEM', 'code' => 'ready', 'doc' => 'ready', 'pipeline' => 'ready'],
            ],
        ];
        file_put_contents($this->target, json_encode($target));
        $env = $this->svc->measure();
        $this->assertTrue($env['target_state_present']);
        $this->assertGreaterThanOrEqual(1, $env['divergence_count']);
        $kinds = array_map(fn ($d) => $d['kind'], $env['divergences']);
        $this->assertContains(AtlasSelfDivergenceModelService::DIVERGENCE_MISSING, $kinds);
    }

    public function test_downgraded_subsystem_detected(): void
    {
        // Target says G0 should be ready/ready/ready, current already is. But if we set
        // target to demand something the current can't deliver, divergence appears.
        $target = [
            'subsystems' => [
                ['acronym' => 'G0', 'code' => 'ready', 'doc' => 'ready', 'pipeline' => 'ready'],
            ],
        ];
        file_put_contents($this->target, json_encode($target));
        $env = $this->svc->measure();
        // G0 is currently ready/ready/ready in scorecard so no downgrade.
        $this->assertSame('atlas.self_divergence.measurement.v1', $env['schema_version']);
    }

    public function test_envelope_carries_kernel_and_scorecard_hashes(): void
    {
        $env = $this->svc->measure();
        $this->assertStringStartsWith('sha256:', $env['kernel_hash']);
        $this->assertStringStartsWith('sha256:', (string) $env['scorecard_hash']);
        $this->assertStringStartsWith('sha256:', $env['divergence_hash']);
    }

    public function test_jsonl_persists_measurements(): void
    {
        $this->svc->measure();
        $this->svc->measure();
        $this->assertCount(2, $this->svc->listMeasurements());
    }

    public function test_claim_policy_provider_safe(): void
    {
        $cp = $this->svc->claimPolicy();
        $this->assertFalse($cp['benchmark_claim_allowed']);
        $this->assertTrue($cp['local_first_only']);
    }

    public function test_divergences_by_kind_breakdown(): void
    {
        $env = $this->svc->measure();
        $this->assertArrayHasKey('missing_subsystem', $env['divergences_by_kind']);
        $this->assertArrayHasKey('downgraded_subsystem', $env['divergences_by_kind']);
        $this->assertArrayHasKey('drift_subsystem', $env['divergences_by_kind']);
    }
}
