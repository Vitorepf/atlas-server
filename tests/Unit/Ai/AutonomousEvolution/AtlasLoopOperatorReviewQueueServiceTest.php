<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\AtlasLoopOperatorReviewQueueService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AtlasLoopOperatorReviewQueueServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('atlas_loop_campaigns')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }

        if (Schema::hasTable('atlas_loop_proposals') && ! Schema::hasColumn('atlas_loop_proposals', 'quality')) {
            (require base_path('database/migrations/2026_06_11_000100_add_quality_columns_to_atlas_loop_tables.php'))->up();
        }

        foreach (['atlas_loop_targets', 'atlas_loop_proposals', 'atlas_loop_tasks', 'atlas_loop_campaigns'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        AtlasLoopProposal::$governedMergeInProgress = false;
    }

    #[DataProvider('terminalOperatorReviewStatuses')]
    public function test_queue_excludes_terminal_operator_review_statuses(string $status): void
    {
        $proposal = $this->proposal($status);

        $queue = $this->service()->queue();

        $this->assertSame('ok', $queue['status']);
        $this->assertSame(0, $queue['count']);
        $this->assertSame([], $queue['items']);
        $this->assertSame($status, $proposal->fresh()?->quality['_operator_review']['status']);
    }

    public function test_queue_keeps_parked_operator_review_proposals_visible(): void
    {
        $proposal = $this->proposal('parked_for_operator_review');

        $queue = $this->service()->queue();

        $this->assertSame('ok', $queue['status']);
        $this->assertSame(1, $queue['count']);
        $this->assertSame((string) $proposal->getKey(), $queue['items'][0]['id']);
        $this->assertSame('parked_for_operator_review', $queue['items'][0]['review_status']);
        $this->assertSame('forbidden_self_target', $queue['items'][0]['reason']);
    }

    public function test_queue_keeps_approval_failed_proposals_visible_even_without_forbidden_self_target(): void
    {
        $proposal = $this->proposal('approval_failed', [
            'target_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopMorningDigestService.php',
            'reason' => 'approval_failed',
        ]);

        $queue = $this->service()->queue();

        $this->assertSame('ok', $queue['status']);
        $this->assertSame(1, $queue['count']);
        $this->assertSame((string) $proposal->getKey(), $queue['items'][0]['id']);
        $this->assertSame('approval_failed', $queue['items'][0]['review_status']);
        $this->assertSame('approval_failed', $queue['items'][0]['reason']);
    }

    public function test_queue_keeps_reviewed_forbidden_self_targets_visible_even_without_operator_review_queue_status(): void
    {
        $proposal = $this->proposal('needs_manual_triage', [
            'reason' => 'manual_triage',
        ]);

        $queue = $this->service()->queue();

        $this->assertSame('ok', $queue['status']);
        $this->assertSame(1, $queue['count']);
        $this->assertSame((string) $proposal->getKey(), $queue['items'][0]['id']);
        $this->assertSame('needs_manual_triage', $queue['items'][0]['review_status']);
        $this->assertSame('manual_triage', $queue['items'][0]['reason']);
    }

    /**
     * @return array<string, array{0:string}>
     */
    public static function terminalOperatorReviewStatuses(): array
    {
        return [
            'rejected' => ['rejected'],
            'merged' => ['merged'],
        ];
    }

    private function service(): AtlasLoopOperatorReviewQueueService
    {
        return app(AtlasLoopOperatorReviewQueueService::class);
    }

    private function campaign(): AtlasLoopCampaign
    {
        return AtlasLoopCampaign::query()->create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'atlas-loop-operator-review-queue-service-test',
            'base_workspace' => base_path(),
            'config' => [],
            'max_seconds' => 3600,
        ]);
    }

    /**
     * @param  array{target_path?:string,reason?:string,reviewed_at?:Carbon|null}  $overrides
     */
    private function proposal(string $reviewStatus, array $overrides = []): AtlasLoopProposal
    {
        $reviewedAt = $overrides['reviewed_at'] ?? now();

        return AtlasLoopProposal::query()->create([
            'campaign_id' => $this->campaign()->id,
            'schema_version' => 'atlas.loop.proposal.v1',
            'status' => AtlasLoopProposal::STATUS_CERTIFIED,
            'objective' => 'operator review queue characterization',
            'target_path' => $overrides['target_path'] ?? 'app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php',
            'diff_text' => 'diff --git a/x b/x',
            'proposal_hash' => 'atlas-loop-operator-review-queue-'.str_replace('_', '-', $reviewStatus).'-'.bin2hex(random_bytes(4)),
            'metric' => null,
            'quality' => [
                '_operator_review' => [
                    'schema_version' => 'atlas.loop.operator_review.v1',
                    'status' => $reviewStatus,
                    'reason' => $overrides['reason'] ?? 'forbidden_self_target',
                    'operator_id' => 'auto_merge',
                    'reviewed_at' => $reviewedAt?->toIso8601String(),
                    'decision' => 'park',
                ],
            ],
            'reviewed_at' => $reviewedAt,
        ]);
    }
}
