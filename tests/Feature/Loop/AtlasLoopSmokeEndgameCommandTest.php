<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the real-provider smoke endgame report is live at the operator surface and emits deterministic
 * facts: with no smoke supplied it reports the smoke-required blocker, surfaces the named blocker id and that
 * no smoke is under review.
 */
final class AtlasLoopSmokeEndgameCommandTest extends TestCase
{
    public function test_builds_smoke_endgame_report_blocked_without_smoke(): void
    {
        $exit = Artisan::call('atlas:loop:smoke-endgame', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction.real_provider_smoke_endgame.v1', $decoded['schema_version']);
        $this->assertSame('read_only_real_provider_smoke_endgame', $decoded['mode']);
        $this->assertSame('end_to_end_real_provider_smoke_green', $decoded['blocker_id']);
        $this->assertSame('blocked_operator_real_provider_smoke_required', $decoded['status']);
        $this->assertFalse($decoded['smoke_under_review']['present']);
    }
}
