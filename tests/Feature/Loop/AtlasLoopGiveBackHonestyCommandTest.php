<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the give-back honesty auditor is live at the operator surface: the command emits the deterministic
 * per-packet honesty-audit facts (count + audits list of verdicts).
 */
final class AtlasLoopGiveBackHonestyCommandTest extends TestCase
{
    public function test_give_back_honesty_emits_audit_facts(): void
    {
        $exit = Artisan::call('atlas:loop:give-back-honesty', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.give_back_honesty.v1', $decoded['schema_version']);
        $this->assertIsArray($decoded['audits']);
        $this->assertSame(count($decoded['audits']), $decoded['audited_count']);
    }
}
