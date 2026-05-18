<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Vox;

use App\Services\Ai\Vox\VoxActionOutcomeService;
use App\Services\Ai\Vox\VoxSchema;
use PHPUnit\Framework\TestCase;

final class VoxActionOutcomeServiceTest extends TestCase
{
    public function test_completed_outcome_is_audio_free_and_clipboard_scoped(): void
    {
        $svc = new VoxActionOutcomeService();
        $intent = [
            'session_id' => 's',
            'intent_id' => 'i',
        ];
        $receipt = ['receipt_id' => 'rcpt_vox_x'];
        $outcome = $svc->completed($intent, $receipt, VoxSchema::EXECUTOR_CLIPBOARD_WRITE, 'olá mundo');

        $this->assertSame(VoxSchema::ACTION_OUTCOME, $outcome['schema']);
        $this->assertSame('clipboard_write', $outcome['executor']);
        $this->assertSame('completed', $outcome['status']);
        $this->assertNull($outcome['error']);
        $this->assertSame([], $outcome['regret_signals']);
        $this->assertSame([], $outcome['executor_violations']);
        $this->assertCount(1, $outcome['artifacts']);
        $this->assertSame('clipboard_payload', $outcome['artifacts'][0]['kind']);
        $this->assertSame(strlen('olá mundo'), $outcome['artifacts'][0]['size_bytes']);
        foreach ($outcome['artifacts'] as $a) {
            $this->assertNotContains($a['kind'], ['raw_audio', 'pcm', 'audio_bytes', 'wav']);
        }
        $this->assertSame('atlas_desktop', $outcome['metadata']['executed_by']);
    }

    public function test_cancelled_outcome_is_aborted_with_operator_reason(): void
    {
        $svc = new VoxActionOutcomeService();
        $outcome = $svc->cancelled([
            'session_id' => 's',
            'intent_id' => 'i',
        ], ['receipt_id' => 'rcpt_vox_x']);

        $this->assertSame('aborted', $outcome['status']);
        $this->assertSame('no_op_dictation', $outcome['executor']);
        $this->assertSame('operator_cancelled', $outcome['error']['kind']);
        $this->assertSame([], $outcome['artifacts']);
        $this->assertFalse($outcome['follow_up_required']);
    }
}
