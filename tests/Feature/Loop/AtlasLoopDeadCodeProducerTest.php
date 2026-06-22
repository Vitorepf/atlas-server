<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\AtlasLoopDeadCodeProducer;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The IN-CAMPAIGN persistence for the deterministic dead-code work-type: a campaign MILLS→CERTIFIES a real
 * removal and the producer PERSISTS it as a propose-only proposal — with NO provider. The load-bearing
 * guarantees: the row lands status='certified_for_review' (OPERATOR REVIEW, never auto-merge), it carries NO
 * `_acceptance_contract` (so it can never ride the value-gate / drain-merge), and it captures a real diff +
 * the cert evidence. Fail-closed on a clean file.
 */
final class AtlasLoopDeadCodeProducerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_campaigns')) {
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
        AtlasLoopProposal::$governedMergeInProgress = false;

        return AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'deadcode persist proof',
            'config' => [],
            'max_seconds' => 60,
        ]);
    }

    private function fixtureDir(string $php): string
    {
        $dir = sys_get_temp_dir().'/atlas-dcp-'.bin2hex(random_bytes(5));
        @mkdir($dir, 0777, true);
        file_put_contents($dir.'/Sample.php', $php);

        return $dir;
    }

    public function test_persists_a_certified_removal_as_a_propose_only_proposal_no_provider(): void
    {
        $campaign = $this->campaign();
        $dir = $this->fixtureDir(<<<'PHP'
<?php

class Sample
{
    public function entry(): int
    {
        return $this->live();
    }

    private function deadHelper(): string
    {
        return 'never called';
    }

    private function live(): int
    {
        return 1;
    }

    private const DEAD_K = 'unused';
}
PHP);

        $id = (new AtlasLoopDeadCodeProducer)->persistCertifiedRemoval((string) $campaign->id, $dir, 'Sample.php');

        @unlink($dir.'/Sample.php');
        @rmdir($dir);

        $this->assertNotNull($id, 'a certified removal is persisted');
        $proposal = AtlasLoopProposal::find($id);
        $this->assertNotNull($proposal);
        $this->assertSame(AtlasLoopProposal::STATUS_CERTIFIED, $proposal->status, "persisted 'certified_for_review' => operator review, never auto-merge");
        $this->assertSame('Sample.php', $proposal->target_path);
        $this->assertSame('deterministic', $proposal->provider);
        $this->assertStringContainsString('deadHelper', (string) $proposal->diff_text, 'the diff shows the removed member');
        $quality = (array) $proposal->quality;
        $this->assertArrayHasKey('_deterministic_removal', $quality);
        $this->assertArrayNotHasKey('_acceptance_contract', $quality, 'no acceptance contract => never rides the value-gate / auto-merge');
        $this->assertFalse($quality['_deterministic_removal']['provider_used'], 'the whole chain used NO provider');
        $this->assertCount(2, $quality['_deterministic_removal']['removed']);
    }

    public function test_real_work_scorecard_credits_the_deterministic_removal(): void
    {
        $campaign = $this->campaign();
        $dir = $this->fixtureDir(<<<'PHP'
<?php

class Sample
{
    public function entry(): int
    {
        return $this->live();
    }

    private function deadHelper(): string
    {
        return 'never called';
    }

    private function live(): int
    {
        return 1;
    }
}
PHP);

        (new AtlasLoopDeadCodeProducer)->persistCertifiedRemoval((string) $campaign->id, $dir, 'Sample.php');
        @unlink($dir.'/Sample.php');
        @rmdir($dir);

        $scorecard = app(\App\Services\Ai\AutonomousEvolution\AtlasLoopRealWorkScorecardService::class)
            ->scorecard((string) $campaign->id);

        $this->assertSame('ok', $scorecard['status']);
        $this->assertGreaterThanOrEqual(
            1,
            $scorecard['deterministic_real_work_proposals'],
            'the real-work scorecard credits the deterministic removal as REAL value (not coverage)'
        );
    }

    public function test_same_producer_persists_an_unused_import_removal_generalized(): void
    {
        $campaign = $this->campaign();
        $dir = $this->fixtureDir(<<<'PHP'
<?php

namespace Foo;

use App\Used\Thing;
use App\Unused\Gone;

class Sample
{
    public function go(): Thing
    {
        return new Thing();
    }
}
PHP);

        // The SAME AtlasLoopDeadCodeProducer, given a DIFFERENT deterministic work-type, persists its removal —
        // proving the producer/persist/scorecard path is generic to any AtlasLoopDeterministicWorkType.
        $producer = new AtlasLoopDeadCodeProducer(new \App\Services\Ai\AutonomousEvolution\AtlasLoopUnusedImportWorkType);
        $id = $producer->persistCertifiedRemoval((string) $campaign->id, $dir, 'Sample.php');

        @unlink($dir.'/Sample.php');
        @rmdir($dir);

        $this->assertNotNull($id, 'the generic producer persists an unused-import removal');
        $proposal = AtlasLoopProposal::find($id);
        $this->assertSame('deterministic', $proposal->provider);
        $this->assertSame(AtlasLoopProposal::STATUS_CERTIFIED, $proposal->status);
        $this->assertStringContainsString('Gone', (string) $proposal->diff_text, 'the unused import is in the diff');
        $this->assertArrayHasKey('_deterministic_removal', (array) $proposal->quality);
    }

    public function test_persist_fails_closed_on_a_clean_file(): void
    {
        $campaign = $this->campaign();
        $dir = $this->fixtureDir(<<<'PHP'
<?php

class Sample
{
    public function entry(): int
    {
        return $this->live();
    }

    private function live(): int
    {
        return 1;
    }
}
PHP);

        $id = (new AtlasLoopDeadCodeProducer)->persistCertifiedRemoval((string) $campaign->id, $dir, 'Sample.php');

        @unlink($dir.'/Sample.php');
        @rmdir($dir);

        $this->assertNull($id, 'no dead members => nothing persisted (no fake proposal)');
        $this->assertSame(0, AtlasLoopProposal::where('campaign_id', $campaign->id)->count());
    }
}
