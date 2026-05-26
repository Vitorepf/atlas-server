<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionSubsystemBuilderService;
use Tests\TestCase;

class AtlasPatamar4SelfConstructF4GapsCommandTest extends TestCase
{
    private string $proposalsLog;

    private string $approvalsLog;

    protected function setUp(): void
    {
        parent::setUp();
        $u = uniqid('', true);
        $this->proposalsLog = sys_get_temp_dir()."/atlas_a6_proposals_{$u}.jsonl";
        $this->approvalsLog = sys_get_temp_dir()."/atlas_a6_approvals_{$u}.jsonl";
        $svc = $this->app->make(AtlasSelfConstructionSubsystemBuilderService::class);
        $svc->setProposalsLogPathForTesting($this->proposalsLog);
        $svc->setApprovalsLogPathForTesting($this->approvalsLog);
        $this->app->instance(AtlasSelfConstructionSubsystemBuilderService::class, $svc);
    }

    protected function tearDown(): void
    {
        @unlink($this->proposalsLog);
        @unlink($this->approvalsLog);
        parent::tearDown();
    }

    public function test_emits_two_proposals_for_f4_gaps(): void
    {
        $exit = \Illuminate\Support\Facades\Artisan::call('atlas:patamar4:self-construct-f4-gaps', ['--json' => true]);
        $stdout = \Illuminate\Support\Facades\Artisan::output();
        $this->assertSame(0, $exit);
        $decoded = json_decode($stdout, true);
        $this->assertSame(2, $decoded['gap_count']);
        $this->assertSame(2, $decoded['proposal_count']);
        $this->assertSame('ACPS', $decoded['proposals'][0]['acronym']);
        $this->assertSame('AGRN-ISF', $decoded['proposals'][1]['acronym']);
        $this->assertStringStartsWith('prop_', $decoded['proposals'][0]['proposal_id']);
        $this->assertStringStartsWith('sha256:', $decoded['proposals'][0]['proposal_hash']);
    }

    public function test_proposals_persist_in_jsonl(): void
    {
        $this->artisan('atlas:patamar4:self-construct-f4-gaps', ['--json' => true])->run();
        $contents = file_get_contents($this->proposalsLog);
        $this->assertNotFalse($contents);
        $this->assertStringContainsString('ACPS', $contents);
        $this->assertStringContainsString('AGRN-ISF', $contents);
    }
}
