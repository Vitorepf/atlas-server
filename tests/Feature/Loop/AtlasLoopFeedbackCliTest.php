<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Feedback\AtlasLoopFeedbackReceiptLedger;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the operator CLI atlas:loop:feedback: inspect emits the miner FACTs (exit 0); apply refuses
 * (non-zero) when the flag is OFF and proceeds (recording a receipt) with --force; history prints the
 * receipts in chronological order. A shared in-memory ledger is bound so apply→history see one store.
 */
final class AtlasLoopFeedbackCliTest extends TestCase
{
    private AtlasLoopFeedbackReceiptLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        // One shared ledger across the (multiple) command invocations in a test.
        $this->ledger = new AtlasLoopFeedbackReceiptLedger;
        $this->app->instance(AtlasLoopFeedbackReceiptLedger::class, $this->ledger);
    }

    public function test_inspect_emits_miner_facts_and_exits_zero(): void
    {
        $code = Artisan::call('atlas:loop:feedback', ['action' => 'inspect', '--json' => true]);
        $this->assertSame(0, $code);

        $out = json_decode(Artisan::output(), true);
        $this->assertSame('inspect', $out['action']);
        foreach (['top_reasons', 'give_back_rate_by_class', 'top_reason_by_class', 'worker_concentration'] as $key) {
            $this->assertArrayHasKey($key, $out['facts'], "miner output carries {$key}");
        }
    }

    public function test_apply_refuses_when_flag_off_without_force(): void
    {
        config(['atlas.loop.feedback.replenisher_enabled' => false]);

        $code = Artisan::call('atlas:loop:feedback', ['action' => 'apply', '--json' => true]);

        $this->assertNotSame(0, $code, 'non-zero exit when flag OFF and no --force');
        $out = json_decode(Artisan::output(), true);
        $this->assertSame('refused', $out['status']);
        $this->assertSame('flag_off', $out['reason']);
        $this->assertSame(0, $this->ledger->count(), 'nothing recorded on refusal');
    }

    public function test_apply_with_force_proceeds_and_records_receipt(): void
    {
        config(['atlas.loop.feedback.replenisher_enabled' => false]);

        $code = Artisan::call('atlas:loop:feedback', ['action' => 'apply', '--force' => true, '--json' => true]);

        $this->assertSame(0, $code);
        $out = json_decode(Artisan::output(), true);
        $this->assertSame('applied', $out['status']);
        $this->assertTrue($out['forced']);
        $this->assertSame(1, $this->ledger->count(), 'a receipt appears in the ledger');
    }

    public function test_apply_with_flag_on_adds_give_back_facts_key(): void
    {
        config(['atlas.loop.feedback.replenisher_enabled' => true]);

        $code = Artisan::call('atlas:loop:feedback', ['action' => 'apply', '--json' => true]);

        $this->assertSame(0, $code);
        $out = json_decode(Artisan::output(), true);
        $this->assertSame(['give_back_facts'], $out['context_keys_added']);
        $this->assertSame(1, $this->ledger->count());
    }

    public function test_history_prints_receipts_in_chronological_order(): void
    {
        $this->ledger->record(['input_records' => [['x' => 1]], 'mined_facts' => [], 'context_keys_added' => ['give_back_facts']]);
        $this->ledger->record(['input_records' => [['x' => 2]], 'mined_facts' => [], 'context_keys_added' => []]);
        $this->ledger->record(['input_records' => [['x' => 3]], 'mined_facts' => [], 'context_keys_added' => []]);

        $code = Artisan::call('atlas:loop:feedback', ['action' => 'history', '--limit' => 5, '--json' => true]);

        $this->assertSame(0, $code);
        $out = json_decode(Artisan::output(), true);
        $this->assertSame(3, $out['count']);
        $this->assertSame([1, 2, 3], array_column($out['receipts'], 'seq'), 'chronological order');
    }

    public function test_unknown_action_exits_invalid(): void
    {
        $code = Artisan::call('atlas:loop:feedback', ['action' => 'bogus', '--json' => true]);
        $this->assertSame(2, $code, 'unknown action ⇒ INVALID exit');
    }
}
