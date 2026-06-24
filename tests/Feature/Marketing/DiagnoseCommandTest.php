<?php

namespace Tests\Feature\Marketing;

use Tests\TestCase;

/**
 * atlas:ai:marketing:diagnose — the operator's window into the 1→25 conversion brain (leverage bottleneck
 * + audience verdict + abandon point + kill/scale). Smoke test: it runs and emits the structured read.
 */
class DiagnoseCommandTest extends TestCase
{
    public function test_diagnose_runs_and_emits_json(): void
    {
        $page = 'If you are a woman over 40, here is how The 3-Hormone Reset works. '
            .'Dr Lee tracked 312 women; 9 out of 10 dropped a size in 6 weeks. '
            .'Get instant access, no credit card, cancel anytime. Watch the free presentation.';

        $this->artisan('atlas:ai:marketing:diagnose', [
            '--page' => $page, '--niche' => 'weight loss', '--mechanism' => 'The 3-Hormone Reset', '--json' => true,
        ])->assertExitCode(0);
    }

    public function test_diagnose_kill_scale_on_campaign_stats(): void
    {
        // 200 clicks, 0 sales → the decider should fire KILL in the output.
        $this->artisan('atlas:ai:marketing:diagnose', [
            '--page' => 'Some page copy with a mechanism and proof from Dr Lee on 312 women.',
            '--niche' => 'weight loss',
            '--clicks' => '200', '--conversions' => '0', '--spend' => '400', '--payout' => '100',
        ])->expectsOutputToContain('KILL')->assertExitCode(0);
    }

    public function test_diagnose_requires_a_page(): void
    {
        $this->artisan('atlas:ai:marketing:diagnose')->assertExitCode(1);
    }
}
