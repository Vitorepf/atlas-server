<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Services\Ai\AutonomousEvolution\AtlasLoopObservabilityDigest;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopDeliveryPipeline;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * LOOP-OS · Fase 5 · §K (wiring) — the live `atlas:loop:campaign:status --json` command must embed the
 * AtlasLoopObservabilityDigest section so the operator sees the real stage funnel / parked / top-EV
 * straight from the status output (no raw-log digging). This proves the seam call is load-bearing:
 * the digest's numbers (parked === 1, max_accrued_ev === 5.0) appear in the decoded command JSON.
 */
final class AtlasLoopCampaignStatusObservabilityWiringTest extends TestCase
{
    private AtlasLoopDeliveryPipeline $pipeline;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_pipeline_state')) {
            (require base_path('database/migrations/2026_06_18_000100_create_atlas_loop_pipeline_state.php'))->up();
        }
        if (! Schema::hasTable('atlas_loop_campaigns')) {
            (require base_path('database/migrations/2026_06_02_000100_create_atlas_loop_runtime_tables.php'))->up();
        }
        $this->pipeline = new AtlasLoopDeliveryPipeline();
    }

    private function makeCampaign(string $id): AtlasLoopCampaign
    {
        $campaign = new AtlasLoopCampaign();
        $campaign->id = $id;
        $campaign->status = 'running';
        $campaign->goal = 'observability wiring proof';
        $campaign->config = ['workers' => 1];
        $campaign->save();

        return $campaign;
    }

    public function test_status_json_embeds_the_observability_digest_section(): void
    {
        $id = 'camp-obs-wire-1';
        $this->makeCampaign($id);

        // Seed the Slice-8 pipeline_state the same way the digest sibling test does.
        $this->pipeline->dispatchProjection($id, 'obj-1', 1.0);
        $this->pipeline->dispatchProjection($id, 'obj-2', 5.0);
        $this->pipeline->park('obj-1', 'oscillation'); // obj-1 ⇒ parked

        $payload = $this->decodeStatusJson($id);

        $this->assertArrayHasKey('observability', $payload, 'the wired digest section must be present');
        $obs = $payload['observability'];

        $this->assertSame(AtlasLoopObservabilityDigest::SCHEMA_VERSION, $obs['schema_version']);
        $this->assertSame('atlas.loop.observability_digest.v1', $obs['schema_version']);
        $this->assertSame(1, $obs['stage_funnel']['parked'] ?? 0, 'one objective parked');
        $this->assertSame(1, $obs['parked']);
        // Numeric value carried through the command's json_encode boundary (5.0 round-trips to 5).
        $this->assertEquals(5.0, $obs['max_accrued_ev'], 'the highest accrued EV waiting');

        // And the digest's pre-JSON section is strictly the float 5.0 — proving the wire transports
        // the REAL digest payload (not a coincidental constant the seam could fake post-serialization).
        $direct = (new AtlasLoopObservabilityDigest())->section($id);
        $this->assertSame(5.0, $direct['max_accrued_ev']);
    }

    public function test_flag_off_short_circuits_to_a_disabled_section(): void
    {
        config(['atlas.loop.observability_digest_enabled' => false]);

        $id = 'camp-obs-wire-off';
        $this->makeCampaign($id);
        $this->pipeline->dispatchProjection($id, 'obj-x', 9.0);

        $payload = $this->decodeStatusJson($id);

        $this->assertArrayHasKey('observability', $payload);
        $this->assertSame('disabled', $payload['observability']['status']);
    }

    /**
     * Run the command live (real, un-mocked console output) and decode the canonical JSON it prints.
     *
     * @return array<string,mixed>
     */
    private function decodeStatusJson(string $id): array
    {
        $exit = \Illuminate\Support\Facades\Artisan::call('atlas:loop:campaign:status', [
            '--campaign-id' => $id,
            '--json' => true,
        ]);
        $this->assertSame(0, $exit, 'status command must exit SUCCESS');

        $output = \Illuminate\Support\Facades\Artisan::output();
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'command must emit a JSON object on stdout; got: '.$output);

        return $decoded;
    }
}
