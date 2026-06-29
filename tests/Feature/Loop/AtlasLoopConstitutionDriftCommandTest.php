<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the constitution drift detector is live at the operator surface: the command emits the deterministic
 * DriftReport facts (drifted + fingerprints + diff summary) over the live constitution.
 */
final class AtlasLoopConstitutionDriftCommandTest extends TestCase
{
    public function test_constitution_drift_emits_drift_report(): void
    {
        $exit = Artisan::call('atlas:loop:constitution-drift', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        foreach (['drifted', 'current_fingerprint', 'last_known_fingerprint', 'last_operator_receipt_id', 'last_verified_at', 'diff_summary'] as $key) {
            $this->assertArrayHasKey($key, $decoded);
        }
        $this->assertIsBool($decoded['drifted']);
        $this->assertNotSame('', $decoded['current_fingerprint'], 'the live constitution has a computed fingerprint');
        $this->assertIsArray($decoded['diff_summary']);
    }
}
