<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AiWorkerSupport;

use App\Services\Ai\AiWorkerSupport\AiWorkerGitShortstatSupport;
use App\Services\Ai\AiWorkerSupport\AiWorkerJobPredicatesSupport;
use App\Services\Ai\AiWorkerSupport\AiWorkerPlanRevisionsSupport;
use App\Services\Ai\AiWorkerSupport\AiWorkerTimeDiffSupport;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AiWorkerJobPredicatesSupportTest extends TestCase
{
    #[Test]
    public function provider_was_called_excludes_receipt_and_policy_codes(): void
    {
        $this->assertFalse(AiWorkerJobPredicatesSupport::providerWasCalled('decision_receipt_dry_run'));
        $this->assertFalse(AiWorkerJobPredicatesSupport::providerWasCalled('permission_denied'));
        $this->assertTrue(AiWorkerJobPredicatesSupport::providerWasCalled(null));
        $this->assertTrue(AiWorkerJobPredicatesSupport::providerWasCalled('timeout'));
    }

    #[Test]
    public function job_kind_predicates(): void
    {
        $this->assertTrue(AiWorkerJobPredicatesSupport::isCouncilJob('council'));
        $this->assertTrue(AiWorkerJobPredicatesSupport::isCouncilJob('chat', ['execution_policy' => 'dual_review']));
        $this->assertFalse(AiWorkerJobPredicatesSupport::isCouncilJob('chat'));

        $this->assertTrue(AiWorkerJobPredicatesSupport::isAtlasScoutJob(
            ['atlas_decide_stage' => 'context_scout'],
        ));
        $this->assertTrue(AiWorkerJobPredicatesSupport::isAtlasPrimaryExecutorJob([
            'atlas_decide_stage' => 'primary_executor',
            'dependency_state' => 'pending',
            'dependency_job_id' => 'job-1',
        ]));
        $this->assertFalse(AiWorkerJobPredicatesSupport::isAtlasPrimaryExecutorJob([
            'atlas_decide_stage' => 'primary_executor',
            'dependency_state' => 'pending',
        ]));

        $this->assertSame(
            ['class' => 'normal'],
            AiWorkerJobPredicatesSupport::privacyFromJob(['privacy' => ['class' => 'normal']]),
        );
        $this->assertSame([], AiWorkerJobPredicatesSupport::privacyFromJob());
    }

    #[Test]
    public function time_diff_git_shortstat_and_plan_revisions(): void
    {
        $start = CarbonImmutable::parse('2026-07-24T12:00:00.000000Z');
        $end = CarbonImmutable::parse('2026-07-24T12:00:01.500000Z');
        $this->assertSame(1500, AiWorkerTimeDiffSupport::diffMs($start, $end));
        $this->assertNull(AiWorkerTimeDiffSupport::diffMs(null, $end));

        $stats = AiWorkerGitShortstatSupport::parse('3 files changed, 48 insertions(+), 12 deletions(-)');
        $this->assertSame(3, $stats['files_touched']);
        $this->assertSame(48, $stats['lines_added']);
        $this->assertSame(12, $stats['lines_removed']);
        $this->assertNull(AiWorkerGitShortstatSupport::parse(''));

        $revs = AiWorkerPlanRevisionsSupport::afterReplan(
            ['execution_plan' => ['steps' => [1]], 'plan_revisions' => []],
            2,
            'replan',
            '2026-07-24T12:00:00Z',
        );
        $this->assertCount(1, $revs);
        $this->assertSame(1, $revs[0]['revision']);
        // No current plan → previous history preserved (never fabricates empty revision).
        $this->assertSame(
            [['revision' => 1]],
            AiWorkerPlanRevisionsSupport::afterReplan(['plan_revisions' => [['revision' => 1]]], 1, 'x', 't'),
        );
    }
}
