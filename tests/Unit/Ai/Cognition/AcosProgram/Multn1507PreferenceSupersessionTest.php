<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition\AcosProgram;

use App\Services\Ai\Operator\PreferenceSupersessionPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class Multn1507PreferenceSupersessionTest extends TestCase
{
    #[Test]
    public function five_consecutive_overrides_pause_active_preference(): void
    {
        $out = PreferenceSupersessionPolicy::evaluate([
            'profile_key' => 'style.concise',
            'safety_floor' => false,
        ], ['override', 'override', 'override', 'override', 'override']);

        $this->assertSame('pause_old_preference', $out['action']);
        $this->assertNotNull($out['reverse_handle']);
    }

    #[Test]
    public function accepted_event_breaks_the_override_streak(): void
    {
        $out = PreferenceSupersessionPolicy::evaluate([
            'profile_key' => 'style.concise',
        ], ['override', 'override', 'accepted', 'override', 'override', 'override']);

        $this->assertSame('no_action', $out['action']);
        $this->assertSame('streak_below_floor', $out['basis']);
    }

    #[Test]
    public function safety_floor_preference_is_never_auto_demoted(): void
    {
        $out = PreferenceSupersessionPolicy::evaluate([
            'profile_key' => 'do_not_touch_migrations',
            'safety_floor' => true,
        ], ['override', 'override', 'override', 'override', 'override']);

        $this->assertSame('no_action', $out['action']);
        $this->assertSame('safety_floor_protected', $out['basis']);
    }
}
