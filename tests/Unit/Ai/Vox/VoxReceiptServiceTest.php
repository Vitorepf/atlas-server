<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Vox;

use App\Services\Ai\Vox\VoxReceiptService;
use App\Services\Ai\Vox\VoxSchema;
use PHPUnit\Framework\TestCase;

final class VoxReceiptServiceTest extends TestCase
{
    public function test_r0_receipt_forbids_provider_tool_audio_and_side_effects(): void
    {
        $svc = new VoxReceiptService();
        $intent = [
            'intent_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'session_id' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
            'mode' => VoxSchema::MODE_DICTATION,
        ];
        $receipt = $svc->issueR0($intent);

        $this->assertSame(VoxSchema::RECEIPT_R0, $receipt['schema']);
        $this->assertSame('vox_r0_no_op_pass_through', $receipt['receipt_type']);
        $this->assertSame('R0', $receipt['risk_class']);
        $this->assertSame('return_text_to_desktop', $receipt['action_authorized']);
        $this->assertFalse($receipt['provider_call_allowed']);
        $this->assertFalse($receipt['tool_call_allowed']);
        $this->assertFalse($receipt['raw_audio_allowed']);
        $this->assertFalse($receipt['external_side_effect_allowed']);
        $this->assertFalse($receipt['confirmation_required']);
        $this->assertSame('vox_kernel_v0', $receipt['issuer']);
        $this->assertStringStartsWith('rcpt_vox_', $receipt['receipt_id']);
        $this->assertSame($intent['intent_id'], $receipt['intent_id']);
        $this->assertSame($intent['session_id'], $receipt['session_id']);
    }
}
