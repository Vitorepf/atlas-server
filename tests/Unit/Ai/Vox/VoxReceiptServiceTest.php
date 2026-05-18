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
        $this->assertSame('vox_kernel_v2', $receipt['issuer']);
        $this->assertTrue($receipt['executable_now']);
        $this->assertStringStartsWith('rcpt_vox_', $receipt['receipt_id']);
        $this->assertSame($intent['intent_id'], $receipt['intent_id']);
        $this->assertSame($intent['session_id'], $receipt['session_id']);
    }

    public function test_advisory_receipt_for_r2_intent_compile_requires_confirmation_but_blocks_execution(): void
    {
        $svc = new VoxReceiptService();
        $intent = [
            'intent_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'session_id' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
            'mode' => VoxSchema::MODE_INTENT_COMPILE,
            'risk_class' => VoxSchema::RISK_R2,
            'risk_reasoning' => 'edição local reversível',
        ];
        $receipt = $svc->issueR0($intent);

        $this->assertSame(VoxReceiptService::RECEIPT_SCHEMA_ADVISORY, $receipt['schema']);
        $this->assertSame('vox_v2_intent_compile_advisory', $receipt['receipt_type']);
        $this->assertSame('R2', $receipt['risk_class']);
        $this->assertSame('return_compiled_prompt_to_desktop', $receipt['action_authorized']);
        $this->assertFalse($receipt['provider_call_allowed']);
        $this->assertFalse($receipt['tool_call_allowed']);
        $this->assertFalse($receipt['raw_audio_allowed']);
        $this->assertFalse($receipt['external_side_effect_allowed']);
        $this->assertFalse($receipt['executable_now']);
        $this->assertTrue($receipt['confirmation_required']);
        $this->assertSame('V3_governed_executor', $receipt['future_governance']['wave']);
        $this->assertTrue($receipt['future_governance']['will_require_human_confirmation']);
        $this->assertFalse($receipt['future_governance']['will_require_double_confirmation']);
    }

    public function test_advisory_receipt_for_r4_demands_double_confirmation(): void
    {
        $svc = new VoxReceiptService();
        $receipt = $svc->issueR0([
            'intent_id' => 'a', 'session_id' => 's', 'mode' => VoxSchema::MODE_INTENT_COMPILE,
            'risk_class' => VoxSchema::RISK_R4, 'risk_reasoning' => 'destrutivo',
        ]);

        $this->assertSame('R4', $receipt['risk_class']);
        $this->assertFalse($receipt['executable_now']);
        $this->assertTrue($receipt['confirmation_required']);
        $this->assertTrue($receipt['future_governance']['will_require_double_confirmation']);
    }

    public function test_advisory_receipt_for_r1_does_not_force_confirmation(): void
    {
        $svc = new VoxReceiptService();
        $receipt = $svc->issueR0([
            'intent_id' => 'a', 'session_id' => 's', 'mode' => VoxSchema::MODE_INTENT_COMPILE,
            'risk_class' => VoxSchema::RISK_R1, 'risk_reasoning' => 'leitura',
        ]);

        $this->assertSame('R1', $receipt['risk_class']);
        $this->assertFalse($receipt['executable_now']);
        $this->assertFalse($receipt['confirmation_required']);
        $this->assertFalse($receipt['future_governance']['will_require_human_confirmation']);
    }
}
