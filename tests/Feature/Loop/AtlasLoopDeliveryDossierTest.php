<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\AtlasLoopDeliveryDossierService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ACDE F3 — proves the per-delivery dossier: signed, machine-resolved D2 dimensions over the merged
 * deliveries, tamper-evident, and byte-identical (empty) when the flag is OFF.
 */
final class AtlasLoopDeliveryDossierTest extends TestCase
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
        AtlasLoopProposal::query()->delete();
        AtlasLoopCampaign::query()->delete();
    }

    private function mergedDelivery(string $targetPath, array $quality): void
    {
        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'dossier',
            'config' => [],
            'max_seconds' => 60,
        ]);
        $p = AtlasLoopProposal::create([
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.proposal.v1',
            'status' => AtlasLoopProposal::STATUS_CERTIFIED,
            'objective' => 'deliver '.$targetPath,
            'target_path' => $targetPath,
            'diff_text' => '',
            'proposal_hash' => substr(hash('sha256', $targetPath), 0, 40),
            'quality' => $quality,
        ]);
        DB::table('atlas_loop_proposals')->where('id', $p->id)->update(['merged_to_main' => true, 'updated_at' => now()]);
    }

    public function test_recent_emits_signed_dossiers_with_resolved_dimensions(): void
    {
        config(['atlas.loop.delivery_dossier_enabled' => true]);
        $this->mergedDelivery('app/Foo/Clean.php', ['_canary' => ['ran' => true, 'passed' => true], 'completeness' => 1.0]);
        $this->mergedDelivery('app/Foo/Escaped.php', ['_canary' => ['ran' => true, 'passed' => false]]);

        $service = new AtlasLoopDeliveryDossierService(null, 'unit-secret');
        $dossiers = $service->recent();

        $this->assertCount(2, $dossiers);
        $byPath = [];
        foreach ($dossiers as $d) {
            $byPath[$d['target_path']] = $d;
            $this->assertSame(AtlasLoopDeliveryDossierService::SCHEMA, $d['schema_version']);
            $this->assertNotSame('', $d['signature']);
            $this->assertTrue($service->verify($d), 'a freshly signed dossier verifies');
        }
        // green canary => clean delivery; red canary => escaped defect.
        $this->assertTrue($byPath['app/Foo/Clean.php']['dimensions']['clean']);
        $this->assertFalse($byPath['app/Foo/Escaped.php']['dimensions']['clean']);
        $this->assertSame('canary_red', $byPath['app/Foo/Escaped.php']['dimensions']['defect_reason']);
    }

    public function test_signature_is_tamper_evident(): void
    {
        $service = new AtlasLoopDeliveryDossierService(null, 'unit-secret');
        $dossier = $service->dossierFor([
            'proposal_id' => 'p1',
            'target_path' => 'app/Foo/Bar.php',
            'objective' => 'do x',
            'delivered_at' => '2026-06-16 00:00:00',
            'quality' => ['_canary' => ['ran' => true, 'passed' => true]],
        ]);
        $this->assertTrue($service->verify($dossier));

        // Mutating the body after signing must invalidate the signature.
        $forged = $dossier;
        $forged['target_path'] = 'app/Foo/Evil.php';
        $this->assertFalse($service->verify($forged), 'an edited dossier fails verification');

        // A different secret must not verify a dossier signed with another key.
        $this->assertFalse((new AtlasLoopDeliveryDossierService(null, 'other-secret'))->verify($dossier));
    }

    public function test_off_is_byte_identical_empty(): void
    {
        config(['atlas.loop.delivery_dossier_enabled' => false]);
        $this->mergedDelivery('app/Foo/Clean.php', ['_canary' => ['ran' => true, 'passed' => true]]);

        $this->assertSame([], (new AtlasLoopDeliveryDossierService(null, 'unit-secret'))->recent(), 'flag OFF => empty => byte-identical');
    }
}
