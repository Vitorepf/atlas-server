<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopRecursiveSelfImprovementGate;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Proves the recursive-self-improvement gate is live at the operator surface: a cert-organ target is REFUSED
 * (constitution, intent-independent); a non-pétreo brain file is a LEGAL proposal that is PARKED while the
 * auto-apply policy is OFF and ARMED only when the operator turns it ON.
 */
final class AtlasLoopRecursiveSelfImprovementGateCommandTest extends TestCase
{
    private const PETREO = 'app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php';

    private const NON_PETREO = 'app/Services/Ai/AutonomousEvolution/AtlasLoopBacklogIntentSource.php';

    private function gate(array $params): array
    {
        $exit = Artisan::call('atlas:loop:recursive-self-improvement-gate', $params + ['--json' => true]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_cert_organ_target_is_refused_even_with_harden_intent(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->gate(['--target' => self::PETREO, '--intent' => 'harden']);

        $this->assertSame(0, $exit);
        $this->assertFalse($d['admitted']);
        $this->assertFalse($d['auto_apply']);
        $this->assertSame(AtlasLoopRecursiveSelfImprovementGate::STATUS_REFUSED_PETREO, $d['status']);
    }

    public function test_non_petreo_target_is_parked_when_policy_off(): void
    {
        Config::set('atlas.loop.recursive_self_improvement_auto_apply', false);

        ['exit' => $exit, 'd' => $d] = $this->gate(['--target' => self::NON_PETREO, '--intent' => 'harden']);

        $this->assertSame(0, $exit);
        $this->assertTrue($d['admitted']);
        $this->assertFalse($d['auto_apply']);
        $this->assertSame(AtlasLoopRecursiveSelfImprovementGate::STATUS_PARKED, $d['status']);
        $this->assertSame(AtlasLoopRecursiveSelfImprovementGate::KIND_HARDEN, $d['kind']);
    }

    public function test_non_petreo_target_is_armed_when_policy_on(): void
    {
        Config::set('atlas.loop.recursive_self_improvement_auto_apply', true);

        ['exit' => $exit, 'd' => $d] = $this->gate(['--target' => self::NON_PETREO]);

        $this->assertSame(0, $exit);
        $this->assertTrue($d['admitted']);
        $this->assertTrue($d['auto_apply']);
        $this->assertSame(AtlasLoopRecursiveSelfImprovementGate::STATUS_AUTO_APPLY_ARMED, $d['status']);
    }

    public function test_missing_target_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:recursive-self-improvement-gate', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
