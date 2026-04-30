<?php

namespace Tests\Unit;

use App\Support\BehaviorLifecycle;
use PHPUnit\Framework\TestCase;

class BehaviorLifecycleTest extends TestCase
{
    public function test_knows_promptable_statuses(): void
    {
        $this->assertTrue(BehaviorLifecycle::isPromptable('active'));
        $this->assertTrue(BehaviorLifecycle::isPromptable('experiment'));
        $this->assertFalse(BehaviorLifecycle::isPromptable('baseline'));
        $this->assertFalse(BehaviorLifecycle::isPromptable('dormant'));
        $this->assertFalse(BehaviorLifecycle::isPromptable('manual_only'));
    }
}
