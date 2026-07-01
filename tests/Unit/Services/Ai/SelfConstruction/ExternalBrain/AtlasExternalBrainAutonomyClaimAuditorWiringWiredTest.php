<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves AtlasExternalBrainAutonomyClaimAuditor is wired into a real call path via the
 * atlas:external-brain:originator-stop-pivot command's new autonomy_claim_audits section — it is
 * no longer an orphan.
 */
final class AtlasExternalBrainAutonomyClaimAuditorWiringWiredTest extends TestCase
{
    private string $factsFile = '';

    protected function tearDown(): void
    {
        if ($this->factsFile !== '' && is_file($this->factsFile)) {
            unlink($this->factsFile);
        }
        parent::tearDown();
    }

    private function exec(array $facts): array
    {
        $this->factsFile = sys_get_temp_dir().'/atlas_stop_pivot_claim_audit_facts_'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($this->factsFile, (string) json_encode($facts));

        Artisan::call('atlas:external-brain:originator-stop-pivot', [
            '--facts-file' => $this->factsFile,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    public function test_autonomy_claims_audited_when_supplied(): void
    {
        $output = $this->exec([
            'autonomy_claims' => [
                ['claim' => 'queue healthy', 'evidence_refs' => []],
                ['claim' => '24/7 autonomous', 'evidence_refs' => ['runnable_end_to_end_replay', 'fresh_outcome_learning']],
            ],
        ]);

        $this->assertCount(2, $output['autonomy_claim_audits']);
        $this->assertSame('queue healthy', $output['autonomy_claim_audits'][0]['claim']);
        $this->assertSame('unsupported', $output['autonomy_claim_audits'][0]['status']);
        $this->assertSame('24/7 autonomous', $output['autonomy_claim_audits'][1]['claim']);
        $this->assertSame('proven', $output['autonomy_claim_audits'][1]['status']);
    }

    public function test_no_autonomy_claims_yields_empty_audits(): void
    {
        $output = $this->exec([]);

        $this->assertSame([], $output['autonomy_claim_audits']);
    }
}
