<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\ProductMode;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * AP-786 command output must always carry the anti-fake proof so a cycle can
 * never look "done" without proving the full owner-flow, and must never present
 * --allow-direct-provider-driver as a normal path.
 */
final class AutonomousEvolutionSessionCommandAntiFakeTest extends TestCase
{
    public function test_json_output_includes_anti_fake_proof_block(): void
    {
        // Dry-run (no --execute): safe, creates no branch/provider/commit/merge.
        Artisan::call('atlas:software-company-stewardship:autonomous-evolution-session', [
            '--area' => 'agentic_engineering_os',
            '--cycles' => 1,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('AP-786', $payload['ap_contract'] ?? null);

        $this->assertArrayHasKey('anti_fake_proof', $payload);
        $proof = $payload['anti_fake_proof'];
        $this->assertFalse($proof['direct_provider_driver_allowed'], 'default run must not allow the direct provider driver');
        $this->assertTrue($proof['requires_full_atlas_forge_owner_flow']);
        $this->assertTrue($proof['robust_contract_required']);
        $this->assertSame(
            ['AP-747', 'AP-756', 'AP-757', 'AP-749', 'AP-758', 'AP-759', 'AP-750'],
            $proof['owner_flow_chain'],
        );

        // Dry-run never executes provider, branch, commit or merge.
        $this->assertFalse((bool) data_get($payload, 'claim_policy.merge_performed', false));
    }
}
