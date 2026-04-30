<?php

namespace Tests\Unit;

use App\Http\Requests\Concerns\RejectsFutureCheckinRecordedAt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class CheckinRecordedAtValidationTest extends TestCase
{
    public function test_rejects_checkin_recorded_far_in_the_future(): void
    {
        $errors = $this->validateRecordedAt(CarbonImmutable::now('UTC')->addMinutes(6)->toIso8601String());

        $this->assertNotEmpty($errors);
    }

    public function test_allows_small_client_clock_skew(): void
    {
        $errors = $this->validateRecordedAt(CarbonImmutable::now('UTC')->addMinutes(4)->toIso8601String());

        $this->assertSame([], $errors);
    }

    /**
     * @return array<int, string>
     */
    private function validateRecordedAt(string $value): array
    {
        $harness = new class {
            use RejectsFutureCheckinRecordedAt;

            public function apply($validator, string $value): void
            {
                $this->rejectFutureCheckinRecordedAt($validator, 'recorded_at', $value);
            }
        };

        $validator = Validator::make(
            ['recorded_at' => $value],
            ['recorded_at' => ['required', 'date']],
        );
        $validator->after(fn ($validator) => $harness->apply($validator, $value));
        $validator->fails();

        return $validator->errors()->all();
    }
}
