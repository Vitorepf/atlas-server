<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\SelfImprovement\AtlasSelfImprovementInput;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementRuntime;
use Tests\TestCase;

class AtlasSelfImprovementInputTest extends TestCase
{
    public function test_normalizes_self_improvement_runtime_options(): void
    {
        $input = new AtlasSelfImprovementInput;

        $this->assertSame(AtlasSelfImprovementRuntime::DEFAULT_REVIEW_WINDOW_HOURS, $input->reviewWindowHours(null));
        $this->assertSame(AtlasSelfImprovementInput::DEFAULT_FINDINGS_LIMIT, $input->findingsLimit('bad'));
        $this->assertSame(1, $input->reviewWindowHours(-10));
        $this->assertSame(1, $input->findingsLimit(0));
        $this->assertSame(42, $input->reviewWindowHours('42'));
        $this->assertSame(AtlasSelfImprovementRuntime::MAX_AUTONOMOUS_REVIEW_WINDOW_HOURS, $input->reviewWindowHours(999));
        $this->assertSame(AtlasSelfImprovementRuntime::MAX_FINDINGS_PER_RUN, $input->findingsLimit(999));
        $this->assertSame([
            'hours' => AtlasSelfImprovementRuntime::MAX_AUTONOMOUS_REVIEW_WINDOW_HOURS,
            'limit' => AtlasSelfImprovementRuntime::MAX_FINDINGS_PER_RUN,
        ], $input->runtimeOptions([
            'hours' => 999,
            'limit' => 999,
        ]));
    }
}
