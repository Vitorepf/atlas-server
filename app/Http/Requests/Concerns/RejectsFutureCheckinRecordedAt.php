<?php

namespace App\Http\Requests\Concerns;

use Carbon\CarbonImmutable;
use Throwable;

trait RejectsFutureCheckinRecordedAt
{
    protected function rejectFutureCheckinRecordedAt($validator, string $field, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        try {
            $recordedAt = CarbonImmutable::parse((string) $value);
        } catch (Throwable) {
            return;
        }

        if ($recordedAt->gt(CarbonImmutable::now('UTC')->addMinutes(5))) {
            $validator->errors()->add($field, 'The recorded_at value may not be more than 5 minutes in the future.');
        }
    }
}
