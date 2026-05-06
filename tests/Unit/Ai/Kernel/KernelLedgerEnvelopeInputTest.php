<?php

namespace Tests\Unit\Ai\Kernel;

use App\Services\Ai\Kernel\Evidence\KernelLedgerEnvelopeInput;
use Tests\TestCase;

class KernelLedgerEnvelopeInputTest extends TestCase
{
    public function test_event_limit_normalizes_with_canonical_ledger_limits(): void
    {
        $input = new KernelLedgerEnvelopeInput;

        $this->assertSame(KernelLedgerEnvelopeInput::DEFAULT_EVENT_LIMIT, $input->eventLimit(null));
        $this->assertSame(KernelLedgerEnvelopeInput::DEFAULT_EVENT_LIMIT, $input->eventLimit('bad'));
        $this->assertSame(1, $input->eventLimit(-50));
        $this->assertSame(KernelLedgerEnvelopeInput::MAX_EVENT_LIMIT, $input->eventLimit(9999));
        $this->assertSame(25, $input->eventLimit('25'));
    }
}
