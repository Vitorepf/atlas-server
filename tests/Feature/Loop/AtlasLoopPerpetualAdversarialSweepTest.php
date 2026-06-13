<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * L5-13: the scheduled adversarial sweep must turn safety findings into governed
 * backlog evidence without directly editing code or weakening never-merge.
 */
final class AtlasLoopPerpetualAdversarialSweepTest extends TestCase
{
    private string $manifestPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manifestPath = storage_path('framework/testing/atlas-loop-perpetual-sweep-'.(string) Str::uuid().'.json');
        @File::delete($this->manifestPath);
    }

    protected function tearDown(): void
    {
        @File::delete($this->manifestPath);
        parent::tearDown();
    }

    public function test_write_sweep_parks_high_and_queues_low_without_direct_mutation(): void
    {
        config([
            'atlas.loop.perpetual_sweep.enabled' => true,
            'atlas.loop.perpetual_sweep.schedule_enabled' => true,
            'atlas.loop.perpetual_sweep.low_auto_fix_enabled' => true,
            'atlas.loop.perpetual_sweep.high_review_enabled' => true,
            'atlas.loop.perpetual_sweep.include_backlog_feed' => false,
            'atlas.loop.perpetual_sweep.carryover_findings' => [
                [
                    'path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopProposalPromotionGate.php',
                    'reason' => 'snippet_payload_missing',
                    'severity' => 'high',
                    'objective' => 'Park missing snippet payload as an operator-reviewed safety finding.',
                    'priority' => 0.96,
                ],
                [
                    'path' => 'docs/fable-lista-5-14-itens.md',
                    'reason' => 'ledger_status_refresh_needed',
                    'severity' => 'low',
                    'objective' => 'Refresh the public ledger from measured sweep evidence.',
                    'priority' => 0.61,
                ],
            ],
        ]);

        $exit = Artisan::call('atlas:loop:perpetual-sweep', [
            '--write' => true,
            '--manifest-path' => $this->manifestPath,
            '--json' => true,
            '--strict' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame('verdict_produced', $payload['status']);
        $this->assertSame(2, $payload['finding_count']);
        $this->assertSame(1, $payload['high_count']);
        $this->assertSame(1, $payload['low_count']);
        $this->assertSame(2, $payload['enqueued_count']);
        $this->assertFalse($payload['verdict']['provider_calls']);
        $this->assertFalse($payload['verdict']['direct_code_mutation']);
        $this->assertFalse($payload['verdict']['never_merge_changed']);
        $this->assertTrue($payload['verdict']['low_auto_fix_is_backlog_intent']);

        $manifest = json_decode((string) File::get($this->manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(2, $manifest['items']);
        $sources = array_column($manifest['items'], 'source');
        $this->assertContains('perpetual_sweep:high_review', $sources);
        $this->assertContains('perpetual_sweep:low_auto_fix', $sources);

        $high = collect($manifest['items'])->firstWhere('source', 'perpetual_sweep:high_review');
        $low = collect($manifest['items'])->firstWhere('source', 'perpetual_sweep:low_auto_fix');
        $this->assertSame('high', $high['severity']);
        $this->assertTrue($high['operator_review_required']);
        $this->assertSame('blocked_until_operator_review', $high['autofix_mode']);
        $this->assertSame('low', $low['severity']);
        $this->assertFalse($low['operator_review_required']);
        $this->assertSame('governed_loop_backlog_intent', $low['autofix_mode']);

        Artisan::call('atlas:loop:perpetual-sweep', [
            '--write' => true,
            '--manifest-path' => $this->manifestPath,
            '--json' => true,
        ]);
        $again = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $again['enqueued_count']);
        $this->assertSame(2, $again['duplicate_count']);
    }

    public function test_schedule_lists_the_fortnightly_sweep_command(): void
    {
        config([
            'atlas.loop.perpetual_sweep.enabled' => true,
            'atlas.loop.perpetual_sweep.schedule_enabled' => true,
            'atlas.loop.perpetual_sweep.schedule_day' => 6,
            'atlas.loop.perpetual_sweep.schedule_time' => '06:05',
            'atlas.loop.perpetual_sweep.schedule_week_parity' => 0,
        ]);

        $exit = Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('atlas:loop:perpetual-sweep --write --json', $output);
    }
}
