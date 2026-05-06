<?php

namespace Tests\Unit\Ai\Telemetry;

use App\Services\Ai\Telemetry\AiTelemetryWindowInput;
use Tests\TestCase;

class AiTelemetryWindowInputTest extends TestCase
{
    public function test_hours_normalizes_telemetry_window_with_canonical_limits(): void
    {
        $input = new AiTelemetryWindowInput;

        $this->assertSame(AiTelemetryWindowInput::DEFAULT_WINDOW_HOURS, $input->hours(null));
        $this->assertSame(AiTelemetryWindowInput::DEFAULT_COST_RATE_WINDOW_HOURS, $input->hours(null, AiTelemetryWindowInput::DEFAULT_COST_RATE_WINDOW_HOURS));
        $this->assertSame(1, $input->hours(-10));
        $this->assertSame(AiTelemetryWindowInput::MAX_WINDOW_HOURS, $input->hours(9999));
        $this->assertSame(48, $input->hours('48'));
    }

    public function test_limit_normalizes_telemetry_list_limits_with_canonical_caps(): void
    {
        $input = new AiTelemetryWindowInput;

        $this->assertSame(
            AiTelemetryWindowInput::DEFAULT_SUMMARY_LIMIT,
            $input->limit(null, AiTelemetryWindowInput::DEFAULT_SUMMARY_LIMIT, AiTelemetryWindowInput::MAX_SUMMARY_LIMIT),
        );
        $this->assertSame(1, $input->limit(-10, AiTelemetryWindowInput::DEFAULT_SUMMARY_LIMIT, AiTelemetryWindowInput::MAX_SUMMARY_LIMIT));
        $this->assertSame(
            AiTelemetryWindowInput::MAX_SUMMARY_LIMIT,
            $input->limit(9999, AiTelemetryWindowInput::DEFAULT_SUMMARY_LIMIT, AiTelemetryWindowInput::MAX_SUMMARY_LIMIT),
        );
        $this->assertSame(42, $input->limit('42', AiTelemetryWindowInput::DEFAULT_OUTCOME_LIMIT, AiTelemetryWindowInput::MAX_OUTCOME_LIMIT));
    }
}
