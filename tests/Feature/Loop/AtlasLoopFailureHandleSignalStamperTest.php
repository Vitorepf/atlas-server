<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTarget;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopFailureHandleSignalStamper;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * P1 MATERIAL-FUEL CONNECTION — the external bug-reproduction handle stamper. Proves the fail-closed
 * admissibility branches and the ANTI-GOODHART invariant: the stamper ONLY merges operator-supplied failure
 * handles onto already-existing campaign targets; it NEVER fabricates a target row or a failure.
 *
 * Uses the loop's focused-migration setUp (no RefreshDatabase: the full suite has a Postgres-only extension).
 */
final class AtlasLoopFailureHandleSignalStamperTest extends TestCase
{
    /** A real pétreo path — in AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS. */
    private const PETREO_PATH = 'app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php';

    private string $artifact;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_targets')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
        if (! Schema::hasColumn('atlas_loop_targets', 'parent_target_id')) {
            (require base_path('database/migrations/2026_06_17_000400_add_idea_tree_columns_to_atlas_loop_targets.php'))->up();
        }

        $this->artifact = sys_get_temp_dir().'/atlas-loop-ext-handles-'.bin2hex(random_bytes(6)).'.json';
        // The crossing is OFF by default; every branch test that needs it flips it ON explicitly.
        config(['atlas.loop.bug_reproduction_external_handles_enabled' => true]);
        config(['atlas.loop.bug_reproduction_external_handles_path' => $this->artifact]);
    }

    protected function tearDown(): void
    {
        @unlink($this->artifact);
        parent::tearDown();
    }

    private function campaign(): AtlasLoopCampaign
    {
        return AtlasLoopCampaign::query()->create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'goal' => 'external failure-handle stamp proof',
            'status' => 'running',
            'config' => [],
        ]);
    }

    /** @param array<string,mixed> $signals */
    private function target(AtlasLoopCampaign $c, string $path, array $signals = [], string $status = AtlasLoopTarget::STATUS_CANDIDATE): AtlasLoopTarget
    {
        return AtlasLoopTarget::query()->create([
            'campaign_id' => $c->id,
            'schema_version' => 'atlas.loop.target.v1',
            'target_path' => $path,
            'target_key' => hash('sha256', $c->id.'|'.$path),
            'content_hash' => 'h0',
            'status' => $status,
            'score' => 0.9,
            'novelty_score' => 1.0,
            'signals' => $signals,
            'attempts' => 0,
            'max_attempts' => 3,
        ]);
    }

    /** @param list<array<string,mixed>> $entries */
    private function writeArtifact(array $entries): void
    {
        file_put_contents($this->artifact, (string) json_encode($entries));
    }

    private function stamper(): AtlasLoopFailureHandleSignalStamper
    {
        return new AtlasLoopFailureHandleSignalStamper;
    }

    // ── branch (a): missing artifact ─────────────────────────────────────────────────────────────────────

    public function test_missing_artifact_returns_no_artifact(): void
    {
        $c = $this->campaign();
        @unlink($this->artifact); // never written

        $out = $this->stamper()->stamp($c);

        $this->assertSame(['stamped' => 0, 'source' => 'no_artifact'], $out);
    }

    // ── branch (b): malformed JSON ───────────────────────────────────────────────────────────────────────

    public function test_malformed_json_returns_malformed(): void
    {
        $c = $this->campaign();
        file_put_contents($this->artifact, '{not valid json'); // garbage

        $out = $this->stamper()->stamp($c);

        $this->assertSame(['stamped' => 0, 'source' => 'malformed'], $out);
    }

    // ── branch (c): valid entry matching a real target → signals MERGED ──────────────────────────────────

    public function test_valid_entry_merges_failure_handle_into_existing_target_signals(): void
    {
        $c = $this->campaign();
        $t = $this->target($c, 'app/Payment/Refund.php', ['existing' => 'keep']);

        $this->writeArtifact([[
            'target_path' => 'app/Payment/Refund.php',
            'failure_test_path' => 'tests/Feature/Payment/RefundTest.php',
            'failure_command' => 'php artisan test tests/Feature/Payment/RefundTest.php',
            'failing_assertion' => 'assertSame(2, $refund->units())',
            'failure_message' => 'Failed asserting that 3 is identical to 2.',
        ]]);

        $out = $this->stamper()->stamp($c);

        $this->assertSame(1, $out['stamped']);
        $this->assertSame('stamped', $out['source']);

        $t->refresh();
        $signals = $t->signals;
        $this->assertSame('tests/Feature/Payment/RefundTest.php', $signals['failure_test_path']);
        $this->assertSame('php artisan test tests/Feature/Payment/RefundTest.php', $signals['failure_command']);
        $this->assertSame('assertSame(2, $refund->units())', $signals['failing_assertion']);
        $this->assertSame('Failed asserting that 3 is identical to 2.', $signals['failure_message']);
        $this->assertSame('keep', $signals['existing'], 'merge must preserve pre-existing signals');
    }

    // ── branch (d): pétreo target_path SKIPPED ───────────────────────────────────────────────────────────

    public function test_petreo_target_path_is_skipped(): void
    {
        $c = $this->campaign();
        $t = $this->target($c, self::PETREO_PATH); // a real row exists, but the path is pétreo

        $this->writeArtifact([[
            'target_path' => self::PETREO_PATH,
            'failure_test_path' => 'tests/Feature/Loop/AtlasLoopMasterSwitchTest.php',
        ]]);

        $out = $this->stamper()->stamp($c);

        $this->assertSame(0, $out['stamped'], 'a pétreo self-target is never stamped');
        $t->refresh();
        $this->assertArrayNotHasKey('failure_test_path', (array) $t->signals, 'no handle merged onto a pétreo target');
    }

    // ── branch (e): target_path not in the campaign SKIPPED — NEVER inserts ──────────────────────────────

    public function test_target_path_not_in_campaign_is_skipped_and_never_inserts(): void
    {
        $c = $this->campaign();
        $before = AtlasLoopTarget::query()->where('campaign_id', $c->id)->count();

        $this->writeArtifact([[
            'target_path' => 'app/Totally/Unknown/Thing.php',
            'failure_test_path' => 'tests/Feature/Unknown/ThingTest.php',
        ]]);

        $out = $this->stamper()->stamp($c);

        $this->assertSame(0, $out['stamped'], 'an unknown target_path is skipped');
        $after = AtlasLoopTarget::query()->where('campaign_id', $c->id)->count();
        $this->assertSame($before, $after, 'the stamper must NEVER fabricate a target row');
        $this->assertSame(0, AtlasLoopTarget::query()->where('target_path', 'app/Totally/Unknown/Thing.php')->count());
    }

    // ── default OFF: early-return no-op (acceptance #6) ──────────────────────────────────────────────────

    public function test_disabled_is_an_early_return_no_op(): void
    {
        config(['atlas.loop.bug_reproduction_external_handles_enabled' => false]);
        $c = $this->campaign();
        $t = $this->target($c, 'app/Payment/Refund.php');
        $this->writeArtifact([[
            'target_path' => 'app/Payment/Refund.php',
            'failure_test_path' => 'tests/Feature/Payment/RefundTest.php',
        ]]);

        $out = $this->stamper()->stamp($c);

        $this->assertSame(['stamped' => 0, 'source' => 'disabled'], $out);
        $t->refresh();
        $this->assertArrayNotHasKey('failure_test_path', (array) $t->signals, 'OFF must not touch any target');
    }
}
