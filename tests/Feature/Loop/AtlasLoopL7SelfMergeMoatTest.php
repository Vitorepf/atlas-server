<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\AtlasLoopAutoMergeService;
use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

/**
 * L7 SELF-MERGE — MOAT-BOUNDED (pétreo). With auto-merge ARMED (self_improvement_auto_merge_enabled=true) the
 * loop may land its OWN certified self-edits autonomously — but the cert-moat is pétreo: a self-edit on a
 * FORBIDDEN_SELF_TARGET (the judge / certifier / auto-merge / materializer / prioritizers) is REJECTED and
 * parked for operator review, REGARDLESS of the flag. The forbidden gate is checked BEFORE the flag, so the
 * flag can never bypass it. Proven WITHOUT git: the gate returns before any apply/canary work.
 *
 * This test does not flip any production flag durably (config() is test-scoped) and never runs the loop.
 */
final class AtlasLoopL7SelfMergeMoatTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_proposals')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
                '2026_06_11_000100_add_quality_columns_to_atlas_loop_tables.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
    }

    private function campaign(): AtlasLoopCampaign
    {
        return AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'l7 moat',
            'config' => [],
            'max_seconds' => 60,
        ]);
    }

    private function selfEditProposal(string $campaignId, string $targetPath): AtlasLoopProposal
    {
        $p = AtlasLoopProposal::create([
            'campaign_id' => $campaignId,
            'schema_version' => 'atlas.loop.proposal.v1',
            'status' => AtlasLoopProposal::STATUS_CERTIFIED,
            'objective' => 'the loop edits its own harness',
            'target_path' => $targetPath,
            'diff_text' => 'x',
            'proposal_hash' => substr(hash('sha256', $targetPath), 0, 40),
            'merged_to_main' => false,
        ]);
        // Mark it a legitimate self-improvement (quality._is_self_improvement) — so we prove even a "legit"
        // self-edit is moat-bounded.
        DB::table('atlas_loop_proposals')->where('id', $p->id)->update(['quality' => json_encode(['_is_self_improvement' => true])]);
        $p->refresh();

        return $p;
    }

    /** Invoke the auto-merge per-proposal gate in isolation (null authorizedCanonical => returns before git). */
    private function attemptAutoMerge(AtlasLoopProposal $p): array
    {
        $service = app(AtlasLoopAutoMergeService::class);

        return (new ReflectionMethod($service, 'mergeOneCritical'))->invoke($service, $p, base_path(), false, null, null, null);
    }

    public function test_the_guard_recognizes_every_moat_organ_as_petreo(): void
    {
        $guard = new AtlasLoopHarnessGuard;
        foreach (AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS as $organ) {
            $this->assertTrue($guard->isForbiddenSelfTarget($organ), $organ.' must be a forbidden self-target (pétreo)');
        }
    }

    public function test_forbidden_self_target_is_rejected_even_with_auto_merge_ARMED(): void
    {
        config(['atlas.loop.self_improvement_auto_merge_enabled' => true]); // ← ARMED
        $campaign = $this->campaign();
        $proposal = $this->selfEditProposal((string) $campaign->id, 'app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php');

        $result = $this->attemptAutoMerge($proposal);

        $this->assertFalse((bool) $result['merged'], 'a self-edit on the FROZEN JUDGE never auto-merges, even armed');
        $this->assertStringContainsString('forbidden_self_target', (string) $result['reason']);
        $proposal->refresh();
        $this->assertFalse((bool) $proposal->merged_to_main, 'merged_to_main stays false — the pétreo moat holds');
        $this->assertNotNull($proposal->reviewed_at, 'the forbidden self-edit is parked for operator review');
    }

    public function test_every_forbidden_organ_is_rejected_armed(): void
    {
        config(['atlas.loop.self_improvement_auto_merge_enabled' => true]); // ← ARMED
        $campaign = $this->campaign();
        foreach (AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS as $i => $organ) {
            $proposal = $this->selfEditProposal((string) $campaign->id, $organ);
            $result = $this->attemptAutoMerge($proposal);
            $this->assertStringContainsString('forbidden_self_target', (string) $result['reason'], $organ.' must be rejected armed');
            $this->assertFalse((bool) $proposal->fresh()->merged_to_main, $organ.' must not merge');
        }
    }

    public function test_non_forbidden_self_improvement_still_parks_when_flag_OFF(): void
    {
        config(['atlas.loop.self_improvement_auto_merge_enabled' => false]); // ← NOT armed
        $campaign = $this->campaign();
        // A NON-forbidden loop file: passes the moat gate, but the flag (OFF) still parks it for review.
        $proposal = $this->selfEditProposal((string) $campaign->id, 'app/Services/Ai/AutonomousEvolution/AtlasLoopSoakReportService.php');

        $result = $this->attemptAutoMerge($proposal);

        $this->assertFalse((bool) $result['merged']);
        $this->assertStringContainsString('self_improvement', (string) $result['reason'], 'flag OFF => even a non-forbidden self-edit parks (the flag is the only thing that unlocks autonomy)');
        $this->assertFalse((bool) $proposal->fresh()->merged_to_main);
    }
}
